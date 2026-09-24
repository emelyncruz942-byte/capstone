-- MathVerse forward repair: restore repeated Unity VR retake score writes.
-- Run this WHOLE file in Supabase SQL Editor as postgres after the three
-- September 20 VR migrations. This migration is idempotent and has no rollback:
-- removing a stale one-result uniqueness rule preserves already-saved history.

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
       OR to_regclass('public.questions') IS NULL
       OR to_regclass('public.quiz_results') IS NULL
       OR to_regprocedure('public.enforce_quiz_result_attempt()') IS NULL
       OR to_regprocedure('public.require_explicit_quiz_retake()') IS NULL
       OR to_regprocedure('public.keep_quiz_results_immutable()') IS NULL
       OR to_regprocedure('auth.uid()') IS NULL THEN
        RAISE EXCEPTION 'Required VR tables, attempt guards, or auth.uid() are missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_20_vr_legacy_access.sql'
    ) OR NOT EXISTS (
        SELECT 1 FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_20_vr_score_submission.sql'
    ) OR NOT EXISTS (
        SELECT 1 FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_20_vr_authenticated_client.sql'
    ) THEN
        RAISE EXCEPTION 'Run all three September 20 VR migrations before this repair.';
    END IF;
END
$preflight$;

-- A production database can retain the August 28 one-result rule under a
-- different name (including a UNIQUE constraint with its own backing index).
-- Remove rules by their exact unqualified key columns, not only by a known
-- historical name. Partial (is_counted) and attempt-number indexes are kept.
DO $remove_stale_one_attempt_rules$
DECLARE
    stale record;
BEGIN
    FOR stale IN
        SELECT trigger_row.tgname
        FROM pg_trigger AS trigger_row
        WHERE trigger_row.tgrelid = 'public.quiz_results'::regclass
          AND NOT trigger_row.tgisinternal
          AND trigger_row.tgfoid = to_regprocedure('public.ignore_repeat_quiz_result()')
    LOOP
        EXECUTE format(
            'DROP TRIGGER %I ON public.quiz_results',
            stale.tgname
        );
    END LOOP;

    FOR stale IN
        SELECT constraint_row.conname
        FROM pg_constraint AS constraint_row
        WHERE constraint_row.conrelid = 'public.quiz_results'::regclass
          AND constraint_row.contype = 'u'
          AND (
              SELECT array_agg(attribute_row.attname::text ORDER BY attribute_row.attname)
              FROM unnest(constraint_row.conkey) WITH ORDINALITY
                   AS constrained_column(attnum, position)
              JOIN pg_attribute AS attribute_row
                ON attribute_row.attrelid = constraint_row.conrelid
               AND attribute_row.attnum = constrained_column.attnum
          ) = ARRAY['session_id', 'student_id']::text[]
    LOOP
        EXECUTE format(
            'ALTER TABLE public.quiz_results DROP CONSTRAINT %I',
            stale.conname
        );
    END LOOP;

    FOR stale IN
        SELECT index_namespace.nspname, index_relation.relname
        FROM pg_index AS index_row
        JOIN pg_class AS index_relation
          ON index_relation.oid = index_row.indexrelid
        JOIN pg_namespace AS index_namespace
          ON index_namespace.oid = index_relation.relnamespace
        WHERE index_row.indrelid = 'public.quiz_results'::regclass
          AND index_row.indisunique
          AND NOT index_row.indisprimary
          AND index_row.indpred IS NULL
          AND index_row.indexprs IS NULL
          AND index_row.indnkeyatts = 2
          AND NOT EXISTS (
              SELECT 1 FROM pg_constraint AS owner_constraint
              WHERE owner_constraint.conindid = index_row.indexrelid
          )
          AND (
              SELECT array_agg(attribute_row.attname::text ORDER BY attribute_row.attname)
              FROM unnest(index_row.indkey) WITH ORDINALITY
                   AS indexed_column(attnum, position)
              JOIN pg_attribute AS attribute_row
                ON attribute_row.attrelid = index_row.indrelid
               AND attribute_row.attnum = indexed_column.attnum
              WHERE indexed_column.position <= index_row.indnkeyatts
          ) = ARRAY['session_id', 'student_id']::text[]
    LOOP
        EXECUTE format(
            'DROP INDEX %I.%I',
            stale.nspname,
            stale.relname
        );
    END LOOP;
END
$remove_stale_one_attempt_rules$;

DROP FUNCTION IF EXISTS public.ignore_repeat_quiz_result();

