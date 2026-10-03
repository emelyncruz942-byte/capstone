-- MathVerse forward migration: server-authoritative VR quizzes and durable
-- machine-request replay/rate protection.
-- Run after 2026_09_24_quiz_lifecycle_and_active_retakes.sql and deploy the
-- matching Unity client at the same time. Existing results and retake grants
-- are retained.

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
       OR to_regprocedure('public.get_vr_quiz_score_slot(uuid,uuid)') IS NULL
       OR to_regprocedure('public.submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)') IS NULL
       OR to_regprocedure('auth.uid()') IS NULL THEN
        RAISE EXCEPTION 'Required VR tables, auth.uid(), or guarded score functions are missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_24_quiz_lifecycle_and_active_retakes.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_09_24_quiz_lifecycle_and_active_retakes.sql first.';
    END IF;
END
$preflight$;

-- These archives might have been created by a prior rollback. Secure every
-- one that exists without requiring rollback-only installations to have all
-- three tables.
DO $secure_archives$
DECLARE
    archive_name text;
BEGIN
    FOREACH archive_name IN ARRAY ARRAY[
        'rollback_arcade_achievements_20260912',
        'rollback_arcade_scores_20260912',
        'rollback_privileged_audit_outbox_20260912'
    ] LOOP
        IF to_regclass('public.' || archive_name) IS NOT NULL THEN
            EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', archive_name);
            EXECUTE format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY', archive_name);
            EXECUTE format(
                'REVOKE ALL PRIVILEGES ON TABLE public.%I FROM PUBLIC, anon, authenticated, service_role',
                archive_name
            );
        END IF;
    END LOOP;
END
$secure_archives$;

-- One bounded row per signed-in actor and operation provides an atomic limit
-- for direct Unity-to-Supabase traffic, independent of Laravel middleware.
CREATE TABLE IF NOT EXISTS public.mathverse_vr_request_limits (
    actor_id uuid NOT NULL,
    scope text NOT NULL CHECK (scope ~ '^[a-z][a-z0-9_-]{0,63}$'),
    window_started_at timestamp with time zone NOT NULL,
    request_count integer NOT NULL CHECK (request_count > 0),
    updated_at timestamp with time zone NOT NULL,
    PRIMARY KEY (actor_id, scope)
);
ALTER TABLE public.mathverse_vr_request_limits ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.mathverse_vr_request_limits FORCE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON TABLE public.mathverse_vr_request_limits
    FROM PUBLIC, anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.consume_vr_request_limit(
    p_actor_id uuid,
    p_scope text,
    p_limit integer,
    p_window_seconds integer
)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    checked_at timestamp with time zone := clock_timestamp();
    current_count integer;
BEGIN
    IF p_actor_id IS NULL
       OR p_scope !~ '^[a-z][a-z0-9_-]{0,63}$'
       OR p_limit NOT BETWEEN 1 AND 1000
       OR p_window_seconds NOT BETWEEN 1 AND 3600 THEN
        RETURN false;
    END IF;

    INSERT INTO public.mathverse_vr_request_limits AS request_limit (
        actor_id, scope, window_started_at, request_count, updated_at
    ) VALUES (p_actor_id, p_scope, checked_at, 1, checked_at)
    ON CONFLICT (actor_id, scope) DO UPDATE
    SET window_started_at = CASE
            WHEN request_limit.window_started_at
                    <= checked_at - make_interval(secs => p_window_seconds)
                THEN checked_at
            ELSE request_limit.window_started_at
        END,
        request_count = CASE
            WHEN request_limit.window_started_at
                    <= checked_at - make_interval(secs => p_window_seconds)
                THEN 1
            ELSE request_limit.request_count + 1
        END,
        updated_at = checked_at
    RETURNING request_count INTO current_count;

    RETURN current_count <= p_limit;
END;
$$;
REVOKE ALL ON FUNCTION public.consume_vr_request_limit(uuid, text, integer, integer)
    FROM PUBLIC, anon, authenticated, service_role;

