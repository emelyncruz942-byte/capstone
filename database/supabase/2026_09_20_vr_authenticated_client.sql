-- MathVerse forward migration: bind Unity VR joins and score writes to auth.uid().
-- Run this WHOLE file in Supabase SQL Editor as postgres after the two VR v1 files.
-- The public room-code and question reads stay available for room discovery, but
-- participant registration and score RPCs now require a signed-in Supabase user.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.profiles') IS NULL
       OR to_regclass('public.quiz_sessions') IS NULL
       OR to_regclass('public.quiz_session_students') IS NULL
       OR to_regclass('public.quiz_participants') IS NULL
       OR to_regprocedure('public.get_vr_quiz_score_slot(uuid,uuid)') IS NULL
       OR to_regprocedure('public.submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)') IS NULL
       OR to_regprocedure('auth.uid()') IS NULL THEN
        RAISE EXCEPTION 'Required VR tables, auth.uid(), or v1 score RPCs are missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_20_vr_legacy_access.sql'
    ) OR NOT EXISTS (
        SELECT 1 FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_20_vr_score_submission.sql'
    ) THEN
        RAISE EXCEPTION 'Run the VR legacy-access and score-submission migrations before this migration.';
    END IF;
END
$preflight$;

CREATE OR REPLACE FUNCTION public.register_my_vr_quiz_participant(p_session_id uuid)
RETURNS TABLE (joined boolean, status text, message text)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    caller_id uuid := auth.uid();
    assignment public.quiz_sessions%rowtype;
    eligibility public.quiz_session_students%rowtype;
    inserted_rows integer := 0;
    checked_at timestamptz := clock_timestamp();
BEGIN
    joined := false;

    IF caller_id IS NULL THEN
        status := 'not_authenticated';
        message := 'Sign in again before joining a VR quiz.';
        RETURN NEXT; RETURN;
    END IF;

    IF p_session_id IS NULL THEN
        status := 'invalid_session';
        message := 'The VR quiz session is invalid.';
        RETURN NEXT; RETURN;
    END IF;

    SELECT s.* INTO assignment
    FROM public.quiz_sessions s
    WHERE s.id = p_session_id;

    SELECT e.* INTO eligibility
    FROM public.quiz_session_students e
    WHERE e.session_id = p_session_id AND e.student_id = caller_id;

    IF NOT EXISTS (
        SELECT 1 FROM public.profiles p
        WHERE p.id = caller_id AND p.role = 'student'
    ) THEN
        status := 'invalid_student';
        message := 'A valid student account is required.';
    ELSIF EXISTS (
        SELECT 1 FROM public.profiles p
        WHERE p.id = caller_id
          AND (p.suspended_at IS NOT NULL OR p.deactivated_at IS NOT NULL)
    ) THEN
        status := 'account_unavailable';
        message := 'This account cannot join quiz assignments.';
    ELSIF assignment.id IS NULL
          OR assignment.status NOT IN ('waiting', 'active')
          OR assignment.is_active IS NOT TRUE THEN
        status := 'not_available';
        message := 'This quiz is no longer available.';
    ELSIF assignment.due_at IS NOT NULL AND assignment.due_at <= checked_at THEN
        status := 'expired';
        message := 'This quiz is past its due time.';
    ELSIF eligibility.session_id IS NULL OR eligibility.eligibility_status <> 'eligible' THEN
        status := 'not_eligible';
        message := 'This quiz is not assigned to your signed-in student account.';
    ELSIF eligibility.retake_due_at IS NOT NULL AND eligibility.retake_due_at <= checked_at THEN
        status := 'expired';
        message := 'Your retake permission has expired.';
    ELSE
        INSERT INTO public.quiz_participants (session_id, student_id)
        VALUES (p_session_id, caller_id)
        ON CONFLICT DO NOTHING;
        GET DIAGNOSTICS inserted_rows = ROW_COUNT;

        joined := true;
        status := CASE WHEN inserted_rows = 1 THEN 'joined' ELSE 'already_joined' END;
        message := CASE WHEN inserted_rows = 1
            THEN 'The signed-in student joined the VR quiz.'
            ELSE 'The signed-in student was already registered for this VR quiz.'
        END;
    END IF;

    RETURN NEXT;
END;
$$;