-- Rebuild the two intended uniqueness rules instead of trusting an index that
-- happens to have the expected name but the wrong deployed definition.
DROP INDEX IF EXISTS public.quiz_results_attempt_number_idx;
DROP INDEX IF EXISTS public.quiz_results_one_counted_attempt_idx;
CREATE UNIQUE INDEX quiz_results_attempt_number_idx
    ON public.quiz_results (session_id, student_id, attempt_number);
CREATE UNIQUE INDEX quiz_results_one_counted_attempt_idx
    ON public.quiz_results (session_id, student_id) WHERE is_counted;

DROP TRIGGER IF EXISTS quiz_results_first_attempt_guard ON public.quiz_results;
DROP TRIGGER IF EXISTS quiz_results_00_explicit_retake_guard ON public.quiz_results;
CREATE TRIGGER quiz_results_00_explicit_retake_guard
    BEFORE INSERT ON public.quiz_results
    FOR EACH ROW EXECUTE FUNCTION public.require_explicit_quiz_retake();
DROP TRIGGER IF EXISTS quiz_results_attempt_guard ON public.quiz_results;
CREATE TRIGGER quiz_results_attempt_guard
    BEFORE INSERT ON public.quiz_results
    FOR EACH ROW EXECUTE FUNCTION public.enforce_quiz_result_attempt();
DROP TRIGGER IF EXISTS quiz_results_immutable_guard ON public.quiz_results;
CREATE TRIGGER quiz_results_immutable_guard
    BEFORE UPDATE ON public.quiz_results
    FOR EACH ROW EXECUTE FUNCTION public.keep_quiz_results_immutable();