-- Durable HMAC nonce claims for the Edge push receiver. The Edge Function
-- validates the HMAC before calling this service-role-only RPC.
CREATE TABLE IF NOT EXISTS public.mathverse_machine_requests (
    scope text NOT NULL CHECK (scope ~ '^[a-z0-9][a-z0-9:_-]{0,63}$'),
    nonce text NOT NULL CHECK (nonce ~ '^[A-Za-z0-9_-]{32,128}$'),
    signed_at timestamp with time zone NOT NULL,
    accepted_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (scope, nonce)
);
CREATE INDEX IF NOT EXISTS mathverse_machine_requests_scope_accepted_idx
    ON public.mathverse_machine_requests (scope, accepted_at DESC);
ALTER TABLE public.mathverse_machine_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.mathverse_machine_requests FORCE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON TABLE public.mathverse_machine_requests
    FROM PUBLIC, anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.claim_machine_request(
    p_scope text,
    p_nonce text,
    p_timestamp timestamp with time zone,
    p_limit integer,
    p_window_seconds integer
)
RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    checked_at timestamp with time zone := clock_timestamp();
    recent_count integer;
BEGIN
    IF p_scope !~ '^[a-z0-9][a-z0-9:_-]{0,63}$'
       OR p_nonce !~ '^[A-Za-z0-9_-]{32,128}$'
       OR p_timestamp IS NULL
       OR abs(extract(epoch FROM checked_at - p_timestamp)) > 300
       OR p_limit NOT BETWEEN 1 AND 1000
       OR p_window_seconds NOT BETWEEN 1 AND 3600 THEN
        RETURN false;
    END IF;

    LOCK TABLE ONLY public.mathverse_machine_requests IN SHARE ROW EXCLUSIVE MODE;
    DELETE FROM public.mathverse_machine_requests
    WHERE accepted_at < checked_at - interval '1 day';

    IF EXISTS (
        SELECT 1 FROM public.mathverse_machine_requests
        WHERE scope = p_scope AND nonce = p_nonce
    ) THEN
        RETURN false;
    END IF;

    SELECT count(*)::integer INTO recent_count
    FROM public.mathverse_machine_requests
    WHERE scope = p_scope
      AND accepted_at > checked_at - make_interval(secs => p_window_seconds);
    IF recent_count >= p_limit THEN
        RETURN false;
    END IF;

    INSERT INTO public.mathverse_machine_requests (scope, nonce, signed_at, accepted_at)
    VALUES (p_scope, p_nonce, p_timestamp, checked_at);
    RETURN true;
END;
$$;
REVOKE ALL ON FUNCTION public.claim_machine_request(
    text, text, timestamp with time zone, integer, integer
) FROM PUBLIC, anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.claim_machine_request(
    text, text, timestamp with time zone, integer, integer
) TO service_role;

-- Current questions store zero-based indexes. Retained older rows can contain
-- choice text or a letter/number label, so resolve those formats on the server.
CREATE OR REPLACE FUNCTION public.resolve_vr_answer_index(
    p_correct_answer text,
    p_choice1 text,
    p_choice2 text,
    p_choice3 text,
    p_choice4 text
)
RETURNS integer
LANGUAGE plpgsql IMMUTABLE
SET search_path = pg_catalog
AS $$
DECLARE
    answer text := btrim(coalesce(p_correct_answer, ''));
    compact text;
BEGIN
    IF answer = '' THEN RETURN NULL; END IF;
    IF answer ~ '^[0-3]$' THEN RETURN answer::integer; END IF;
    IF lower(answer) = lower(btrim(coalesce(p_choice1, ''))) THEN RETURN 0; END IF;
    IF lower(answer) = lower(btrim(coalesce(p_choice2, ''))) THEN RETURN 1; END IF;
    IF lower(answer) = lower(btrim(coalesce(p_choice3, ''))) THEN RETURN 2; END IF;
    IF lower(answer) = lower(btrim(coalesce(p_choice4, ''))) THEN RETURN 3; END IF;

    -- Retained rows can contain labels such as "C) Earth" or "D. Mars".
    -- Match the Unity resolver after checking exact choice text so a choice
    -- whose literal text begins with a label is not misclassified.
    IF char_length(answer) > 1
       AND lower(substr(answer, 1, 1)) ~ '^[a-d]$'
       AND substr(answer, 2, 1) ~ '^[[:space:].):-]$' THEN
        RETURN ascii(lower(substr(answer, 1, 1))) - ascii('a');
    END IF;

    compact := regexp_replace(lower(answer), '[ _\.():-]', '', 'g');
    compact := regexp_replace(compact, '^(choice|option|answer)', '');
    IF compact ~ '^[1-4]$' THEN RETURN compact::integer - 1; END IF;
    IF compact ~ '^[a-d]$' THEN RETURN ascii(compact) - ascii('a'); END IF;
    RETURN NULL;
