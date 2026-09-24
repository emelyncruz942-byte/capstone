-- MathVerse forward migration: reliable manual quiz lifecycle changes and
-- per-student retakes while the original quiz is still active.
-- Run after 2026_09_23_vr_retake_score_repair.sql.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.classes') IS NULL
       OR to_regclass('public.profiles') IS NULL
       OR to_regclass('public.quiz_sessions') IS NULL
       OR to_regclass('public.quiz_session_students') IS NULL
       OR to_regclass('public.quiz_results') IS NULL
       OR to_regprocedure('public.get_vr_quiz_score_slot(uuid,uuid)') IS NULL THEN
        RAISE EXCEPTION 'Required quiz lifecycle tables or VR score functions are missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_23_vr_retake_score_repair.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_09_23_vr_retake_score_repair.sql before this migration.';
    END IF;
END
$preflight$;

-- This is the only manual start/end write path used by Laravel. Locking the
-- assignment makes concurrent clicks deterministic, and returning the current
-- state makes a retry safe after a lost HTTP response.
CREATE OR REPLACE FUNCTION public.transition_quiz_session(
    p_session_id uuid,
    p_class_id uuid,
    p_teacher_id uuid,
    p_action text
)
RETURNS TABLE (
    outcome_code text,
    changed boolean,
    session_status text,
    started_at timestamp with time zone,
    ended_at timestamp with time zone,
    quiz_topic text,
    started_early boolean
)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    assignment public.quiz_sessions%rowtype;
    changed_at timestamp with time zone := clock_timestamp();
    requested_action text := lower(btrim(coalesce(p_action, '')));
BEGIN
    IF requested_action NOT IN ('start', 'end') THEN
        RAISE EXCEPTION 'Unsupported quiz lifecycle action'
            USING ERRCODE = '22023';
    END IF;

    SELECT session_row.* INTO assignment
    FROM public.quiz_sessions AS session_row
    WHERE session_row.id = p_session_id
      AND session_row.class_id = p_class_id
      AND session_row.teacher_id = p_teacher_id
    FOR UPDATE;

    IF assignment.id IS NULL THEN
        RAISE EXCEPTION 'Quiz assignment not found for this teacher and class'
            USING ERRCODE = 'P0002';
    END IF;

    PERFORM 1
    FROM public.profiles AS teacher_row
    WHERE teacher_row.id = p_teacher_id
      AND teacher_row.role = 'teacher'
      AND teacher_row.suspended_at IS NULL
      AND teacher_row.deactivated_at IS NULL
    FOR SHARE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'An active teacher account is required'
            USING ERRCODE = '42501';
    END IF;

    quiz_topic := assignment.topic;
    started_early := false;

    IF requested_action = 'start' THEN
        IF assignment.status = 'active' AND assignment.is_active IS TRUE THEN
            outcome_code := 'already_active';
            changed := false;
            session_status := assignment.status;
            started_at := assignment.started_at;
            ended_at := assignment.ended_at;
            RETURN NEXT;
            RETURN;
        END IF;

        started_early := assignment.available_at IS NOT NULL
            AND assignment.available_at > changed_at;

        IF assignment.status = 'completed' THEN
            RAISE EXCEPTION 'An ended quiz cannot be restarted'
                USING ERRCODE = '55000';
        END IF;
        IF assignment.status NOT IN ('waiting', 'active') THEN
            RAISE EXCEPTION 'Only an assigned quiz can be started'
                USING ERRCODE = '55000';
        END IF;

        PERFORM 1
        FROM public.classes AS class_row
        WHERE class_row.id = p_class_id
          AND class_row.teacher_id = p_teacher_id
          AND class_row.archived_at IS NULL
          AND class_row.deleted_at IS NULL
        FOR SHARE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'A quiz in an archived or trashed class cannot restart'
                USING ERRCODE = '55000';
        END IF;

        IF assignment.due_at IS NOT NULL AND assignment.due_at <= changed_at THEN
            RAISE EXCEPTION 'This quiz assignment is already past due'
                USING ERRCODE = '22023';
        END IF;

        UPDATE public.quiz_sessions AS target
        SET status = 'active',
            is_active = true,
            available_at = changed_at,
            started_at = coalesce(target.started_at, changed_at),
            ended_at = null
        WHERE target.id = assignment.id
        RETURNING target.status, target.started_at, target.ended_at
        INTO session_status, started_at, ended_at;

        outcome_code := 'started';
        changed := true;
        RETURN NEXT;
        RETURN;
    END IF;

    IF assignment.status = 'completed'
       AND assignment.is_active IS FALSE
       AND assignment.retake_mode IS FALSE THEN
        outcome_code := 'already_completed';
        changed := false;
        session_status := assignment.status;
        started_at := assignment.started_at;
        ended_at := assignment.ended_at;
        RETURN NEXT;
        RETURN;
    END IF;
    IF assignment.status NOT IN ('waiting', 'active', 'completed') THEN
        RAISE EXCEPTION 'This quiz is not in an endable state'
            USING ERRCODE = '55000';
    END IF;

    UPDATE public.quiz_sessions AS target
    SET status = 'completed',
        is_active = false,
        retake_mode = false,
        ended_at = coalesce(target.ended_at, changed_at)
    WHERE target.id = assignment.id
    RETURNING target.status, target.started_at, target.ended_at
    INTO session_status, started_at, ended_at;

    outcome_code := 'ended';
    changed := true;
    RETURN NEXT;
