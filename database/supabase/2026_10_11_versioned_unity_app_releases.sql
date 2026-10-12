-- Versioned Unity application artifacts stored in a private Supabase bucket.
-- Run after 2026_10_03_support_tickets.sql and deploy the matching Laravel
-- release-management pages only after this migration succeeds.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.profiles') IS NULL
       OR to_regclass('storage.buckets') IS NULL
       OR to_regclass('storage.objects') IS NULL THEN
        RAISE EXCEPTION 'Required MathVerse profile, migration, or Supabase Storage objects are missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_10_03_support_tickets.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_10_03_support_tickets.sql first.';
    END IF;
END
$preflight$;

-- The bucket remains private. Laravel uploads with the server-side service key
-- and creates short-lived signed URLs after applying application permissions.
INSERT INTO storage.buckets (
    id,
    name,
    public,
    file_size_limit,
    allowed_mime_types
) VALUES (
    'unity-releases',
    'unity-releases',
    false,
    5368709120,
    ARRAY[
        'application/octet-stream',
        'application/vnd.android.package-archive',
        'application/x-itunes-ipa'
    ]::text[]
)
ON CONFLICT (id) DO UPDATE
SET name = EXCLUDED.name,
    public = false,
    file_size_limit = EXCLUDED.file_size_limit,
    allowed_mime_types = EXCLUDED.allowed_mime_types;

-- Restrictive policies compose with any existing permissive Storage policy.
-- They prevent a broad legacy policy from accidentally exposing this bucket
-- while leaving every other bucket's established policies unchanged.
DROP POLICY IF EXISTS unity_releases_browser_object_boundary ON storage.objects;
CREATE POLICY unity_releases_browser_object_boundary
ON storage.objects
AS RESTRICTIVE
FOR ALL
TO anon, authenticated
USING (bucket_id <> 'unity-releases')
WITH CHECK (bucket_id <> 'unity-releases');

DROP POLICY IF EXISTS unity_releases_browser_bucket_boundary ON storage.buckets;
CREATE POLICY unity_releases_browser_bucket_boundary
ON storage.buckets
AS RESTRICTIVE
FOR ALL
TO anon, authenticated
USING (id <> 'unity-releases')
WITH CHECK (id <> 'unity-releases');