END;
$$;
REVOKE ALL ON FUNCTION public.resolve_vr_answer_index(text, text, text, text, text)
    FROM PUBLIC, anon, authenticated, service_role;

-- Four-digit codes remain compatible with all existing website assignments.
-- The private session UUID is returned only after authentication, eligibility,
-- account-state, due-date and rate checks pass.
CREATE OR REPLACE FUNCTION public.join_my_vr_quiz_room(p_room_code text)
RETURNS TABLE (
    joined boolean,
    status text,
    message text,
    session_id uuid,
    time_limit integer
)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    caller_id uuid := auth.uid();
    normalized_code text := btrim(coalesce(p_room_code, ''));
    assignment public.quiz_sessions%rowtype;
    eligibility public.quiz_session_students%rowtype;
    inserted_rows integer := 0;
    checked_at timestamp with time zone := clock_timestamp();
BEGIN
    joined := false;
    session_id := NULL;
    time_limit := 0;

    IF caller_id IS NULL THEN
        status := 'not_authenticated';
        message := 'Sign in again before joining a VR quiz.';
        RETURN NEXT; RETURN;
    END IF;
    IF NOT public.consume_vr_request_limit(caller_id, 'join', 12, 60) THEN
        status := 'rate_limited';
        message := 'Too many room attempts. Wait a minute and try again.';
        RETURN NEXT; RETURN;
    END IF;
    IF normalized_code !~ '^[0-9]{4}$' THEN
        status := 'invalid_code';
        message := 'Enter the four-digit quiz room code.';
        RETURN NEXT; RETURN;
    END IF;

    SELECT session_row.* INTO assignment
    FROM public.quiz_sessions AS session_row
    WHERE session_row.room_code = normalized_code
      AND session_row.status IN ('waiting', 'active')
      AND session_row.is_active IS TRUE
    ORDER BY session_row.available_at DESC NULLS LAST, session_row.id
    LIMIT 1
    FOR SHARE;

    IF assignment.id IS NOT NULL THEN
        SELECT student_row.* INTO eligibility
        FROM public.quiz_session_students AS student_row
        WHERE student_row.session_id = assignment.id
          AND student_row.student_id = caller_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.profiles AS profile_row
        WHERE profile_row.id = caller_id AND profile_row.role = 'student'
    ) THEN
        status := 'invalid_student';
        message := 'A valid student account is required.';
    ELSIF EXISTS (
        SELECT 1 FROM public.profiles AS profile_row
        WHERE profile_row.id = caller_id
          AND (profile_row.suspended_at IS NOT NULL
               OR profile_row.deactivated_at IS NOT NULL)
    ) THEN
        status := 'account_unavailable';
        message := 'This account cannot join quiz assignments.';
    ELSIF assignment.id IS NULL THEN
        status := 'not_available';
        message := 'This room code is invalid or the quiz has ended.';
    ELSIF assignment.due_at IS NOT NULL AND assignment.due_at <= checked_at THEN
        status := 'expired';
        message := 'This quiz is past its due time.';
    ELSIF eligibility.session_id IS NULL
          OR eligibility.eligibility_status <> 'eligible' THEN
        status := 'not_eligible';
        message := 'This quiz is not assigned to your signed-in student account.';
    ELSIF eligibility.retake_due_at IS NOT NULL
          AND eligibility.retake_due_at <= checked_at THEN
        status := 'expired';
        message := 'Your retake permission has expired.';
    ELSE
        INSERT INTO public.quiz_participants (session_id, student_id)
        VALUES (assignment.id, caller_id)
        ON CONFLICT DO NOTHING;
        GET DIAGNOSTICS inserted_rows = ROW_COUNT;

        joined := true;
        status := CASE WHEN inserted_rows = 1 THEN 'joined' ELSE 'already_joined' END;
        message := CASE WHEN inserted_rows = 1
            THEN 'The signed-in student joined the VR quiz.'
            ELSE 'The signed-in student was already registered for this VR quiz.'
        END;
        session_id := assignment.id;
        time_limit := greatest(1, coalesce(assignment.time_limit, 20));
    END IF;
    RETURN NEXT;
