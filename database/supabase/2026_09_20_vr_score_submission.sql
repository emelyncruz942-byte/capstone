-- MathVerse forward migration: one first Unity VR score and one score per active retake.
-- Run this WHOLE file in Supabase SQL Editor as postgres.
-- Requires the August 29/30 attempt migrations and the ordered VR access migration.
-- This migration is idempotent and can be reapplied after global hardening.
-- Uses the existing anon-key + CurrentUserID flow; the supplied UUID is not authenticated.
-- Scores use two RPCs. No direct quiz_results read/update/insert grants are added.
-- Existing scores are retained. A retake creates a new row and becomes the counted score.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.quiz_session_students') IS NULL
       OR to_regclass('public.quiz_results') IS NULL
       OR to_regclass('public.quiz_sessions') IS NULL
       OR to_regclass('public.quiz_participants') IS NULL
       OR to_regclass('public.questions') IS NULL
       OR to_regprocedure('public.enforce_quiz_result_attempt()') IS NULL
       OR to_regprocedure('public.require_explicit_quiz_retake()') IS NULL
       OR to_regprocedure('public.keep_quiz_results_immutable()') IS NULL THEN
        RAISE EXCEPTION 'Required attempt tables/guards are missing. Run the August 29 scheduling and August 30 attempt-integrity migrations first.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_20_vr_legacy_access.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_09_20_vr_legacy_access.sql before this migration.';
    END IF;
END;
$$;

-- Remove only the obsolete August 28 single-row guard/index if still present.
DROP TRIGGER IF EXISTS quiz_results_first_attempt_guard ON public.quiz_results;
DROP INDEX IF EXISTS public.quiz_results_one_attempt_per_assignment_idx;
CREATE UNIQUE INDEX IF NOT EXISTS quiz_results_attempt_number_idx
    ON public.quiz_results (session_id, student_id, attempt_number);
CREATE UNIQUE INDEX IF NOT EXISTS quiz_results_one_counted_attempt_idx
    ON public.quiz_results (session_id, student_id) WHERE is_counted;

-- Restore the existing guards if a manual schema edit removed a trigger.
DROP TRIGGER IF EXISTS quiz_results_00_explicit_retake_guard ON public.quiz_results;
CREATE TRIGGER quiz_results_00_explicit_retake_guard BEFORE INSERT ON public.quiz_results
    FOR EACH ROW EXECUTE FUNCTION public.require_explicit_quiz_retake();
DROP TRIGGER IF EXISTS quiz_results_attempt_guard ON public.quiz_results;
CREATE TRIGGER quiz_results_attempt_guard BEFORE INSERT ON public.quiz_results
    FOR EACH ROW EXECUTE FUNCTION public.enforce_quiz_result_attempt();
DROP TRIGGER IF EXISTS quiz_results_immutable_guard ON public.quiz_results;
CREATE TRIGGER quiz_results_immutable_guard BEFORE UPDATE ON public.quiz_results
    FOR EACH ROW EXECUTE FUNCTION public.keep_quiz_results_immutable();

-- Called once immediately before the Unity quiz begins. This does not consume an attempt.
-- The attempt number and grant timestamp bind the eventual score to this permission.
CREATE OR REPLACE FUNCTION public.get_vr_quiz_score_slot(p_session_id uuid, p_student_id uuid)
RETURNS TABLE (
    can_submit boolean, status text, attempt_number integer,
    retake_granted_at text, message text
)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    assignment public.quiz_sessions%rowtype;
    eligibility public.quiz_session_students%rowtype;
    attempts_used integer;
    latest_submission timestamptz;
    checked_at timestamptz;
