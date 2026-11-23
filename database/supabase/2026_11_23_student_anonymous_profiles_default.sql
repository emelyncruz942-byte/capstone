-- Students start with anonymous peer-facing profiles. They may opt out from
-- Profile later; replaying this migration must not overwrite that choice.
BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.profiles') IS NULL
       OR to_regclass('public.mathverse_schema_migrations') IS NULL THEN
        RAISE EXCEPTION 'The MathVerse profile schema and migration ledger are required.';
    END IF;
END
$preflight$;

-- The Auth profile-creation trigger omits this field, so this also covers
-- signups from Unity without trusting a user-editable metadata preference.
ALTER TABLE public.profiles
    ALTER COLUMN show_on_leaderboard SET DEFAULT false;

-- Claim the one-time backfill atomically. A later replay still restores the
-- default but leaves students' subsequent explicit opt-out choices alone.
WITH first_application AS (
    INSERT INTO public.mathverse_schema_migrations (migration_key)
    VALUES ('20261123114432_student_anonymous_profiles_default.sql')
    ON CONFLICT (migration_key) DO NOTHING
    RETURNING migration_key
)
UPDATE public.profiles
SET show_on_leaderboard = false
WHERE role = 'student'
  AND show_on_leaderboard IS DISTINCT FROM false
  AND EXISTS (SELECT 1 FROM first_application);

COMMIT;