END;
$$;

CREATE OR REPLACE FUNCTION public.get_my_vr_quiz_status(p_session_id uuid)
RETURNS TABLE (status text, is_active boolean, time_limit integer)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    caller_id uuid := auth.uid();
BEGIN
    IF caller_id IS NULL
       OR NOT public.consume_vr_request_limit(caller_id, 'status', 60, 60) THEN
        RETURN;
    END IF;

    RETURN QUERY
    SELECT session_row.status,
           session_row.is_active,
           greatest(1, coalesce(session_row.time_limit, 20))
    FROM public.quiz_sessions AS session_row
    WHERE session_row.id = p_session_id
      AND EXISTS (
          SELECT 1 FROM public.profiles AS profile_row
          WHERE profile_row.id = caller_id
            AND profile_row.role = 'student'
            AND profile_row.suspended_at IS NULL
            AND profile_row.deactivated_at IS NULL
      )
      AND EXISTS (
          SELECT 1 FROM public.quiz_session_students AS student_row
          WHERE student_row.session_id = session_row.id
            AND student_row.student_id = caller_id
            AND student_row.eligibility_status = 'eligible'
      )
      AND EXISTS (
          SELECT 1 FROM public.quiz_participants AS participant_row
          WHERE participant_row.session_id = session_row.id
            AND participant_row.student_id = caller_id
      );
END;
$$;

CREATE OR REPLACE FUNCTION public.get_my_vr_quiz_questions(p_session_id uuid)
RETURNS TABLE (
    question_id bigint,
    question text,
    choice1 text,
    choice2 text,
    choice3 text,
    choice4 text
)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    caller_id uuid := auth.uid();
    checked_at timestamp with time zone := clock_timestamp();
BEGIN
    IF caller_id IS NULL THEN
        RAISE EXCEPTION 'Authentication is required' USING ERRCODE = '42501';
    END IF;
    IF NOT public.consume_vr_request_limit(caller_id, 'questions', 20, 60) THEN
        RAISE EXCEPTION 'Too many question requests. Wait a minute and retry.'
            USING ERRCODE = 'P0001';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM public.quiz_sessions AS session_row
        JOIN public.quiz_session_students AS student_row
          ON student_row.session_id = session_row.id
         AND student_row.student_id = caller_id
         AND student_row.eligibility_status = 'eligible'
        JOIN public.quiz_participants AS participant_row
          ON participant_row.session_id = session_row.id
         AND participant_row.student_id = caller_id
        JOIN public.profiles AS profile_row
          ON profile_row.id = caller_id
         AND profile_row.role = 'student'
         AND profile_row.suspended_at IS NULL
         AND profile_row.deactivated_at IS NULL
        WHERE session_row.id = p_session_id
          AND session_row.status = 'active'
          AND session_row.is_active IS TRUE
          AND (session_row.due_at IS NULL OR session_row.due_at > checked_at)
          AND (student_row.retake_due_at IS NULL OR student_row.retake_due_at > checked_at)
    ) THEN
        RAISE EXCEPTION 'This quiz is not available to the signed-in student'
            USING ERRCODE = '42501';
    END IF;

    RETURN QUERY
    SELECT question_row.id,
           question_row.question,
           question_row.choice1,
           question_row.choice2,
           question_row.choice3,
           question_row.choice4
    FROM public.questions AS question_row
    WHERE question_row.session_id = p_session_id
      AND question_row.deleted_at IS NULL
    ORDER BY question_row.id;
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
        can_submit := false; status := 'not_authenticated'; attempt_number := 0;
        retake_granted_at := ''; message := 'Sign in again before starting a VR quiz.';
        RETURN NEXT; RETURN;
    END IF;
    IF NOT public.consume_vr_request_limit(caller_id, 'score-slot', 20, 60) THEN
        can_submit := false; status := 'rate_limited'; attempt_number := 0;
        retake_granted_at := ''; message := 'Too many attempt checks. Wait a minute and try again.';
        RETURN NEXT; RETURN;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM public.quiz_sessions AS session_row
        WHERE session_row.id = p_session_id
          AND session_row.status = 'active'
          AND session_row.is_active IS TRUE
    ) THEN
        can_submit := false; status := 'not_active'; attempt_number := 0;
        retake_granted_at := ''; message := 'Wait for the teacher to start this quiz.';
        RETURN NEXT; RETURN;
    END IF;

    RETURN QUERY
    SELECT slot.can_submit, slot.status, slot.attempt_number,
           slot.retake_granted_at, slot.message
    FROM public.get_vr_quiz_score_slot(p_session_id, caller_id) AS slot;