BEGIN
    can_submit := false;
    attempt_number := 0;
    retake_granted_at := '';

    IF p_session_id IS NULL OR p_student_id IS NULL
       OR p_student_id = '00000000-0000-0000-0000-000000000000'::uuid
       OR (auth.uid() IS NOT NULL AND auth.uid() IS DISTINCT FROM p_student_id) THEN
        status := 'invalid_student'; message := 'Sign in again before submitting a quiz score.';
        RETURN NEXT; RETURN;
    END IF;

    -- Same lock order as the existing attempt guard and teacher retake RPC.
    SELECT s.* INTO assignment FROM public.quiz_sessions s
        WHERE s.id = p_session_id FOR UPDATE;
    SELECT e.* INTO eligibility FROM public.quiz_session_students e
        WHERE e.session_id = p_session_id AND e.student_id = p_student_id FOR UPDATE;
    checked_at := clock_timestamp();
    SELECT count(*)::integer, max(r.created_at) INTO attempts_used, latest_submission
        FROM public.quiz_results r WHERE r.session_id = p_session_id AND r.student_id = p_student_id;

    IF NOT EXISTS (SELECT 1 FROM public.profiles p WHERE p.id = p_student_id AND p.role = 'student') THEN
        status := 'invalid_student'; message := 'A valid student account is required.';
    ELSIF EXISTS (SELECT 1 FROM public.profiles p WHERE p.id = p_student_id
                   AND (p.suspended_at IS NOT NULL OR p.deactivated_at IS NOT NULL)) THEN
        status := 'account_unavailable'; message := 'This account cannot submit quiz scores.';
    ELSIF assignment.id IS NULL OR assignment.status NOT IN ('waiting', 'active')
          OR assignment.is_active IS NOT TRUE THEN
        status := 'not_available'; message := 'This quiz is no longer available.';
    ELSIF assignment.due_at IS NOT NULL AND assignment.due_at <= checked_at THEN
        status := 'expired'; message := 'This quiz is past its due time.';
    ELSIF NOT EXISTS (SELECT 1 FROM public.quiz_participants p
                      WHERE p.session_id = p_session_id AND p.student_id = p_student_id) THEN
        status := 'not_joined'; message := 'Join the VR room again before submitting a score.';
    ELSIF eligibility.session_id IS NULL OR eligibility.eligibility_status <> 'eligible' THEN
        status := 'not_eligible'; message := 'You are not eligible for this quiz assignment.';
    ELSIF eligibility.retake_due_at IS NOT NULL AND eligibility.retake_due_at <= checked_at THEN
        status := 'expired'; message := 'Your retake permission has expired.';
    ELSIF attempts_used >= eligibility.allowed_attempts THEN
        status := 'attempts_exhausted'; message := 'Your allowed attempt has already been recorded.';
    ELSIF (attempts_used > 0 OR assignment.retake_mode)
          AND (assignment.retake_mode IS NOT TRUE
               OR eligibility.last_retake_granted_at IS NULL
               OR eligibility.last_retake_granted_by IS DISTINCT FROM assignment.teacher_id) THEN
        status := 'retake_required'; message := 'A teacher must grant an active retake before another score can be saved.';
    ELSIF attempts_used > 0 AND latest_submission >= eligibility.last_retake_granted_at THEN
        -- Do not let stacked allowed_attempts values provide several scores for one grant.
        status := 'attempts_exhausted'; message := 'The score for this retake has already been recorded.';
    ELSE
        can_submit := true; status := 'ready'; attempt_number := attempts_used + 1;
        retake_granted_at := coalesce(eligibility.last_retake_granted_at::text, '');
        message := 'One score can be submitted for this attempt.';
    END IF;
    RETURN NEXT;
END;
$$;

CREATE OR REPLACE FUNCTION public.submit_vr_quiz_score(
    p_session_id uuid, p_student_id uuid, p_submission_id uuid,
    p_attempt_number integer, p_retake_granted_at text,
    p_correct_answers integer, p_total_questions integer
)
RETURNS TABLE (
    saved boolean, status text, result_id uuid, attempt_number integer,
    correct_answers integer, total_questions integer, message text
)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    receipt public.quiz_results%rowtype;
    slot record;
    question_count integer;
