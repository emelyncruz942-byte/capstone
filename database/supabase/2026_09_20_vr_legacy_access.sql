-- MathVerse forward migration: restore the legacy Unity VR database access.
-- Run the WHOLE file in Supabase SQL Editor as the database owner (postgres).
-- This migration is intentionally idempotent and permanently enables the
-- narrowly scoped VR access. Reapply it after any global security migration.
--
-- Opened: anon reads of current waiting/active VR room metadata and questions
-- (including answers), plus anon registration into those rooms using existing
-- profile IDs. This intentionally does NOT authenticate the supplied student ID.
-- Profiles/accounts, quiz_results, user_stats, class membership, XP, Storage,
-- audit records and leaderboard permissions are NOT changed. FK/unique checks,
-- triggers, RLS and original policies remain enabled and are not removed.
--
-- The quiz score/checking code was cut off in the supplied scripts, so score
-- submission is NOT opened. Photon/scene bugs, placeholder API keys, the nil
-- student UUID and duplicate participant inserts are NOT fixed by SQL.

BEGIN;
SET LOCAL search_path = pg_catalog, public, pg_temp;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $vr_access$
DECLARE
    operation constant text := 'ENABLE';
    marker constant text := 'mathverse-vr-legacy-access-v1';
    target_tables constant text[] := ARRAY['quiz_sessions', 'questions', 'quiz_participants'];
    own_policies constant text[] := ARRAY[
        'mathverse_vr_legacy_read_allow', 'mathverse_vr_legacy_read_boundary',
        'mathverse_vr_legacy_join_allow', 'mathverse_vr_legacy_join_boundary'
    ];
    table_name text;
    relation_record record;
    policy_record record;
    installed integer;