END;
$$;

CREATE OR REPLACE FUNCTION public.submit_my_vr_quiz_answers(
    p_session_id uuid,
    p_submission_id uuid,
    p_attempt_number integer,
    p_retake_granted_at text,
    p_question_ids bigint[],
    p_selections integer[]
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
    existing_receipt public.quiz_results%rowtype;
    expected_count integer;
    supplied_count integer := coalesce(array_length(p_question_ids, 1), 0);
    selection_count integer := coalesce(array_length(p_selections, 1), 0);
    matching_count integer;
    distinct_count integer;
    calculated_score integer;
BEGIN
    saved := false;
    IF caller_id IS NULL THEN
        status := 'not_authenticated';
        message := 'Sign in again before submitting a VR quiz score.';
        RETURN NEXT; RETURN;
    END IF;
    IF NOT public.consume_vr_request_limit(caller_id, 'score-submit', 10, 60) THEN
        status := 'rate_limited';
        message := 'Too many score requests. Wait a minute and retry the same submission.';
        RETURN NEXT; RETURN;
    END IF;
    IF p_session_id IS NULL
       OR p_submission_id IS NULL
       OR p_submission_id = '00000000-0000-0000-0000-000000000000'::uuid
       OR p_attempt_number IS NULL OR p_attempt_number < 1
       OR supplied_count < 1 OR supplied_count > 500
       OR supplied_count <> selection_count THEN
        status := 'invalid_request';
        message := 'The answer submission is invalid.';
        RETURN NEXT; RETURN;
    END IF;

    -- A committed submission is immutable. Return its original receipt before
    -- checking live quiz state or the current question set so a client can
    -- safely retry after losing the first response, even if the teacher has
    -- since ended or edited the quiz.
    SELECT result_row.* INTO existing_receipt
    FROM public.quiz_results AS result_row
    WHERE result_row.id = p_submission_id;

    IF FOUND THEN
        IF existing_receipt.session_id IS DISTINCT FROM p_session_id
           OR existing_receipt.student_id IS DISTINCT FROM caller_id THEN
            status := 'invalid_request';
            message := 'This submission ID belongs to another attempt.';
            RETURN NEXT; RETURN;
        END IF;

        saved := true;
        status := 'already_saved';
        result_id := existing_receipt.id;
        attempt_number := existing_receipt.attempt_number;
        correct_answers := existing_receipt.correct_answers;
        total_questions := existing_receipt.total_questions;
        message := 'This submission was already saved. The original score is unchanged.';
        RETURN NEXT; RETURN;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.quiz_sessions AS session_row
        WHERE session_row.id = p_session_id
          AND session_row.status = 'active'
          AND session_row.is_active IS TRUE
    ) THEN
        status := 'not_active';
        message := 'Wait for the teacher to start this quiz.';
        RETURN NEXT; RETURN;
    END IF;
    IF EXISTS (
        SELECT 1 FROM unnest(p_selections) AS answer(value)
        WHERE answer.value NOT BETWEEN -1 AND 3
    ) THEN
        status := 'invalid_answers';
        message := 'One or more selected answers are invalid.';
        RETURN NEXT; RETURN;
    END IF;

    SELECT count(*)::integer INTO expected_count
    FROM public.questions AS question_row
    WHERE question_row.session_id = p_session_id
      AND question_row.deleted_at IS NULL;

    SELECT count(DISTINCT response.question_id)::integer,
           count(question_row.id)::integer
    INTO distinct_count, matching_count
    FROM unnest(p_question_ids) AS response(question_id)
    LEFT JOIN public.questions AS question_row
      ON question_row.id = response.question_id
     AND question_row.session_id = p_session_id
     AND question_row.deleted_at IS NULL;

    IF expected_count < 1
       OR supplied_count <> expected_count
       OR distinct_count <> supplied_count
       OR matching_count <> supplied_count THEN
        status := 'question_mismatch';
        message := 'The submitted questions do not match this quiz.';
        RETURN NEXT; RETURN;
    END IF;

    SELECT count(*) FILTER (
               WHERE response.selection = public.resolve_vr_answer_index(
                   question_row.correct_answer,
                   question_row.choice1,
                   question_row.choice2,
                   question_row.choice3,
                   question_row.choice4
               )
           )::integer
    INTO calculated_score
    FROM unnest(p_question_ids, p_selections) AS response(question_id, selection)
    JOIN public.questions AS question_row
      ON question_row.id = response.question_id
     AND question_row.session_id = p_session_id
     AND question_row.deleted_at IS NULL;

    RETURN QUERY
    SELECT receipt.saved, receipt.status, receipt.result_id,
           receipt.attempt_number, receipt.correct_answers,
           receipt.total_questions, receipt.message
    FROM public.submit_vr_quiz_score(
        p_session_id,
        caller_id,
        p_submission_id,
        p_attempt_number,
        coalesce(p_retake_granted_at, ''),
        coalesce(calculated_score, 0),
        expected_count
    ) AS receipt;