BEGIN
    saved := false;
    IF p_submission_id IS NULL OR p_submission_id = '00000000-0000-0000-0000-000000000000'::uuid
       OR p_session_id IS NULL OR p_student_id IS NULL OR p_attempt_number IS NULL OR p_attempt_number < 1
       OR (auth.uid() IS NOT NULL AND auth.uid() IS DISTINCT FROM p_student_id) THEN
        status := 'invalid_request'; message := 'The score submission is invalid.';
        RETURN NEXT; RETURN;
    END IF;

    -- Serialize competing submissions and teacher retakes for this assignment.
    PERFORM 1 FROM public.quiz_sessions s WHERE s.id = p_session_id FOR UPDATE;
    SELECT r.* INTO receipt FROM public.quiz_results r WHERE r.id = p_submission_id;
    IF FOUND THEN
        IF receipt.session_id IS DISTINCT FROM p_session_id OR receipt.student_id IS DISTINCT FROM p_student_id THEN
            status := 'invalid_request'; message := 'This submission ID belongs to another attempt.';
            RETURN NEXT; RETURN;
        END IF;
        saved := true; status := 'already_saved';
        message := 'This submission was already saved. The original score is unchanged.';
    ELSE
        -- A second client with a different UUID still cannot replace this numbered attempt.
        SELECT r.* INTO receipt FROM public.quiz_results r
            WHERE r.session_id = p_session_id AND r.student_id = p_student_id
              AND r.attempt_number = p_attempt_number;
        IF FOUND THEN
            status := 'already_recorded'; message := 'This attempt already has a saved score. It was not changed.';
        ELSE
            SELECT s.* INTO slot FROM public.get_vr_quiz_score_slot(p_session_id, p_student_id) s;
            IF slot.can_submit IS NOT TRUE THEN
                status := slot.status; message := slot.message;
                RETURN NEXT; RETURN;
            END IF;
            IF slot.attempt_number IS DISTINCT FROM p_attempt_number
               OR slot.retake_granted_at IS DISTINCT FROM coalesce(p_retake_granted_at, '') THEN
                status := 'attempt_changed'; message := 'Your retake permission changed. Rejoin the quiz to use the current permission.';
                RETURN NEXT; RETURN;
            END IF;
            SELECT count(*)::integer INTO question_count FROM public.questions q
                WHERE q.session_id = p_session_id AND q.deleted_at IS NULL;
            IF p_correct_answers IS NULL OR p_total_questions IS NULL OR p_total_questions < 1
               OR p_correct_answers < 0 OR p_correct_answers > p_total_questions
               OR p_total_questions <> question_count THEN
                status := 'invalid_score'; message := 'The score or question count does not match this quiz.';
                RETURN NEXT; RETURN;
            END IF;

            -- The existing guard enforces active/start/due/eligibility rules, assigns the
            -- attempt number, and changes the earlier row's is_counted flag inside its trigger.
            INSERT INTO public.quiz_results (id, session_id, student_id, correct_answers, total_questions)
                VALUES (p_submission_id, p_session_id, p_student_id, p_correct_answers, p_total_questions)
                RETURNING * INTO receipt;
            IF receipt.id IS NULL THEN
                status := 'not_saved'; message := 'The database did not accept this attempt.';
                RETURN NEXT; RETURN;
            END IF;
            saved := true; status := 'saved'; message := 'Your score was saved for this attempt.';
        END IF;
    END IF;
    result_id := receipt.id; attempt_number := receipt.attempt_number;
    correct_answers := receipt.correct_answers; total_questions := receipt.total_questions;
    RETURN NEXT;
END;
$$;

REVOKE ALL ON FUNCTION public.get_vr_quiz_score_slot(uuid, uuid) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.submit_vr_quiz_score(uuid, uuid, uuid, integer, text, integer, integer)
    FROM PUBLIC, anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.get_vr_quiz_score_slot(uuid, uuid) TO anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.submit_vr_quiz_score(uuid, uuid, uuid, integer, text, integer, integer)
    TO anon, authenticated, service_role;

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_09_20_vr_score_submission.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_function_privilege('anon', 'public.get_vr_quiz_score_slot(uuid,uuid)', 'EXECUTE') AS slot_rpc_ready,
    has_function_privilege('anon', 'public.submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)', 'EXECUTE') AS score_rpc_ready,
    EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = 'public.quiz_results'::regclass
        AND tgname = 'quiz_results_attempt_guard' AND tgenabled = 'O') AS attempt_guard_ready,
    EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = 'public.quiz_results'::regclass
        AND tgname = 'quiz_results_immutable_guard' AND tgenabled = 'O') AS immutable_guard_ready;
