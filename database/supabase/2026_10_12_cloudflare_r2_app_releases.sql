-- Add Cloudflare R2 as the blob provider for new Unity application releases.
-- Existing Supabase Storage rows and RPCs remain intact for older releases.
-- Laravel must verify R2 objects through the private R2 API before calling the
-- v2 finalize or activation RPCs in this migration.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.profiles') IS NULL
       OR to_regclass('public.unity_app_releases') IS NULL THEN
        RAISE EXCEPTION 'The MathVerse Unity release schema is missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_10_11_versioned_unity_app_releases.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_10_11_versioned_unity_app_releases.sql first.';
    END IF;
END
$preflight$;

-- The provider defaults to Supabase so the existing begin_unity_app_release
-- routine keeps creating legacy-compatible rows. Only the v2 begin routine
-- below creates R2 rows. The multipart upload ID is an opaque R2 identifier,
-- not an access key or other credential. It remains in this server-only table
-- after a failure until external abort/delete cleanup is confirmed.
ALTER TABLE public.unity_app_releases
    ADD COLUMN IF NOT EXISTS storage_provider text NOT NULL DEFAULT 'supabase',
    ADD COLUMN IF NOT EXISTS provider_upload_id text,
    ADD COLUMN IF NOT EXISTS object_etag text,
    ADD COLUMN IF NOT EXISTS multipart_part_size bigint;

UPDATE public.unity_app_releases
SET storage_provider = 'supabase'
WHERE storage_provider IS NULL;

-- Supports a safe rerun if an earlier draft of this migration created R2 rows
-- before multipart size was persisted. That draft always defaulted to 16 MiB.
UPDATE public.unity_app_releases
SET multipart_part_size = 16777216
WHERE storage_provider = 'r2'
  AND multipart_part_size IS NULL;

ALTER TABLE public.unity_app_releases
    ALTER COLUMN storage_provider SET DEFAULT 'supabase',
    ALTER COLUMN storage_provider SET NOT NULL,
    DROP CONSTRAINT IF EXISTS unity_app_releases_storage_bucket_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_storage_path_key,
    DROP CONSTRAINT IF EXISTS unity_app_releases_storage_provider_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_bucket_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_upload_id_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_object_etag_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_multipart_part_size_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_state_check,
    DROP CONSTRAINT IF EXISTS unity_app_releases_provider_bucket_path_key;

ALTER TABLE public.unity_app_releases
    ADD CONSTRAINT unity_app_releases_storage_provider_check
        CHECK (storage_provider IN ('supabase', 'r2')),
    ADD CONSTRAINT unity_app_releases_provider_bucket_check CHECK (
        (storage_provider = 'supabase' AND storage_bucket = 'unity-releases')
        OR
        (storage_provider = 'r2'
            AND char_length(storage_bucket) BETWEEN 3 AND 63
            AND storage_bucket ~ '^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$')
    ),
    ADD CONSTRAINT unity_app_releases_provider_upload_id_check CHECK (
        provider_upload_id IS NULL
        OR (
            storage_provider = 'r2'
            AND status IN ('uploading', 'failed')
            AND char_length(provider_upload_id) BETWEEN 1 AND 2048
            AND provider_upload_id !~ '[[:cntrl:]]'
        )
    ),
    ADD CONSTRAINT unity_app_releases_object_etag_check CHECK (
        object_etag IS NULL
        OR (
            char_length(object_etag) BETWEEN 1 AND 512
            AND object_etag !~ '[[:cntrl:]]'
        )
    ),
    ADD CONSTRAINT unity_app_releases_multipart_part_size_check CHECK (
        (storage_provider = 'supabase' AND multipart_part_size IS NULL)
        OR
        (storage_provider = 'r2'
            AND multipart_part_size BETWEEN 5242880 AND 536870912)
    ),
    ADD CONSTRAINT unity_app_releases_provider_state_check CHECK (
        (storage_provider = 'supabase'
            AND provider_upload_id IS NULL
            AND object_etag IS NULL)
        OR
        (storage_provider = 'r2' AND (
            (status = 'uploading' AND object_etag IS NULL)
            OR
            (status = 'ready'
                AND provider_upload_id IS NULL
                AND object_etag IS NOT NULL)
            OR
            (status = 'failed'
                AND object_etag IS NULL)
        ))
    ),
    ADD CONSTRAINT unity_app_releases_provider_bucket_path_key
        UNIQUE (storage_provider, storage_bucket, storage_path);