END;
$$;

-- Close direct answer-bearing reads and every caller-scored legacy client path.
-- Laravel continues to use its service role for teacher/admin workflows.
REVOKE SELECT ON TABLE public.questions FROM PUBLIC, anon, authenticated;
REVOKE SELECT ON TABLE public.quiz_sessions FROM PUBLIC, anon, authenticated;
REVOKE INSERT ON TABLE public.quiz_participants FROM PUBLIC, anon, authenticated;

-- PostgreSQL table ACLs and column ACLs are independent. The retired legacy
-- migration granted individual room columns and participant insert columns,
-- so clear every matching column grant as well as the table-level grants.
DO $close_legacy_vr_column_privileges$
DECLARE
    column_record record;
BEGIN
    FOR column_record IN
        SELECT class_row.relname AS table_name, attribute_row.attname AS column_name
        FROM pg_class AS class_row
        JOIN pg_namespace AS namespace_row ON namespace_row.oid = class_row.relnamespace
        JOIN pg_attribute AS attribute_row ON attribute_row.attrelid = class_row.oid
        WHERE namespace_row.nspname = 'public'
          AND class_row.relname IN ('questions', 'quiz_sessions')
          AND attribute_row.attnum > 0
          AND NOT attribute_row.attisdropped
    LOOP
        EXECUTE format(
            'REVOKE SELECT (%1$I) ON TABLE public.%2$I FROM PUBLIC, anon, authenticated',
            column_record.column_name,
            column_record.table_name
        );
    END LOOP;

    FOR column_record IN
        SELECT attribute_row.attname AS column_name
        FROM pg_attribute AS attribute_row
        WHERE attribute_row.attrelid = 'public.quiz_participants'::regclass
          AND attribute_row.attnum > 0
          AND NOT attribute_row.attisdropped
    LOOP
        EXECUTE format(
            'REVOKE INSERT (%1$I) ON TABLE public.quiz_participants FROM PUBLIC, anon, authenticated',
            column_record.column_name
        );
    END LOOP;
END
$close_legacy_vr_column_privileges$;

DROP POLICY IF EXISTS mathverse_vr_legacy_read_allow ON public.quiz_sessions;
DROP POLICY IF EXISTS mathverse_vr_legacy_read_boundary ON public.quiz_sessions;
DROP POLICY IF EXISTS mathverse_vr_legacy_read_allow ON public.questions;
DROP POLICY IF EXISTS mathverse_vr_legacy_read_boundary ON public.questions;
DROP POLICY IF EXISTS mathverse_vr_legacy_join_allow ON public.quiz_participants;
DROP POLICY IF EXISTS mathverse_vr_legacy_join_boundary ON public.quiz_participants;

