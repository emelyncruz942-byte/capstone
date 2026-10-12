-- Emergency rollback for 2026_10_11_versioned_unity_app_releases.sql.
-- Restore application code without release-management routes first. Metadata
-- is archived; Storage objects and their private access boundary are retained.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL THEN
        RAISE EXCEPTION 'The MathVerse migration registry is missing.';
    END IF;
END
$preflight$;

DO $archive_unity_releases$
BEGIN
    IF to_regclass('public.unity_app_releases') IS NOT NULL THEN
        CREATE TABLE IF NOT EXISTS public.rollback_unity_app_releases_20261011
            (LIKE public.unity_app_releases INCLUDING ALL);
        ALTER TABLE public.rollback_unity_app_releases_20261011
            ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.rollback_unity_app_releases_20261011
            FORCE ROW LEVEL SECURITY;
        REVOKE ALL PRIVILEGES
            ON TABLE public.rollback_unity_app_releases_20261011
            FROM PUBLIC, anon, authenticated, service_role;

        INSERT INTO public.rollback_unity_app_releases_20261011
        SELECT * FROM public.unity_app_releases
        ON CONFLICT (id) DO NOTHING;
    END IF;
END
$archive_unity_releases$;

DROP FUNCTION IF EXISTS public.activate_unity_app_release(uuid, uuid);
DROP FUNCTION IF EXISTS public.fail_unity_app_release(uuid, uuid, text);
DROP FUNCTION IF EXISTS public.finalize_unity_app_release(uuid, uuid, bigint, text);
DROP FUNCTION IF EXISTS public.begin_unity_app_release(
    uuid, text, text, text, text, bigint, text, text
);
DROP TABLE IF EXISTS public.unity_app_releases;

-- Do not delete rows directly from storage.objects: that can orphan billed
-- files. The private bucket and restrictive browser policies deliberately stay
-- in place. Remove retained objects with the Storage API before deleting the
-- bucket in a separate, reviewed cleanup.

DELETE FROM public.mathverse_schema_migrations
WHERE migration_key = '2026_10_11_versioned_unity_app_releases.sql';

NOTIFY pgrst, 'reload schema';
COMMIT;