ALTER TABLE public.unity_app_releases ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.unity_app_releases FORCE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON TABLE public.unity_app_releases
    FROM PUBLIC, anon, authenticated, service_role;
GRANT SELECT ON TABLE public.unity_app_releases TO service_role;

DROP FUNCTION IF EXISTS public.begin_unity_app_release_v2(
    uuid, text, text, text, text, bigint, text, text, text
);

CREATE OR REPLACE FUNCTION public.begin_unity_app_release_v2(
    p_admin_id uuid,
    p_version text,
    p_platform text,
    p_artifact_kind text,
    p_original_filename text,
    p_expected_size_bytes bigint,
    p_release_notes text,
    p_mime_type text,
    p_multipart_part_size bigint,
    p_storage_bucket text
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_id uuid := gen_random_uuid();
    normalized_version text := btrim(p_version);
    normalized_platform text := lower(btrim(p_platform));
    normalized_kind text := lower(btrim(p_artifact_kind));
    normalized_filename text := btrim(p_original_filename);
    normalized_notes text := nullif(btrim(p_release_notes), '');
    normalized_mime text := nullif(lower(btrim(p_mime_type)), '');
    normalized_bucket text := lower(btrim(p_storage_bucket));
    release_row public.unity_app_releases%ROWTYPE;
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_admin_id
          AND role = 'admin'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to create a Unity release.';
    END IF;

    IF normalized_version IS NULL
       OR normalized_version !~ '^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$' THEN
        RAISE EXCEPTION 'The release version is invalid.';
    END IF;

    IF normalized_platform NOT IN ('android', 'ios')
       OR normalized_kind NOT IN ('apk', 'ipa')
       OR (normalized_platform = 'android' AND normalized_kind <> 'apk')
       OR (normalized_platform = 'ios' AND normalized_kind <> 'ipa') THEN
        RAISE EXCEPTION 'The release platform and artifact type do not match.';
    END IF;

    IF normalized_filename IS NULL
       OR char_length(normalized_filename) NOT BETWEEN 5 AND 220
       OR normalized_filename ~ '[/\\]'
       OR normalized_filename ~ '[[:cntrl:]]'
       OR (normalized_kind = 'apk' AND lower(normalized_filename) !~ '\.apk$')
       OR (normalized_kind = 'ipa' AND lower(normalized_filename) !~ '\.ipa$') THEN
        RAISE EXCEPTION 'The release file name is invalid.';
    END IF;

    IF p_expected_size_bytes IS NULL
       OR p_expected_size_bytes NOT BETWEEN 1 AND 5368709120 THEN
        RAISE EXCEPTION 'The expected file size is invalid.';
    END IF;

    IF p_multipart_part_size IS NULL
       OR p_multipart_part_size NOT BETWEEN 5242880 AND 536870912 THEN
        RAISE EXCEPTION 'The R2 multipart part size is invalid.';
    END IF;

    IF normalized_bucket IS NULL
       OR char_length(normalized_bucket) NOT BETWEEN 3 AND 63
       OR normalized_bucket !~ '^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$' THEN
        RAISE EXCEPTION 'The R2 bucket name is invalid.';
    END IF;

    normalized_mime := coalesce(
        normalized_mime,
        CASE normalized_kind
            WHEN 'apk' THEN 'application/vnd.android.package-archive'
            ELSE 'application/x-itunes-ipa'
        END
    );

    IF normalized_mime NOT IN (
        'application/octet-stream',
        'application/vnd.android.package-archive',
        'application/x-itunes-ipa'
    ) OR (
        normalized_kind = 'apk'
        AND normalized_mime NOT IN (
            'application/octet-stream',
            'application/vnd.android.package-archive'
        )
    ) OR (
        normalized_kind = 'ipa'
        AND normalized_mime NOT IN (
            'application/octet-stream',
            'application/x-itunes-ipa'
        )
    ) THEN
        RAISE EXCEPTION 'The release content type is invalid.';
    END IF;

    IF normalized_notes IS NOT NULL AND char_length(normalized_notes) > 10000 THEN
        RAISE EXCEPTION 'Release notes cannot exceed 10000 characters.';
    END IF;

    INSERT INTO public.unity_app_releases (
        id,
        version,
        platform,
        artifact_kind,
        storage_provider,
        storage_bucket,
        storage_path,
        download_name,
        expected_size_bytes,
        multipart_part_size,
        mime_type,
        release_notes,
        uploaded_by
    ) VALUES (
        release_id,
        normalized_version,
        normalized_platform,
        normalized_kind,
        'r2',
        normalized_bucket,
        'releases/' || normalized_platform || '/' || release_id::text
            || '/mathverse-' || lower(normalized_version) || '.' || normalized_kind,
        normalized_filename,
        p_expected_size_bytes,
        p_multipart_part_size,
        normalized_mime,
        normalized_notes,
        p_admin_id
    )
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.attach_unity_app_release_upload_v2(
    p_release_id uuid,
    p_admin_id uuid,
    p_upload_id text
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_row public.unity_app_releases%ROWTYPE;
    normalized_upload_id text := nullif(btrim(p_upload_id), '');
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_admin_id
          AND role = 'admin'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to attach a Unity upload.';
    END IF;

    IF normalized_upload_id IS NULL
       OR char_length(normalized_upload_id) > 2048
       OR normalized_upload_id ~ '[[:cntrl:]]' THEN
        RAISE EXCEPTION 'The R2 multipart upload identifier is invalid.';
    END IF;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.storage_provider <> 'r2'
       OR release_row.status <> 'uploading' THEN
        RAISE EXCEPTION 'Only an uploading R2 release can accept a multipart upload.';
    ELSIF release_row.provider_upload_id IS NOT NULL THEN
        IF release_row.provider_upload_id <> normalized_upload_id THEN
            RAISE EXCEPTION 'A different multipart upload is already attached to this release.';
        END IF;
        RETURN release_row;
    END IF;

    UPDATE public.unity_app_releases
    SET provider_upload_id = normalized_upload_id,
        updated_at = clock_timestamp()
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.finalize_unity_app_release_v2(
    p_release_id uuid,
    p_admin_id uuid,
    p_actual_size_bytes bigint,
    p_object_etag text,
    p_sha256 text DEFAULT NULL
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_row public.unity_app_releases%ROWTYPE;
    normalized_etag text := nullif(btrim(p_object_etag), '');
    normalized_sha256 text := nullif(lower(btrim(p_sha256)), '');
    completed_time timestamp with time zone := clock_timestamp();
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_admin_id
          AND role = 'admin'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to finalize a Unity release.';
    END IF;

    IF p_actual_size_bytes IS NULL
       OR p_actual_size_bytes NOT BETWEEN 1 AND 5368709120 THEN
        RAISE EXCEPTION 'The verified uploaded file size is invalid.';
    END IF;

    IF normalized_etag IS NULL
       OR char_length(normalized_etag) > 512
       OR normalized_etag ~ '[[:cntrl:]]' THEN
        RAISE EXCEPTION 'The verified R2 object ETag is invalid.';
    END IF;

    IF normalized_sha256 IS NOT NULL
       AND normalized_sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'The SHA-256 value is invalid.';
    END IF;

    LOCK TABLE ONLY public.unity_app_releases IN SHARE ROW EXCLUSIVE MODE;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.storage_provider <> 'r2' THEN
        RAISE EXCEPTION 'The v2 finalization RPC only accepts R2 releases.';
    END IF;

    IF release_row.status = 'ready' THEN
        IF release_row.size_bytes <> p_actual_size_bytes
           OR release_row.object_etag <> normalized_etag
           OR release_row.sha256 IS DISTINCT FROM normalized_sha256 THEN
            RAISE EXCEPTION 'The completed Unity release does not match this verification.';
        END IF;
        RETURN release_row;
    ELSIF release_row.status <> 'uploading' THEN
        RAISE EXCEPTION 'Only an uploading Unity release can be finalized.';
    ELSIF release_row.provider_upload_id IS NULL THEN
        RAISE EXCEPTION 'The R2 multipart upload is not attached to this release.';
    ELSIF p_actual_size_bytes <> release_row.expected_size_bytes THEN
        RAISE EXCEPTION 'The verified R2 object size does not match the release.';
    END IF;

    -- Laravel reaches this server-only RPC only after completing the multipart
    -- upload and verifying the exact key, byte size, ETag, and release metadata
    -- through R2 HeadObject. No browser can assert these values directly.
    UPDATE public.unity_app_releases
    SET is_active = false,
        updated_at = completed_time
    WHERE is_active
      AND platform = release_row.platform;

    UPDATE public.unity_app_releases
    SET status = 'ready',
        is_active = true,
        provider_upload_id = NULL,
        object_etag = normalized_etag,
        size_bytes = p_actual_size_bytes,
        sha256 = normalized_sha256,
        completed_by = p_admin_id,
        activated_by = p_admin_id,
        completed_at = completed_time,
        activated_at = completed_time,
        failure_reason = NULL,
        updated_at = completed_time
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.fail_unity_app_release_v2(
    p_release_id uuid,
    p_admin_id uuid,
    p_failure_reason text
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_row public.unity_app_releases%ROWTYPE;
    normalized_reason text := nullif(btrim(p_failure_reason), '');
    completed_time timestamp with time zone := clock_timestamp();
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_admin_id
          AND role = 'admin'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to fail a Unity release.';
    END IF;

    IF normalized_reason IS NULL OR char_length(normalized_reason) > 1000 THEN
        RAISE EXCEPTION 'A valid upload failure reason is required.';
    END IF;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.storage_provider <> 'r2' THEN
        RAISE EXCEPTION 'The v2 failure RPC only accepts R2 releases.';
    ELSIF release_row.status = 'failed' THEN
        RETURN release_row;
    ELSIF release_row.status <> 'uploading' THEN
        RAISE EXCEPTION 'Only an uploading Unity release can be marked failed.';
    END IF;

    UPDATE public.unity_app_releases
    SET status = 'failed',
        is_active = false,
        object_etag = NULL,
        completed_by = p_admin_id,
        completed_at = completed_time,
        failure_reason = normalized_reason,
        updated_at = completed_time
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.finish_unity_app_release_cleanup_v2(
    p_release_id uuid,
    p_admin_id uuid,
    p_upload_id text
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_row public.unity_app_releases%ROWTYPE;
    normalized_upload_id text := nullif(btrim(p_upload_id), '');
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_admin_id
          AND role = 'admin'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to finish Unity release cleanup.';
    END IF;

    IF normalized_upload_id IS NOT NULL
       AND (
           char_length(normalized_upload_id) > 2048
           OR normalized_upload_id ~ '[[:cntrl:]]'
       ) THEN
        RAISE EXCEPTION 'The R2 multipart upload identifier is invalid.';
    END IF;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.storage_provider <> 'r2'
       OR release_row.status <> 'failed' THEN
        RAISE EXCEPTION 'Only a failed R2 release can finish storage cleanup.';
    ELSIF release_row.provider_upload_id IS NULL THEN
        RETURN release_row;
    ELSIF normalized_upload_id IS NULL
       OR release_row.provider_upload_id <> normalized_upload_id THEN
        RAISE EXCEPTION 'The multipart upload identifier no longer matches this failed release.';
    END IF;

    UPDATE public.unity_app_releases
    SET provider_upload_id = NULL,
        updated_at = clock_timestamp()
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.activate_unity_app_release_v2(
    p_release_id uuid,
    p_admin_id uuid,
    p_verified_size_bytes bigint,
    p_object_etag text
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_row public.unity_app_releases%ROWTYPE;
    normalized_etag text := nullif(btrim(p_object_etag), '');
    activated_time timestamp with time zone := clock_timestamp();
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_admin_id
          AND role = 'admin'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to activate a Unity release.';
    END IF;

    IF p_verified_size_bytes IS NULL
       OR p_verified_size_bytes NOT BETWEEN 1 AND 5368709120 THEN
        RAISE EXCEPTION 'The verified R2 object size is invalid.';
    END IF;

    IF normalized_etag IS NULL
       OR char_length(normalized_etag) > 512
       OR normalized_etag ~ '[[:cntrl:]]' THEN
        RAISE EXCEPTION 'The verified R2 object ETag is invalid.';
    END IF;

    LOCK TABLE ONLY public.unity_app_releases IN SHARE ROW EXCLUSIVE MODE;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.storage_provider <> 'r2' THEN
        RAISE EXCEPTION 'The v2 activation RPC only accepts R2 releases.';
    ELSIF release_row.status <> 'ready' THEN
        RAISE EXCEPTION 'Only a ready Unity release can be activated.';
    ELSIF p_verified_size_bytes <> release_row.size_bytes
       OR p_verified_size_bytes <> release_row.expected_size_bytes
       OR normalized_etag <> release_row.object_etag THEN
        RAISE EXCEPTION 'The verified R2 object no longer matches its ready release.';
    END IF;

    IF release_row.is_active THEN
        RETURN release_row;
    END IF;

    UPDATE public.unity_app_releases
    SET is_active = false,
        updated_at = activated_time
    WHERE is_active
      AND platform = release_row.platform;

    UPDATE public.unity_app_releases
    SET is_active = true,
        activated_by = p_admin_id,
        activated_at = activated_time,
        updated_at = activated_time
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

REVOKE ALL ON FUNCTION public.begin_unity_app_release_v2(
    uuid, text, text, text, text, bigint, text, text, bigint, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.attach_unity_app_release_upload_v2(
    uuid, uuid, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.finalize_unity_app_release_v2(
    uuid, uuid, bigint, text, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.fail_unity_app_release_v2(
    uuid, uuid, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.finish_unity_app_release_cleanup_v2(
    uuid, uuid, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.activate_unity_app_release_v2(
    uuid, uuid, bigint, text
) FROM PUBLIC, anon, authenticated, service_role;

GRANT EXECUTE ON FUNCTION public.begin_unity_app_release_v2(
    uuid, text, text, text, text, bigint, text, text, bigint, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.attach_unity_app_release_upload_v2(
    uuid, uuid, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.finalize_unity_app_release_v2(
    uuid, uuid, bigint, text, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.fail_unity_app_release_v2(
    uuid, uuid, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.finish_unity_app_release_cleanup_v2(
    uuid, uuid, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.activate_unity_app_release_v2(
    uuid, uuid, bigint, text
) TO service_role;

COMMENT ON COLUMN public.unity_app_releases.storage_provider IS
    'Blob provider for this artifact. Existing rows remain on private Supabase Storage; new v2 uploads use private Cloudflare R2.';
COMMENT ON COLUMN public.unity_app_releases.provider_upload_id IS
    'Opaque R2 multipart upload identifier used only by the server while uploading and retained after failure until confirmed cleanup; never stores a credential.';
COMMENT ON COLUMN public.unity_app_releases.object_etag IS
    'Provider object ETag recorded after Laravel verifies the completed private object.';
COMMENT ON COLUMN public.unity_app_releases.multipart_part_size IS
    'Multipart part size chosen when an R2 release begins; persisted so rolling deployments can safely resume and finalize it.';
COMMENT ON FUNCTION public.finalize_unity_app_release_v2(uuid, uuid, bigint, text, text) IS
    'Records server-verified R2 size and ETag values and atomically activates the completed release for its platform.';
COMMENT ON FUNCTION public.activate_unity_app_release_v2(uuid, uuid, bigint, text) IS
    'Reactivates a retained R2 release only when Laravel supplies its freshly verified size and ETag.';
COMMENT ON FUNCTION public.finish_unity_app_release_cleanup_v2(uuid, uuid, text) IS
    'Clears a failed release multipart ID only after Laravel confirms that R2 abort/delete cleanup completed.';

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_10_12_cloudflare_r2_app_releases.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    NOT has_table_privilege('anon', 'public.unity_app_releases', 'SELECT')
        AS anonymous_release_metadata_closed,
    NOT has_table_privilege('authenticated', 'public.unity_app_releases', 'SELECT')
        AS authenticated_release_metadata_closed,
    has_function_privilege(
        'service_role',
        'public.finalize_unity_app_release_v2(uuid,uuid,bigint,text,text)',
        'EXECUTE'
    ) AS server_r2_finalize_rpc_ready,
    has_function_privilege(
        'service_role',
        'public.finish_unity_app_release_cleanup_v2(uuid,uuid,text)',
        'EXECUTE'
    ) AS server_r2_cleanup_rpc_ready,
    NOT has_function_privilege(
        'authenticated',
        'public.finish_unity_app_release_cleanup_v2(uuid,uuid,text)',
        'EXECUTE'
    ) AS browser_r2_cleanup_closed;