REVOKE ALL ON FUNCTION public.register_my_vr_quiz_participant(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.submit_my_vr_quiz_score(
    uuid, uuid, integer, text, integer, integer
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.join_my_vr_quiz_room(text)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.get_my_vr_quiz_status(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.get_my_vr_quiz_questions(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.get_my_vr_quiz_score_slot(uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.submit_my_vr_quiz_answers(
    uuid, uuid, integer, text, bigint[], integer[]
) FROM PUBLIC, anon, authenticated, service_role;

GRANT EXECUTE ON FUNCTION public.join_my_vr_quiz_room(text) TO authenticated;
GRANT EXECUTE ON FUNCTION public.get_my_vr_quiz_status(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION public.get_my_vr_quiz_questions(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION public.get_my_vr_quiz_score_slot(uuid) TO authenticated;
GRANT EXECUTE ON FUNCTION public.submit_my_vr_quiz_answers(
    uuid, uuid, integer, text, bigint[], integer[]
) TO authenticated;

COMMENT ON FUNCTION public.join_my_vr_quiz_room(text) IS
    'Validates a four-digit room code and eligibility, registers auth.uid(), and returns the private session UUID.';
COMMENT ON FUNCTION public.get_my_vr_quiz_questions(uuid) IS
    'Returns assigned VR question text and choices to auth.uid() without correct answers.';
COMMENT ON FUNCTION public.submit_my_vr_quiz_answers(
    uuid, uuid, integer, text, bigint[], integer[]
) IS 'Computes a VR score from server-held answers and preserves immutable idempotent retake results.';
COMMENT ON FUNCTION public.claim_machine_request(
    text, text, timestamp with time zone, integer, integer
) IS 'Service-only durable nonce claim and per-scope rate limit for signed machine requests.';

DO $verify_closed_vr_acl$
DECLARE
    role_name text;
BEGIN
    FOREACH role_name IN ARRAY ARRAY['anon', 'authenticated'] LOOP
        IF has_table_privilege(role_name, 'public.questions', 'SELECT')
           OR has_table_privilege(role_name, 'public.quiz_sessions', 'SELECT')
           OR has_table_privilege(role_name, 'public.quiz_participants', 'INSERT')
           OR EXISTS (
               SELECT 1 FROM pg_attribute AS attribute_row
               WHERE attribute_row.attrelid = 'public.questions'::regclass
                 AND attribute_row.attnum > 0
                 AND NOT attribute_row.attisdropped
                 AND has_column_privilege(
                     role_name, 'public.questions', attribute_row.attname, 'SELECT'
                 )
           )
           OR EXISTS (
               SELECT 1 FROM pg_attribute AS attribute_row
               WHERE attribute_row.attrelid = 'public.quiz_sessions'::regclass
                 AND attribute_row.attnum > 0
                 AND NOT attribute_row.attisdropped
                 AND has_column_privilege(
                     role_name, 'public.quiz_sessions', attribute_row.attname, 'SELECT'
                 )
           )
           OR EXISTS (
               SELECT 1 FROM pg_attribute AS attribute_row
               WHERE attribute_row.attrelid = 'public.quiz_participants'::regclass
                 AND attribute_row.attnum > 0
                 AND NOT attribute_row.attisdropped
                 AND has_column_privilege(
                     role_name, 'public.quiz_participants', attribute_row.attname, 'INSERT'
                 )
           ) THEN
            RAISE EXCEPTION 'Legacy direct VR privileges remain for role %', role_name;
        END IF;
    END LOOP;
END
$verify_closed_vr_acl$;

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_10_02_vr_server_authority_and_request_guards.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_function_privilege('authenticated',
        'public.join_my_vr_quiz_room(text)', 'EXECUTE') AS authenticated_join_ready,
    has_function_privilege('authenticated',
        'public.get_my_vr_quiz_questions(uuid)', 'EXECUTE') AS safe_questions_ready,
    has_function_privilege('authenticated',
        'public.submit_my_vr_quiz_answers(uuid,uuid,integer,text,bigint[],integer[])',
        'EXECUTE') AS server_scoring_ready,
    NOT has_table_privilege('anon', 'public.questions', 'SELECT') AS anonymous_answers_closed,
    NOT has_table_privilege('authenticated', 'public.questions', 'SELECT') AS direct_answers_closed,
    NOT has_column_privilege('anon', 'public.quiz_sessions', 'room_code', 'SELECT')
        AS anonymous_room_columns_closed,
    NOT has_column_privilege('anon', 'public.quiz_participants', 'student_id', 'INSERT')
        AS anonymous_participant_columns_closed,
    has_function_privilege('service_role',
        'public.claim_machine_request(text,text,timestamp with time zone,integer,integer)',
        'EXECUTE') AS machine_guard_ready;