END;
$$;

REVOKE ALL ON FUNCTION public.transition_quiz_session(uuid, uuid, uuid, text)
    FROM PUBLIC, anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.transition_quiz_session(uuid, uuid, uuid, text)
    TO service_role;

-- A finished student can receive one extra attempt while classmates continue
-- their first attempt. Only reopening an ended assignment enables global
-- retake_mode; an original active assignment is left otherwise unchanged.
CREATE OR REPLACE FUNCTION public.grant_quiz_retake(
    p_session_id uuid,
    p_student_id uuid,
    p_teacher_id uuid,
    p_reason text,
    p_due_at timestamp with time zone DEFAULT NULL
)
RETURNS TABLE (new_allowed_attempts integer, retake_due_at timestamp with time zone)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    assignment public.quiz_sessions%rowtype;
    eligibility public.quiz_session_students%rowtype;
    attempts_used integer;
    next_allowed integer;
    requested_due timestamp with time zone;
    next_due timestamp with time zone;
    session_due timestamp with time zone;
    checked_at timestamp with time zone := clock_timestamp();
    active_original boolean;
BEGIN
    SELECT session_row.* INTO assignment
    FROM public.quiz_sessions AS session_row
    WHERE session_row.id = p_session_id
      AND session_row.teacher_id = p_teacher_id
    FOR UPDATE;

    IF assignment.id IS NULL THEN
        RAISE EXCEPTION 'Quiz assignment not found for this teacher';
    END IF;

    PERFORM 1
    FROM public.classes AS class_row
    WHERE class_row.id = assignment.class_id
      AND class_row.teacher_id = p_teacher_id
      AND class_row.archived_at IS NULL
      AND class_row.deleted_at IS NULL
    FOR SHARE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'A quiz in an archived or trashed class cannot grant retakes';
    END IF;

    active_original := assignment.status = 'active'
        AND assignment.is_active IS TRUE
        AND assignment.retake_mode IS FALSE;
    IF NOT active_original
       AND assignment.status <> 'completed'
       AND NOT (assignment.status = 'active' AND assignment.retake_mode IS TRUE) THEN
        RAISE EXCEPTION 'End the original quiz before granting a retake';
    END IF;

    SELECT student_row.* INTO eligibility
    FROM public.quiz_session_students AS student_row
    WHERE student_row.session_id = p_session_id
      AND student_row.student_id = p_student_id
    FOR UPDATE;

    IF eligibility.session_id IS NULL THEN
        RAISE EXCEPTION 'Student is not eligible for this assignment';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.profiles AS profile_row
        WHERE profile_row.id = p_student_id
          AND (profile_row.suspended_at IS NOT NULL
               OR profile_row.deactivated_at IS NOT NULL)
    ) THEN
        RAISE EXCEPTION 'A suspended or deactivated student cannot receive a retake';
    END IF;
    IF p_reason IS NULL OR char_length(btrim(p_reason)) = 0
       OR char_length(p_reason) > 500 THEN
        RAISE EXCEPTION 'A retake reason between 1 and 500 characters is required';
    END IF;

    SELECT count(*)::integer INTO attempts_used
    FROM public.quiz_results AS result_row
    WHERE result_row.session_id = p_session_id
      AND result_row.student_id = p_student_id;

    IF active_original AND attempts_used < 1 THEN
        RAISE EXCEPTION 'The student must finish the active quiz before receiving a retake';
    END IF;
    IF eligibility.last_retake_granted_at IS NOT NULL
       AND eligibility.allowed_attempts > attempts_used
       AND (eligibility.retake_due_at IS NULL OR eligibility.retake_due_at > checked_at) THEN
        RAISE EXCEPTION 'This student already has an unused retake';
    END IF;

    requested_due := coalesce(p_due_at, checked_at + interval '1 day');
    next_due := CASE
        WHEN active_original AND assignment.due_at IS NOT NULL
            THEN least(requested_due, assignment.due_at)
        ELSE requested_due
    END;
    IF next_due <= checked_at THEN
        RAISE EXCEPTION 'The retake due date must be in the future';
    END IF;

    -- Expired unused grants do not accumulate phantom allowances. Every new
    -- grant authorizes exactly the next numbered attempt.
    next_allowed := attempts_used + 1;

    UPDATE public.quiz_session_students
    SET eligibility_status = 'eligible',
        allowed_attempts = next_allowed,
        excused_at = null,
        excused_by = null,
        excuse_reason = null,
        last_retake_granted_at = checked_at,
        last_retake_granted_by = p_teacher_id,
        retake_due_at = next_due,
        retake_reason = btrim(p_reason)
    WHERE session_id = p_session_id
      AND student_id = p_student_id;

    IF NOT active_original THEN
        SELECT greatest(next_due, max(student_row.retake_due_at))
        INTO session_due
        FROM public.quiz_session_students AS student_row
        WHERE student_row.session_id = p_session_id;

        UPDATE public.quiz_sessions
        SET status = 'active',
            is_active = true,
            retake_mode = true,
            available_at = checked_at,
            due_at = session_due,
            ended_at = null
        WHERE id = p_session_id;
    END IF;

    RETURN QUERY SELECT next_allowed, next_due;