BEGIN
    IF operation NOT IN ('ENABLE', 'DISABLE') THEN
        RAISE EXCEPTION 'operation must be ENABLE or DISABLE.';
    END IF;
    PERFORM set_config('mathverse.legacy_vr_operation', operation, true);
    IF to_regclass('public.mathverse_schema_migrations') IS NULL THEN
        RAISE EXCEPTION 'Migration tracking is missing. Run the September 12 platform migration first.';
    END IF;
    IF current_user IN ('anon', 'authenticated', 'service_role') THEN
        RAISE EXCEPTION 'Use an already-authorized database owner in SQL Editor, normally postgres.';
    END IF;
    IF NOT has_schema_privilege('anon', 'public', 'USAGE') THEN
        RAISE EXCEPTION 'Anon lacks public-schema usage; review the security migration first.';
    END IF;
    IF current_setting('session_replication_role') <> 'origin' THEN
        RAISE EXCEPTION 'Keep FK/trigger enforcement enabled (origin).';
    END IF;
    FOREACH table_name IN ARRAY target_tables LOOP
        SELECT c.* INTO relation_record FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relname = table_name;
        IF NOT FOUND OR relation_record.relkind <> 'r'
            OR NOT pg_has_role(current_user, relation_record.relowner, 'USAGE') THEN
            RAISE EXCEPTION 'Missing ordinary owner-controlled table public.%.', table_name;
        END IF;
        IF NOT relation_record.relrowsecurity THEN
            RAISE EXCEPTION 'RLS must remain enabled on public.%.', table_name;
        END IF;
        IF EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = relation_record.oid OR inhparent = relation_record.oid) THEN
            RAISE EXCEPTION 'Inheritance/partitions require separate review: public.%.', table_name;
        END IF;
    END LOOP;

    LOCK TABLE ONLY public.questions, ONLY public.quiz_participants,
        ONLY public.quiz_sessions IN SHARE ROW EXCLUSIVE MODE;

    SELECT count(*) INTO installed FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relname = ANY(target_tables) AND p.polname = ANY(own_policies);
    IF installed NOT IN (0, 6) THEN
        RAISE EXCEPTION 'A partial/same-named policy set exists. Review it; do not overwrite unknown policies.';
    END IF;
    IF installed = 6 THEN
        FOR policy_record IN SELECT p.*, c.relname FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public'
            AND c.relname = ANY(target_tables) AND p.polname = ANY(own_policies)
        LOOP
            IF obj_description(policy_record.oid, 'pg_policy') IS DISTINCT FROM
                marker || ':' || md5(to_jsonb(policy_record)::text) THEN
                RAISE EXCEPTION 'Policy % does not match this patch or was edited. Review it before proceeding.', policy_record.polname;
            END IF;
        END LOOP;
        IF operation = 'ENABLE' THEN
            -- Hardening may have revoked anon grants while retaining policies.
            GRANT SELECT (id, room_code, status, time_limit, is_active) ON public.quiz_sessions TO anon;
            GRANT SELECT ON public.questions TO anon;
            GRANT INSERT (session_id, student_id) ON public.quiz_participants TO anon;
            RAISE NOTICE 'Patch policies already installed; legacy VR grants ensured.';
            RETURN;
        END IF;
        -- Refuse to remove grants after someone broadened this patch's scope.
        IF has_table_privilege('anon', 'public.quiz_sessions', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
            OR has_table_privilege('anon', 'public.questions', 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
            OR has_table_privilege('anon', 'public.quiz_participants', 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
            OR EXISTS (SELECT 1 FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = ANY(target_tables)
                    AND a.attnum > 0 AND NOT a.attisdropped AND (
                    has_column_privilege('anon', c.oid, a.attnum, 'UPDATE,REFERENCES')
                    OR (has_column_privilege('anon', c.oid, a.attnum, 'SELECT') AND
                        (c.relname = 'quiz_participants' OR (c.relname = 'quiz_sessions'
                            AND a.attname NOT IN ('id','room_code','status','time_limit','is_active'))))
                    OR (has_column_privilege('anon', c.oid, a.attnum, 'INSERT') AND
                        (c.relname <> 'quiz_participants' OR a.attname NOT IN ('session_id','student_id'))))) THEN
            RAISE EXCEPTION 'Anon privileges were broadened since ENABLE. Review before DISABLE; no grants removed.';
        END IF;
        REVOKE SELECT (id, room_code, status, time_limit, is_active) ON public.quiz_sessions FROM anon;
        REVOKE SELECT ON public.questions FROM anon;
        REVOKE INSERT (session_id, student_id) ON public.quiz_participants FROM anon;
        DROP POLICY mathverse_vr_legacy_read_allow ON public.quiz_sessions;
        DROP POLICY mathverse_vr_legacy_read_boundary ON public.quiz_sessions;
        DROP POLICY mathverse_vr_legacy_read_allow ON public.questions;
        DROP POLICY mathverse_vr_legacy_read_boundary ON public.questions;
        DROP POLICY mathverse_vr_legacy_join_allow ON public.quiz_participants;
        DROP POLICY mathverse_vr_legacy_join_boundary ON public.quiz_participants;
        RAISE NOTICE 'Legacy VR grants and policies removed. Original policies and participant rows remain.';
        RETURN;
    ELSIF operation = 'DISABLE' THEN
        RAISE NOTICE 'Patch is not installed; nothing changed.';
        RETURN;
    END IF;

    -- A closed anon baseline makes DISABLE reversible without removing old grants.
    IF EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relname = ANY(target_tables)
        AND (has_table_privilege('anon', c.oid, 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
            OR has_any_column_privilege('anon', c.oid, 'SELECT,INSERT,UPDATE,REFERENCES'))) THEN
        RAISE EXCEPTION 'Anon already has VR table/column privileges. Review those first so this patch does not overwrite your previous access configuration.';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public'
        AND c.relname = ANY(target_tables) AND NOT p.polpermissive
        AND (p.polcmd = '*' OR (c.relname = 'quiz_participants' AND p.polcmd = 'a')
            OR (c.relname <> 'quiz_participants' AND p.polcmd = 'r'))
        AND EXISTS (SELECT 1 FROM unnest(p.polroles) AS r(role_id)
            WHERE CASE WHEN r.role_id = 0 THEN true ELSE pg_has_role('anon', r.role_id, 'USAGE') END)) THEN
        RAISE EXCEPTION 'An existing restrictive policy applies to anon VR access. Send its definition for review; this patch will not remove it.';
    END IF;

    -- Paired boundaries stop old permissive PUBLIC/anon policies from opening
    -- completed/inactive rooms or non-session quest questions inadvertently.
    CREATE POLICY mathverse_vr_legacy_read_allow ON public.quiz_sessions AS PERMISSIVE
        FOR SELECT TO anon USING (status IN ('waiting', 'active') AND is_active IS TRUE);
    CREATE POLICY mathverse_vr_legacy_read_boundary ON public.quiz_sessions AS RESTRICTIVE
        FOR SELECT TO anon USING (status IN ('waiting', 'active') AND is_active IS TRUE);
    CREATE POLICY mathverse_vr_legacy_read_allow ON public.questions AS PERMISSIVE
        FOR SELECT TO anon USING (deleted_at IS NULL AND EXISTS (
            SELECT 1 FROM public.quiz_sessions s WHERE s.id = questions.session_id));
    CREATE POLICY mathverse_vr_legacy_read_boundary ON public.questions AS RESTRICTIVE
        FOR SELECT TO anon USING (deleted_at IS NULL AND EXISTS (
            SELECT 1 FROM public.quiz_sessions s WHERE s.id = questions.session_id));
    CREATE POLICY mathverse_vr_legacy_join_allow ON public.quiz_participants AS PERMISSIVE
        FOR INSERT TO anon WITH CHECK (EXISTS (
            SELECT 1 FROM public.quiz_sessions s WHERE s.id = quiz_participants.session_id));
    CREATE POLICY mathverse_vr_legacy_join_boundary ON public.quiz_participants AS RESTRICTIVE
        FOR INSERT TO anon WITH CHECK (EXISTS (
            SELECT 1 FROM public.quiz_sessions s WHERE s.id = quiz_participants.session_id));

    GRANT SELECT (id, room_code, status, time_limit, is_active) ON public.quiz_sessions TO anon;
    GRANT SELECT ON public.questions TO anon;
    GRANT INSERT (session_id, student_id) ON public.quiz_participants TO anon;

    FOR policy_record IN SELECT p.*, c.relname FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public'
        AND c.relname = ANY(target_tables) AND p.polname = ANY(own_policies)
    LOOP
        EXECUTE format('COMMENT ON POLICY %I ON public.%I IS %L', policy_record.polname, policy_record.relname,
            marker || ':' || md5(to_jsonb(policy_record)::text));
    END LOOP;
    RAISE NOTICE 'Legacy anon VR room lookup, status polling, question fetch and participant registration enabled. Accounts/profiles/results/stats remain protected.';
END
$vr_access$;

-- Non-mutating permission/planning checks run as anon only when enabling.
-- Unexpected older policies that reference a protected table fail here and
-- roll back the whole patch. There is no data deletion/test participant insert.
DO $check_access$
BEGIN
    IF current_setting('mathverse.legacy_vr_operation', true) = 'ENABLE' THEN
        EXECUTE 'SET LOCAL ROLE anon';
        EXECUTE 'SELECT id, room_code, status, time_limit, is_active FROM public.quiz_sessions LIMIT 0';
        EXECUTE 'SELECT * FROM public.questions LIMIT 0';
        EXECUTE 'RESET ROLE';
    END IF;
END
$check_access$;

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_09_20_vr_legacy_access.sql')
ON CONFLICT (migration_key) DO NOTHING;

COMMIT;

SELECT
    (SELECT count(*) = 6 FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relname IN ('quiz_sessions','questions','quiz_participants')
            AND p.polname IN ('mathverse_vr_legacy_read_allow','mathverse_vr_legacy_read_boundary',
                'mathverse_vr_legacy_join_allow','mathverse_vr_legacy_join_boundary')) AS vr_patch_installed,
    (has_column_privilege('anon','public.quiz_sessions','id','SELECT')
        AND has_column_privilege('anon','public.quiz_sessions','room_code','SELECT')
        AND has_column_privilege('anon','public.quiz_sessions','status','SELECT')
        AND has_column_privilege('anon','public.quiz_sessions','time_limit','SELECT')
        AND has_column_privilege('anon','public.quiz_sessions','is_active','SELECT')) AS room_lookup_and_status,
    has_table_privilege('anon','public.questions','SELECT') AS question_fetch,
    (has_column_privilege('anon','public.quiz_participants','session_id','INSERT')
        AND has_column_privilege('anon','public.quiz_participants','student_id','INSERT')) AS participant_registration;