CREATE OR REPLACE FUNCTION public.get_vr_quiz_score_slot(
    p_session_id uuid,
    p_student_id uuid
)
RETURNS TABLE (
    can_submit boolean,
    status text,
    attempt_number integer,
    retake_granted_at text,
    message text
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
        status := 'invalid_student';
        message := 'Sign in again before submitting a quiz score.';
        RETURN NEXT; RETURN;
    END IF;

    SELECT s.* INTO assignment
    FROM public.quiz_sessions AS s
    WHERE s.id = p_session_id
    FOR UPDATE;

    SELECT e.* INTO eligibility
    FROM public.quiz_session_students AS e
    WHERE e.session_id = p_session_id AND e.student_id = p_student_id
    FOR UPDATE;

    checked_at := clock_timestamp();
    SELECT count(*)::integer, max(r.created_at)
    INTO attempts_used, latest_submission
    FROM public.quiz_results AS r
    WHERE r.session_id = p_session_id AND r.student_id = p_student_id;

    IF NOT EXISTS (
        SELECT 1 FROM public.profiles AS p
        WHERE p.id = p_student_id AND p.role = 'student'
    ) THEN
        status := 'invalid_student';
        message := 'A valid student account is required.';
    ELSIF EXISTS (
        SELECT 1 FROM public.profiles AS p
        WHERE p.id = p_student_id
          AND (p.suspended_at IS NOT NULL OR p.deactivated_at IS NOT NULL)
    ) THEN
        status := 'account_unavailable';
        message := 'This account cannot submit quiz scores.';
    ELSIF assignment.id IS NULL
          OR assignment.status NOT IN ('waiting', 'active')
          OR assignment.is_active IS NOT TRUE THEN
        status := 'not_available';
        message := 'This quiz is no longer available.';
    ELSIF assignment.due_at IS NOT NULL AND assignment.due_at <= checked_at THEN
        status := 'expired';
        message := 'This quiz is past its due time.';
    ELSIF NOT EXISTS (
        SELECT 1 FROM public.quiz_participants AS p
        WHERE p.session_id = p_session_id AND p.student_id = p_student_id
    ) THEN
        status := 'not_joined';
        message := 'Join the VR room again before submitting a score.';
    ELSIF eligibility.session_id IS NULL OR eligibility.eligibility_status <> 'eligible' THEN
        status := 'not_eligible';
        message := 'You are not eligible for this quiz assignment.';
    ELSIF eligibility.retake_due_at IS NOT NULL
          AND eligibility.retake_due_at <= checked_at THEN
        status := 'expired';
        message := 'Your retake permission has expired.';
    ELSIF attempts_used >= eligibility.allowed_attempts THEN
        status := 'attempts_exhausted';
        message := 'Your allowed attempt has already been recorded.';
    ELSIF (attempts_used > 0 OR assignment.retake_mode)
          AND (assignment.retake_mode IS NOT TRUE
               OR eligibility.last_retake_granted_at IS NULL
               OR eligibility.last_retake_granted_by IS DISTINCT FROM assignment.teacher_id) THEN
        status := 'retake_required';
        message := 'A teacher must grant an active retake before another score can be saved.';
    ELSIF attempts_used > 0
          AND latest_submission >= eligibility.last_retake_granted_at THEN
        status := 'attempts_exhausted';
        message := 'The score for this retake has already been recorded.';
    ELSE
        can_submit := true;
        status := 'ready';
        attempt_number := attempts_used + 1;
        retake_granted_at := coalesce(eligibility.last_retake_granted_at::text, '');
        message := 'One score can be submitted for this attempt.';
    END IF;

    RETURN NEXT;
END;
$$;

CREATE OR REPLACE FUNCTION public.submit_vr_quiz_score(
    p_session_id uuid,
    p_student_id uuid,
    p_submission_id uuid,
    p_attempt_number integer,
    p_retake_granted_at text,
    p_correct_answers integer,
    p_total_questions integer
)
RETURNS TABLE (
    saved boolean,
    status text,
    result_id uuid,
    attempt_number integer,
    correct_answers integer,
    total_questions integer,
    message text
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

    IF p_submission_id IS NULL
       OR p_submission_id = '00000000-0000-0000-0000-000000000000'::uuid
       OR p_session_id IS NULL OR p_student_id IS NULL
       OR p_attempt_number IS NULL OR p_attempt_number < 1
       OR (auth.uid() IS NOT NULL AND auth.uid() IS DISTINCT FROM p_student_id) THEN
        status := 'invalid_request';
        message := 'The score submission is invalid.';
        RETURN NEXT; RETURN;
    END IF;

    PERFORM 1
    FROM public.quiz_sessions AS s
    WHERE s.id = p_session_id
    FOR UPDATE;

    SELECT r.* INTO receipt
    FROM public.quiz_results AS r
    WHERE r.id = p_submission_id;

    IF FOUND THEN
        IF receipt.session_id IS DISTINCT FROM p_session_id
           OR receipt.student_id IS DISTINCT FROM p_student_id THEN
            status := 'invalid_request';
            message := 'This submission ID belongs to another attempt.';
            RETURN NEXT; RETURN;
        END IF;
        saved := true;
        status := 'already_saved';
        message := 'This submission was already saved. The original score is unchanged.';
    ELSE
        SELECT r.* INTO receipt
        FROM public.quiz_results AS r
        WHERE r.session_id = p_session_id
          AND r.student_id = p_student_id
          AND r.attempt_number = p_attempt_number;

        IF FOUND THEN
            status := 'already_recorded';
            message := 'This attempt already has a saved score. It was not changed.';
        ELSE
            SELECT s.* INTO slot
            FROM public.get_vr_quiz_score_slot(p_session_id, p_student_id) AS s;

            IF slot.can_submit IS NOT TRUE THEN
                status := slot.status;
                message := slot.message;
                RETURN NEXT; RETURN;
            END IF;

            IF slot.attempt_number IS DISTINCT FROM p_attempt_number
               OR slot.retake_granted_at IS DISTINCT FROM coalesce(p_retake_granted_at, '') THEN
                status := 'attempt_changed';
                message := 'Your retake permission changed. Rejoin the quiz to use the current permission.';
                RETURN NEXT; RETURN;
            END IF;

            SELECT count(*)::integer INTO question_count
            FROM public.questions AS q
            WHERE q.session_id = p_session_id AND q.deleted_at IS NULL;

            IF p_correct_answers IS NULL OR p_total_questions IS NULL
               OR p_total_questions < 1
               OR p_correct_answers < 0 OR p_correct_answers > p_total_questions
               OR p_total_questions <> question_count THEN
                status := 'invalid_score';
                message := 'The score or question count does not match this quiz.';
                RETURN NEXT; RETURN;
            END IF;

            INSERT INTO public.quiz_results (
                id, session_id, student_id, correct_answers, total_questions
            ) VALUES (
                p_submission_id, p_session_id, p_student_id,
                p_correct_answers, p_total_questions
            )
            RETURNING * INTO receipt;

            IF receipt.id IS NULL THEN
                status := 'not_saved';
                message := 'The database did not accept this attempt.';
                RETURN NEXT; RETURN;
            END IF;

            saved := true;
            status := 'saved';
            message := 'Your score was saved for this attempt.';
        END IF;
    END IF;

    result_id := receipt.id;
    attempt_number := receipt.attempt_number;
    correct_answers := receipt.correct_answers;
    total_questions := receipt.total_questions;
    RETURN NEXT;
END;
$$;

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
    FROM public.quiz_sessions AS s
    WHERE s.id = p_session_id;

    SELECT e.* INTO eligibility
    FROM public.quiz_session_students AS e
    WHERE e.session_id = p_session_id AND e.student_id = caller_id;

    IF NOT EXISTS (
        SELECT 1 FROM public.profiles AS p
        WHERE p.id = caller_id AND p.role = 'student'
    ) THEN
        status := 'invalid_student';
        message := 'A valid student account is required.';
    ELSIF EXISTS (
        SELECT 1 FROM public.profiles AS p
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
    ELSIF eligibility.retake_due_at IS NOT NULL
          AND eligibility.retake_due_at <= checked_at THEN
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
    can_submit boolean,
    status text,
    attempt_number integer,
    retake_granted_at text,
    message text
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
    saved boolean,
    status text,
    result_id uuid,
    attempt_number integer,
    correct_answers integer,
    total_questions integer,
    message text
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

-- Preserve the September 20 production boundary: only the no-student-id
-- wrappers are browser-callable. Service code may call the internal functions.
REVOKE INSERT ON public.quiz_participants FROM PUBLIC, anon, authenticated;
REVOKE INSERT (session_id, student_id) ON public.quiz_participants
    FROM PUBLIC, anon, authenticated;

-- Close drifted direct result writes at both PostgreSQL ACL levels. Revoking
-- table privileges does not remove grants stored on individual columns, so
-- clear every write-capable column ACL as well.
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER
    ON TABLE public.quiz_results FROM PUBLIC, anon, authenticated;

DO $close_direct_result_column_writes$
DECLARE
    result_column record;
BEGIN
    FOR result_column IN
        SELECT attribute_row.attname
        FROM pg_attribute AS attribute_row
        WHERE attribute_row.attrelid = 'public.quiz_results'::regclass
          AND attribute_row.attnum > 0
          AND NOT attribute_row.attisdropped
    LOOP
        EXECUTE format(
            'REVOKE INSERT (%1$I), UPDATE (%1$I), REFERENCES (%1$I) '
            'ON TABLE public.quiz_results FROM PUBLIC, anon, authenticated',
            result_column.attname
        );
    END LOOP;
END
$close_direct_result_column_writes$;

REVOKE ALL ON FUNCTION public.get_vr_quiz_score_slot(uuid, uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.submit_vr_quiz_score(
    uuid, uuid, uuid, integer, text, integer, integer
) FROM PUBLIC, anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.get_vr_quiz_score_slot(uuid, uuid)
    TO service_role;
GRANT EXECUTE ON FUNCTION public.submit_vr_quiz_score(
    uuid, uuid, uuid, integer, text, integer, integer
) TO service_role;

REVOKE ALL ON FUNCTION public.register_my_vr_quiz_participant(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.get_my_vr_quiz_score_slot(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.submit_my_vr_quiz_score(
    uuid, uuid, integer, text, integer, integer
) FROM PUBLIC, anon, authenticated, service_role;

GRANT EXECUTE ON FUNCTION public.register_my_vr_quiz_participant(uuid)
    TO authenticated;
GRANT EXECUTE ON FUNCTION public.get_my_vr_quiz_score_slot(uuid)
    TO authenticated;
GRANT EXECUTE ON FUNCTION public.submit_my_vr_quiz_score(
    uuid, uuid, integer, text, integer, integer
) TO authenticated;

COMMENT ON FUNCTION public.register_my_vr_quiz_participant(uuid) IS
    'Registers auth.uid() for an active assigned VR quiz; callers cannot supply a student id.';
COMMENT ON FUNCTION public.get_my_vr_quiz_score_slot(uuid) IS
    'Returns VR attempt permission for auth.uid(); callers cannot inspect another student slot.';
COMMENT ON FUNCTION public.submit_my_vr_quiz_score(
    uuid, uuid, integer, text, integer, integer
) IS 'Submits a VR result for auth.uid() through the guarded immutable score function.';

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_09_23_vr_retake_score_repair.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_function_privilege(
        'authenticated',
        'public.submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)',
        'EXECUTE'
    ) AS authenticated_score_ready,
    NOT has_function_privilege(
        'anon',
        'public.submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)',
        'EXECUTE'
    ) AS anonymous_score_closed,
    NOT has_function_privilege(
        'authenticated',
        'public.submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)',
        'EXECUTE'
    ) AS direct_authenticated_score_closed,
    to_regclass('public.quiz_results_attempt_number_idx') IS NOT NULL
        AS attempt_index_ready,
    to_regclass('public.quiz_results_one_counted_attempt_idx') IS NOT NULL
        AS counted_index_ready;