END;
$$;

REVOKE ALL ON FUNCTION public.grant_quiz_retake(uuid, uuid, uuid, text, timestamp with time zone)
    FROM PUBLIC, anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.grant_quiz_retake(uuid, uuid, uuid, text, timestamp with time zone)
    TO service_role;

-- The September 23 repair correctly required a teacher grant for subsequent
-- attempts, but it also required assignment-wide retake_mode. That would make
-- an active per-student retake impossible. A fresh per-student grant is enough
-- when the original assignment remains active; global retake_mode still keeps
-- never-finished students out of a reopened, ended assignment.
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
    latest_submission timestamp with time zone;
    checked_at timestamp with time zone;
BEGIN
    can_submit := false;
    attempt_number := 0;
    retake_granted_at := '';

    IF p_session_id IS NULL OR p_student_id IS NULL
       OR p_student_id = '00000000-0000-0000-0000-000000000000'::uuid
       OR (auth.uid() IS NOT NULL AND auth.uid() IS DISTINCT FROM p_student_id) THEN
        status := 'invalid_student';
        message := 'Sign in again before submitting a quiz score.';
        RETURN NEXT;
        RETURN;
    END IF;

    SELECT session_row.* INTO assignment
    FROM public.quiz_sessions AS session_row
    WHERE session_row.id = p_session_id
    FOR UPDATE;

    SELECT student_row.* INTO eligibility
    FROM public.quiz_session_students AS student_row
    WHERE student_row.session_id = p_session_id
      AND student_row.student_id = p_student_id
    FOR UPDATE;

    checked_at := clock_timestamp();
    SELECT count(*)::integer, max(result_row.created_at)
    INTO attempts_used, latest_submission
    FROM public.quiz_results AS result_row
    WHERE result_row.session_id = p_session_id
      AND result_row.student_id = p_student_id;

    IF NOT EXISTS (
        SELECT 1 FROM public.profiles AS profile_row
        WHERE profile_row.id = p_student_id AND profile_row.role = 'student'
    ) THEN
        status := 'invalid_student';
        message := 'A valid student account is required.';
    ELSIF EXISTS (
        SELECT 1 FROM public.profiles AS profile_row
        WHERE profile_row.id = p_student_id
          AND (profile_row.suspended_at IS NOT NULL
               OR profile_row.deactivated_at IS NOT NULL)
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
        SELECT 1 FROM public.quiz_participants AS participant_row
        WHERE participant_row.session_id = p_session_id
          AND participant_row.student_id = p_student_id
    ) THEN
        status := 'not_joined';
        message := 'Join the VR room again before submitting a score.';
    ELSIF eligibility.session_id IS NULL
          OR eligibility.eligibility_status <> 'eligible' THEN
        status := 'not_eligible';
        message := 'You are not eligible for this quiz assignment.';
    ELSIF eligibility.retake_due_at IS NOT NULL
          AND eligibility.retake_due_at <= checked_at THEN
        status := 'expired';
        message := 'Your retake permission has expired.';
    ELSIF attempts_used >= eligibility.allowed_attempts THEN
        status := 'attempts_exhausted';
        message := 'Your allowed attempt has already been recorded.';
    ELSIF (attempts_used > 0 OR assignment.retake_mode IS TRUE)
          AND (eligibility.last_retake_granted_at IS NULL
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

REVOKE ALL ON FUNCTION public.get_vr_quiz_score_slot(uuid, uuid)
    FROM PUBLIC, anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.get_vr_quiz_score_slot(uuid, uuid)
    TO service_role;

COMMENT ON FUNCTION public.transition_quiz_session(uuid, uuid, uuid, text) IS
    'Service-only, ownership-scoped, idempotent manual quiz start/end transition.';
COMMENT ON FUNCTION public.grant_quiz_retake(uuid, uuid, uuid, text, timestamp with time zone) IS
    'Grants exactly one next attempt; active original quizzes remain available to unfinished classmates.';

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_09_24_quiz_lifecycle_and_active_retakes.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;
