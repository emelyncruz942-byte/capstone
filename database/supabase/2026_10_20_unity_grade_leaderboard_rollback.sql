-- Remove the authenticated Unity grade leaderboard endpoint. Apply the
-- matching Unity client rollback before running this database rollback.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DROP FUNCTION IF EXISTS public.get_my_unity_trophy_leaderboard(integer);

DROP POLICY IF EXISTS mathverse_profiles_client_read_boundary ON public.profiles;
DROP POLICY IF EXISTS mathverse_profiles_self_read_allow ON public.profiles;

DELETE FROM public.mathverse_schema_migrations
WHERE migration_key = '2026_10_20_unity_grade_leaderboard.sql';

NOTIFY pgrst, 'reload schema';
COMMIT;