CREATE OR REPLACE FUNCTION public.get_my_vr_quiz_score_slot(p_session_id uuid)
RETURNS TABLE (
    can_submit boolean, status text, attempt_number integer,
    retake_granted_at text, message text
)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    caller_id uuid := auth.uid();
BEGIN
    IF caller_id IS NULL THEN
        can_submit := false;
        status := 'not_authenticated';
        attempt_number := 0;
        retake_granted_at := '';
        message := 'Sign in again before starting a VR quiz.';
        RETURN NEXT; RETURN;
    END IF;

    RETURN QUERY
    SELECT slot.can_submit, slot.status, slot.attempt_number,
           slot.retake_granted_at, slot.message
    FROM public.get_vr_quiz_score_slot(p_session_id, caller_id) AS slot;
END;
$$;

CREATE OR REPLACE FUNCTION public.submit_my_vr_quiz_score(
    p_session_id uuid,
    p_submission_id uuid,
    p_attempt_number integer,
    p_retake_granted_at text,
    p_correct_answers integer,
    p_total_questions integer
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
    caller_id uuid := auth.uid();
BEGIN
    IF caller_id IS NULL THEN
        saved := false;
        status := 'not_authenticated';
        message := 'Sign in again before submitting a VR quiz score.';
        RETURN NEXT; RETURN;
    END IF;

    RETURN QUERY
    SELECT receipt.saved, receipt.status, receipt.result_id,
           receipt.attempt_number, receipt.correct_answers,
           receipt.total_questions, receipt.message
    FROM public.submit_vr_quiz_score(
        p_session_id,
        caller_id,
        p_submission_id,
        p_attempt_number,
        p_retake_granted_at,
        p_correct_answers,
        p_total_questions
    ) AS receipt;
END;
$$;

-- Retire the spoofable anonymous write paths used by the legacy Unity build.
-- The legacy read grants remain so a room code can still be discovered before
-- the authenticated registration RPC runs.
REVOKE INSERT ON public.quiz_participants FROM PUBLIC, anon, authenticated;
REVOKE INSERT (session_id, student_id) ON public.quiz_participants
    FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.get_vr_quiz_score_slot(uuid, uuid)
    FROM PUBLIC, anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.submit_vr_quiz_score(uuid, uuid, uuid, integer, text, integer, integer)
    FROM PUBLIC, anon, authenticated;

REVOKE ALL ON FUNCTION public.register_my_vr_quiz_participant(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.get_my_vr_quiz_score_slot(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.submit_my_vr_quiz_score(uuid, uuid, integer, text, integer, integer)
    FROM PUBLIC, anon, authenticated, service_role;

GRANT EXECUTE ON FUNCTION public.register_my_vr_quiz_participant(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION public.get_my_vr_quiz_score_slot(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION public.submit_my_vr_quiz_score(uuid, uuid, integer, text, integer, integer)
    TO authenticated;

COMMENT ON FUNCTION public.register_my_vr_quiz_participant(uuid) IS
    'Registers auth.uid() for an active assigned VR quiz; callers cannot supply a student id.';
COMMENT ON FUNCTION public.get_my_vr_quiz_score_slot(uuid) IS
    'Returns VR attempt permission for auth.uid(); callers cannot inspect another student slot.';
COMMENT ON FUNCTION public.submit_my_vr_quiz_score(uuid, uuid, integer, text, integer, integer) IS
    'Submits a VR result for auth.uid() through the guarded immutable v1 score function.';

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_09_20_vr_authenticated_client.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_function_privilege('authenticated',
        'public.register_my_vr_quiz_participant(uuid)', 'EXECUTE') AS authenticated_join_ready,
    has_function_privilege('authenticated',
        'public.get_my_vr_quiz_score_slot(uuid)', 'EXECUTE') AS authenticated_slot_ready,
    has_function_privilege('authenticated',
        'public.submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)',
        'EXECUTE') AS authenticated_score_ready,
    NOT has_function_privilege('anon',
        'public.get_vr_quiz_score_slot(uuid,uuid)', 'EXECUTE') AS anonymous_slot_closed,
    NOT has_function_privilege('anon',
        'public.submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)',
        'EXECUTE') AS anonymous_score_closed,
    NOT has_column_privilege('anon', 'public.quiz_participants', 'student_id', 'INSERT')
        AS anonymous_participant_spoofing_closed,
    NOT has_function_privilege('authenticated',
        'public.get_vr_quiz_score_slot(uuid,uuid)', 'EXECUTE') AS direct_authenticated_slot_closed,
    NOT has_function_privilege('authenticated',
        'public.submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)',
        'EXECUTE') AS direct_authenticated_score_closed,
    NOT has_column_privilege('authenticated', 'public.quiz_participants', 'student_id', 'INSERT')
        AS direct_authenticated_participant_insert_closed;