CREATE TABLE IF NOT EXISTS public.unity_app_releases (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    version text NOT NULL CHECK (
        char_length(version) BETWEEN 1 AND 64
        AND version ~ '^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$'
    ),
    platform text NOT NULL CHECK (platform IN ('android', 'ios')),
    artifact_kind text NOT NULL CHECK (artifact_kind IN ('apk', 'ipa')),
    storage_bucket text NOT NULL DEFAULT 'unity-releases'
        CHECK (storage_bucket = 'unity-releases'),
    storage_path text NOT NULL UNIQUE CHECK (
        char_length(storage_path) BETWEEN 1 AND 512
        AND storage_path ~ '^releases/(android|ios)/[0-9a-f-]{36}/[0-9a-z][0-9a-z._+-]*\.(apk|ipa)$'
        AND storage_path !~ '(^|/)\.\.(/|$)'
    ),
    download_name text NOT NULL CHECK (
        char_length(download_name) BETWEEN 5 AND 220
        AND download_name !~ '[/\\]'
        AND download_name !~ '[[:cntrl:]]'
    ),
    expected_size_bytes bigint NOT NULL CHECK (
        expected_size_bytes BETWEEN 1 AND 5368709120
    ),
    size_bytes bigint CHECK (size_bytes BETWEEN 1 AND 5368709120),
    mime_type text NOT NULL CHECK (mime_type IN (
        'application/octet-stream',
        'application/vnd.android.package-archive',
        'application/x-itunes-ipa'
    )),
    sha256 text CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    release_notes text CHECK (char_length(release_notes) BETWEEN 1 AND 10000),
    status text NOT NULL DEFAULT 'uploading'
        CHECK (status IN ('uploading', 'ready', 'failed')),
    is_active boolean NOT NULL DEFAULT false,
    uploaded_by uuid NOT NULL REFERENCES public.profiles(id) ON DELETE RESTRICT,
    completed_by uuid REFERENCES public.profiles(id) ON DELETE RESTRICT,
    activated_by uuid REFERENCES public.profiles(id) ON DELETE RESTRICT,
    failure_reason text CHECK (char_length(failure_reason) BETWEEN 1 AND 1000),
    created_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    completed_at timestamp with time zone,
    activated_at timestamp with time zone,
    CHECK (
        (platform = 'android' AND artifact_kind = 'apk')
        OR (platform = 'ios' AND artifact_kind = 'ipa')
    ),
    CHECK (NOT is_active OR status = 'ready'),
    CHECK (
        (status = 'uploading'
            AND size_bytes IS NULL
            AND completed_by IS NULL
            AND completed_at IS NULL
            AND failure_reason IS NULL
            AND NOT is_active)
        OR
        (status = 'ready'
            AND size_bytes = expected_size_bytes
            AND completed_by IS NOT NULL
            AND completed_at IS NOT NULL
            AND failure_reason IS NULL)
        OR
        (status = 'failed'
            AND size_bytes IS NULL
            AND completed_by IS NOT NULL
            AND completed_at IS NOT NULL
            AND failure_reason IS NOT NULL
            AND NOT is_active)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS unity_app_releases_version_platform_unique
    ON public.unity_app_releases (lower(version), platform)
    WHERE status <> 'failed';
DROP INDEX IF EXISTS public.unity_app_releases_one_active_unique;
CREATE UNIQUE INDEX IF NOT EXISTS unity_app_releases_one_active_per_platform_unique
    ON public.unity_app_releases (platform)
    WHERE is_active;
CREATE INDEX IF NOT EXISTS unity_app_releases_status_created_idx
    ON public.unity_app_releases (status, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS unity_app_releases_uploaded_by_idx
    ON public.unity_app_releases (uploaded_by);
CREATE INDEX IF NOT EXISTS unity_app_releases_completed_by_idx
    ON public.unity_app_releases (completed_by)
    WHERE completed_by IS NOT NULL;
CREATE INDEX IF NOT EXISTS unity_app_releases_activated_by_idx
    ON public.unity_app_releases (activated_by)
    WHERE activated_by IS NOT NULL;

ALTER TABLE public.unity_app_releases ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.unity_app_releases FORCE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON TABLE public.unity_app_releases
    FROM PUBLIC, anon, authenticated, service_role;
GRANT SELECT ON TABLE public.unity_app_releases TO service_role;

CREATE OR REPLACE FUNCTION public.begin_unity_app_release(
    p_admin_id uuid,
    p_version text,
    p_platform text,
    p_artifact_kind text,
    p_original_filename text,
    p_expected_size_bytes bigint,
    p_release_notes text DEFAULT NULL,
    p_mime_type text DEFAULT NULL
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
        storage_path,
        download_name,
        expected_size_bytes,
        mime_type,
        release_notes,
        uploaded_by
    ) VALUES (
        release_id,
        normalized_version,
        normalized_platform,
        normalized_kind,
        'releases/' || normalized_platform || '/' || release_id::text
            || '/mathverse-' || lower(normalized_version) || '.' || normalized_kind,
        normalized_filename,
        p_expected_size_bytes,
        normalized_mime,
        normalized_notes,
        p_admin_id
    )
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.finalize_unity_app_release(
    p_release_id uuid,
    p_admin_id uuid,
    p_actual_size_bytes bigint,
    p_sha256 text DEFAULT NULL
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_row public.unity_app_releases%ROWTYPE;
    stored_metadata jsonb;
    stored_size_bytes bigint;
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
        RAISE EXCEPTION 'The uploaded file size is invalid.';
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
    END IF;

    IF release_row.status = 'ready' THEN
        IF release_row.size_bytes <> p_actual_size_bytes
           OR release_row.sha256 IS DISTINCT FROM normalized_sha256 THEN
            RAISE EXCEPTION 'The completed Unity release does not match this request.';
        END IF;
        RETURN release_row;
    ELSIF release_row.status <> 'uploading' THEN
        RAISE EXCEPTION 'Only an uploading Unity release can be finalized.';
    END IF;

    SELECT object_row.metadata INTO stored_metadata
    FROM storage.objects AS object_row
    WHERE object_row.bucket_id = release_row.storage_bucket
      AND object_row.name = release_row.storage_path
    LIMIT 1;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'The uploaded Unity artifact was not found in private storage.';
    END IF;

    stored_size_bytes := CASE
        WHEN coalesce(stored_metadata ->> 'size', '') ~ '^[0-9]+$'
            THEN (stored_metadata ->> 'size')::bigint
        WHEN coalesce(stored_metadata ->> 'contentLength', '') ~ '^[0-9]+$'
            THEN (stored_metadata ->> 'contentLength')::bigint
        WHEN coalesce(stored_metadata ->> 'content-length', '') ~ '^[0-9]+$'
            THEN (stored_metadata ->> 'content-length')::bigint
        ELSE NULL
    END;

    IF stored_size_bytes IS NULL
       OR stored_size_bytes <> p_actual_size_bytes
       OR stored_size_bytes <> release_row.expected_size_bytes THEN
        RAISE EXCEPTION 'The uploaded Unity artifact size does not match the release.';
    END IF;

    UPDATE public.unity_app_releases
    SET is_active = false,
        updated_at = completed_time
    WHERE is_active
      AND platform = release_row.platform;

    UPDATE public.unity_app_releases
    SET status = 'ready',
        is_active = true,
        size_bytes = stored_size_bytes,
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

CREATE OR REPLACE FUNCTION public.fail_unity_app_release(
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
    END IF;

    IF release_row.status = 'failed' THEN
        RETURN release_row;
    ELSIF release_row.status <> 'uploading' THEN
        RAISE EXCEPTION 'Only an uploading Unity release can be marked failed.';
    END IF;

    UPDATE public.unity_app_releases
    SET status = 'failed',
        is_active = false,
        completed_by = p_admin_id,
        completed_at = completed_time,
        failure_reason = normalized_reason,
        updated_at = completed_time
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.activate_unity_app_release(
    p_release_id uuid,
    p_admin_id uuid
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    release_row public.unity_app_releases%ROWTYPE;
    stored_metadata jsonb;
    stored_size_bytes bigint;
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

    LOCK TABLE ONLY public.unity_app_releases IN SHARE ROW EXCLUSIVE MODE;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.status <> 'ready' THEN
        RAISE EXCEPTION 'Only a ready Unity release can be activated.';
    END IF;

    SELECT object_row.metadata INTO stored_metadata
    FROM storage.objects AS object_row
    WHERE object_row.bucket_id = release_row.storage_bucket
      AND object_row.name = release_row.storage_path
    LIMIT 1;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'The Unity artifact is missing from private storage.';
    END IF;

    stored_size_bytes := CASE
        WHEN coalesce(stored_metadata ->> 'size', '') ~ '^[0-9]+$'
            THEN (stored_metadata ->> 'size')::bigint
        WHEN coalesce(stored_metadata ->> 'contentLength', '') ~ '^[0-9]+$'
            THEN (stored_metadata ->> 'contentLength')::bigint
        WHEN coalesce(stored_metadata ->> 'content-length', '') ~ '^[0-9]+$'
            THEN (stored_metadata ->> 'content-length')::bigint
        ELSE NULL
    END;

    IF stored_size_bytes IS NULL
       OR stored_size_bytes <> release_row.size_bytes
       OR stored_size_bytes <> release_row.expected_size_bytes THEN
        RAISE EXCEPTION 'The Unity artifact no longer matches its ready release.';
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

REVOKE ALL ON FUNCTION public.begin_unity_app_release(
    uuid, text, text, text, text, bigint, text, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.finalize_unity_app_release(
    uuid, uuid, bigint, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.fail_unity_app_release(
    uuid, uuid, text
) FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.activate_unity_app_release(
    uuid, uuid
) FROM PUBLIC, anon, authenticated, service_role;

GRANT EXECUTE ON FUNCTION public.begin_unity_app_release(
    uuid, text, text, text, text, bigint, text, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.finalize_unity_app_release(
    uuid, uuid, bigint, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.fail_unity_app_release(
    uuid, uuid, text
) TO service_role;
GRANT EXECUTE ON FUNCTION public.activate_unity_app_release(
    uuid, uuid
) TO service_role;

COMMENT ON TABLE public.unity_app_releases IS
    'Server-only metadata for retained Unity artifacts; Laravel exposes only the active ready release through short-lived signed URLs.';
COMMENT ON FUNCTION public.finalize_unity_app_release(uuid, uuid, bigint, text) IS
    'Verifies a private Storage object and atomically makes the completed Unity release the only active ready release for its platform.';
COMMENT ON FUNCTION public.activate_unity_app_release(uuid, uuid) IS
    'Atomically reactivates a retained ready Unity release for its platform after verifying that its private Storage object still matches.';

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_10_11_versioned_unity_app_releases.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    NOT (SELECT public FROM storage.buckets WHERE id = 'unity-releases')
        AS release_bucket_private,
    NOT has_table_privilege('anon', 'public.unity_app_releases', 'SELECT')
        AS anonymous_release_metadata_closed,
    NOT has_table_privilege('authenticated', 'public.unity_app_releases', 'SELECT')
        AS authenticated_release_metadata_closed,
    has_table_privilege('service_role', 'public.unity_app_releases', 'SELECT')
        AS server_release_reads_ready,
    NOT has_table_privilege('service_role', 'public.unity_app_releases', 'INSERT,UPDATE,DELETE')
        AS direct_server_release_writes_closed,
    has_function_privilege(
        'service_role',
        'public.finalize_unity_app_release(uuid,uuid,bigint,text)',
        'EXECUTE'
    ) AS server_finalize_rpc_ready,
    NOT has_function_privilege(
        'authenticated',
        'public.activate_unity_app_release(uuid,uuid)',
        'EXECUTE'
    ) AS browser_activation_closed;
