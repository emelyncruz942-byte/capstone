-- Roll back Cloudflare R2 release support while retaining R2 release metadata
-- in a runtime-inaccessible archive. Any provider_upload_id still present is
-- durable evidence of unfinished external cleanup. Objects in R2 are never
-- deleted here.

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
END
$preflight$;

CREATE TABLE IF NOT EXISTS public.rollback_cloudflare_r2_unity_releases_20261012 (
    id uuid PRIMARY KEY,
    version text NOT NULL,
    platform text NOT NULL,
    artifact_kind text NOT NULL,
    storage_provider text NOT NULL,
    storage_bucket text NOT NULL,
    storage_path text NOT NULL,
    provider_upload_id text,
    object_etag text,
    multipart_part_size bigint,
    download_name text NOT NULL,
    expected_size_bytes bigint NOT NULL,
    size_bytes bigint,
    mime_type text NOT NULL,
    sha256 text,
    release_notes text,
    status text NOT NULL,
    is_active boolean NOT NULL,
    uploaded_by uuid NOT NULL,
    completed_by uuid,
    activated_by uuid,
    failure_reason text,
    created_at timestamp with time zone NOT NULL,
    updated_at timestamp with time zone NOT NULL,
    completed_at timestamp with time zone,
    activated_at timestamp with time zone,
    archived_at timestamp with time zone NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE public.rollback_cloudflare_r2_unity_releases_20261012
    ADD COLUMN IF NOT EXISTS multipart_part_size bigint;
ALTER TABLE public.rollback_cloudflare_r2_unity_releases_20261012
    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rollback_cloudflare_r2_unity_releases_20261012
    FORCE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES
    ON TABLE public.rollback_cloudflare_r2_unity_releases_20261012
    FROM PUBLIC, anon, authenticated, service_role;

INSERT INTO public.rollback_cloudflare_r2_unity_releases_20261012 (
    id,
    version,
    platform,
    artifact_kind,
    storage_provider,
    storage_bucket,
    storage_path,
    provider_upload_id,
    object_etag,
    multipart_part_size,
    download_name,
    expected_size_bytes,
    size_bytes,
    mime_type,
    sha256,
    release_notes,
    status,
    is_active,
    uploaded_by,
    completed_by,
    activated_by,
    failure_reason,
    created_at,
    updated_at,
    completed_at,
    activated_at,
    archived_at
)
SELECT
    id,
    version,
    platform,
    artifact_kind,
    storage_provider,
    storage_bucket,
    storage_path,
    provider_upload_id,
    object_etag,
    multipart_part_size,
    download_name,
    expected_size_bytes,
    size_bytes,
    mime_type,
    sha256,
    release_notes,
    status,
    is_active,
    uploaded_by,
    completed_by,
    activated_by,
    failure_reason,
    created_at,
    updated_at,
    completed_at,
    activated_at,
    clock_timestamp()
FROM public.unity_app_releases
WHERE storage_provider = 'r2'
ON CONFLICT (id) DO UPDATE
SET version = EXCLUDED.version,
    platform = EXCLUDED.platform,
    artifact_kind = EXCLUDED.artifact_kind,
    storage_provider = EXCLUDED.storage_provider,
    storage_bucket = EXCLUDED.storage_bucket,
    storage_path = EXCLUDED.storage_path,
    provider_upload_id = EXCLUDED.provider_upload_id,
    object_etag = EXCLUDED.object_etag,
    multipart_part_size = EXCLUDED.multipart_part_size,
    download_name = EXCLUDED.download_name,
    expected_size_bytes = EXCLUDED.expected_size_bytes,
    size_bytes = EXCLUDED.size_bytes,
    mime_type = EXCLUDED.mime_type,
    sha256 = EXCLUDED.sha256,
    release_notes = EXCLUDED.release_notes,
    status = EXCLUDED.status,
    is_active = EXCLUDED.is_active,
    uploaded_by = EXCLUDED.uploaded_by,
    completed_by = EXCLUDED.completed_by,
    activated_by = EXCLUDED.activated_by,
    failure_reason = EXCLUDED.failure_reason,
    created_at = EXCLUDED.created_at,
    updated_at = EXCLUDED.updated_at,
    completed_at = EXCLUDED.completed_at,
    activated_at = EXCLUDED.activated_at,
    archived_at = EXCLUDED.archived_at;

-- Remove only R2-backed rows before restoring the original Supabase-only
-- constraints. The archive above preserves their metadata, while the private
-- objects and any pending multipart uploads remain in R2 for a reviewed
-- recovery or later cleanup.
DELETE FROM public.unity_app_releases
WHERE storage_provider = 'r2';

DROP FUNCTION IF EXISTS public.begin_unity_app_release_v2(
    uuid, text, text, text, text, bigint, text, text, bigint, text
);
DROP FUNCTION IF EXISTS public.begin_unity_app_release_v2(
    uuid, text, text, text, text, bigint, text, text, text
);
DROP FUNCTION IF EXISTS public.attach_unity_app_release_upload_v2(
    uuid, uuid, text
);
DROP FUNCTION IF EXISTS public.finalize_unity_app_release_v2(
    uuid, uuid, bigint, text, text
);
DROP FUNCTION IF EXISTS public.fail_unity_app_release_v2(
    uuid, uuid, text
);
DROP FUNCTION IF EXISTS public.finish_unity_app_release_cleanup_v2(
    uuid, uuid, text
);
DROP FUNCTION IF EXISTS public.activate_unity_app_release_v2(
    uuid, uuid, bigint, text
);

ALTER TABLE public.unity_app_releases
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_bucket_path_key,
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_state_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_object_etag_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_multipart_part_size_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_upload_id_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_bucket_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_storage_provider_check,
    DROP COLUMN IF EXISTS provider_upload_id,
    DROP COLUMN IF EXISTS object_etag,
    DROP COLUMN IF EXISTS multipart_part_size,
    DROP COLUMN IF EXISTS storage_provider;

DO $restore_constraints$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.unity_app_releases'::regclass
          AND conname = 'unity_app_releases_storage_bucket_check'
    ) THEN
        ALTER TABLE public.unity_app_releases
            ADD CONSTRAINT unity_app_releases_storage_bucket_check
            CHECK (storage_bucket = 'unity-releases');
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'public.unity_app_releases'::regclass
          AND conname = 'unity_app_releases_storage_path_key'
    ) THEN
        ALTER TABLE public.unity_app_releases
            ADD CONSTRAINT unity_app_releases_storage_path_key
            UNIQUE (storage_path);
    END IF;
END
$restore_constraints$;

DELETE FROM public.mathverse_schema_migrations
WHERE migration_key = '2026_10_12_cloudflare_r2_app_releases.sql';

NOTIFY pgrst, 'reload schema';
COMMIT;
