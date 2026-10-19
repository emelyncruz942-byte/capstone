-- Remove retry-safe Unity release deletion support.
-- Refuse rollback while a release is pending deletion because its provider
-- object may already have been removed even though its row still exists.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.unity_app_releases') IS NULL THEN
        RAISE EXCEPTION 'The MathVerse Unity release schema is missing.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.unity_app_releases
        WHERE deletion_requested_at IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'Finish or manually reconcile pending Unity release deletions before rollback.';
    END IF;
END
$preflight$;

DROP TRIGGER IF EXISTS guard_unity_app_release_pending_deletion
    ON public.unity_app_releases;
DROP FUNCTION IF EXISTS public.guard_unity_app_release_pending_deletion();
DROP FUNCTION IF EXISTS public.finish_unity_app_release_deletion(
    uuid, uuid, uuid, text, text, text, text
);
DROP FUNCTION IF EXISTS public.begin_unity_app_release_deletion(uuid, uuid);

ALTER TABLE public.unity_app_releases
    DROP CONSTRAINT IF EXISTS unity_app_releases_deletion_state_check,
    DROP COLUMN IF EXISTS deletion_token,
    DROP COLUMN IF EXISTS deletion_requested_by,
    DROP COLUMN IF EXISTS deletion_requested_at;

DELETE FROM public.mathverse_schema_migrations
WHERE migration_key = '2026_10_19_deletable_unity_app_releases.sql';

NOTIFY pgrst, 'reload schema';
COMMIT;
