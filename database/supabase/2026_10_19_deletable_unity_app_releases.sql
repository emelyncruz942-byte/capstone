-- Add retry-safe deletion for inactive Unity application releases.
-- Laravel claims a release first, removes its private provider object, then
-- finishes the metadata deletion with the same opaque token and storage
-- identity. The claim prevents activation or publication while cleanup runs.

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
        WHERE migration_key = '2026_10_12_cloudflare_r2_app_releases.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_10_12_cloudflare_r2_app_releases.sql first.';
    END IF;
END
$preflight$;

ALTER TABLE public.unity_app_releases
    ADD COLUMN IF NOT EXISTS deletion_requested_at timestamp with time zone,
    ADD COLUMN IF NOT EXISTS deletion_requested_by uuid
        REFERENCES public.profiles(id) ON DELETE RESTRICT,
    ADD COLUMN IF NOT EXISTS deletion_token uuid;

ALTER TABLE public.unity_app_releases
    DROP CONSTRAINT IF EXISTS unity_app_releases_deletion_state_check;

ALTER TABLE public.unity_app_releases
    ADD CONSTRAINT unity_app_releases_deletion_state_check CHECK (
        (
            deletion_requested_at IS NULL
            AND deletion_requested_by IS NULL
            AND deletion_token IS NULL
        )
        OR
        (
            deletion_requested_at IS NOT NULL
            AND deletion_requested_by IS NOT NULL
            AND deletion_token IS NOT NULL
            AND NOT is_active
        )
    );

CREATE OR REPLACE FUNCTION public.guard_unity_app_release_pending_deletion()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, public
AS $$
BEGIN
    IF OLD.deletion_requested_at IS NOT NULL THEN
        RAISE EXCEPTION 'This Unity release is pending deletion and cannot be changed.';
    END IF;

    IF NEW.deletion_requested_at IS NOT NULL AND NEW.is_active THEN
        RAISE EXCEPTION 'A current Unity release cannot be marked for deletion.';
    END IF;

    RETURN NEW;
END;
$$;

REVOKE ALL ON FUNCTION public.guard_unity_app_release_pending_deletion()
    FROM PUBLIC, anon, authenticated, service_role;

DROP TRIGGER IF EXISTS guard_unity_app_release_pending_deletion
    ON public.unity_app_releases;
CREATE TRIGGER guard_unity_app_release_pending_deletion
BEFORE UPDATE ON public.unity_app_releases
FOR EACH ROW
EXECUTE FUNCTION public.guard_unity_app_release_pending_deletion();

CREATE OR REPLACE FUNCTION public.begin_unity_app_release_deletion(
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
    requested_time timestamp with time zone := clock_timestamp();
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_admin_id
          AND role = 'admin'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to delete a Unity release.';
    END IF;

    -- This is the same table lock used by release activation/finalization. It
    -- closes the race where a version could become current just as its private
    -- object was about to be removed.
    LOCK TABLE ONLY public.unity_app_releases IN SHARE ROW EXCLUSIVE MODE;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.is_active THEN
        RAISE EXCEPTION 'Make another version current before deleting this Unity release.';
    ELSIF release_row.deletion_requested_at IS NOT NULL THEN
        RETURN release_row;
    END IF;

    UPDATE public.unity_app_releases
    SET deletion_requested_at = requested_time,
        deletion_requested_by = p_admin_id,
        deletion_token = gen_random_uuid(),
        updated_at = requested_time
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

CREATE OR REPLACE FUNCTION public.finish_unity_app_release_deletion(
    p_release_id uuid,
    p_admin_id uuid,
    p_deletion_token uuid,
    p_storage_provider text,
    p_storage_bucket text,
    p_storage_path text,
    p_provider_upload_id text
)
RETURNS public.unity_app_releases
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
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
        RAISE EXCEPTION 'An active administrator is required to finish Unity release deletion.';
    END IF;

    SELECT * INTO release_row
    FROM public.unity_app_releases
    WHERE id = p_release_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unity release not found.';
    ELSIF release_row.is_active THEN
        RAISE EXCEPTION 'A current Unity release cannot be deleted.';
    ELSIF release_row.deletion_requested_at IS NULL
       OR release_row.deletion_token IS NULL
       OR p_deletion_token IS NULL
       OR release_row.deletion_token <> p_deletion_token THEN
        RAISE EXCEPTION 'The Unity release deletion token is missing or no longer matches.';
    ELSIF release_row.storage_provider IS DISTINCT FROM p_storage_provider
       OR release_row.storage_bucket IS DISTINCT FROM p_storage_bucket
       OR release_row.storage_path IS DISTINCT FROM p_storage_path
       OR release_row.provider_upload_id IS DISTINCT FROM p_provider_upload_id THEN
        RAISE EXCEPTION 'The Unity release storage identity no longer matches the cleanup request.';
    END IF;

    DELETE FROM public.unity_app_releases
    WHERE id = p_release_id
    RETURNING * INTO release_row;

    RETURN release_row;
END;
$$;

REVOKE ALL ON FUNCTION public.begin_unity_app_release_deletion(uuid, uuid)
    FROM PUBLIC, anon, authenticated, service_role;
REVOKE ALL ON FUNCTION public.finish_unity_app_release_deletion(
    uuid, uuid, uuid, text, text, text, text
) FROM PUBLIC, anon, authenticated, service_role;

GRANT EXECUTE ON FUNCTION public.begin_unity_app_release_deletion(uuid, uuid)
    TO service_role;
GRANT EXECUTE ON FUNCTION public.finish_unity_app_release_deletion(
    uuid, uuid, uuid, text, text, text, text
) TO service_role;

COMMENT ON COLUMN public.unity_app_releases.deletion_requested_at IS
    'Server-managed claim time. A claimed inactive release cannot be activated or otherwise changed while provider cleanup is pending.';
COMMENT ON COLUMN public.unity_app_releases.deletion_requested_by IS
    'Administrator who most recently initiated the retry-safe release deletion workflow.';
COMMENT ON COLUMN public.unity_app_releases.deletion_token IS
    'Opaque server-only token binding provider cleanup to the final metadata deletion.';
COMMENT ON FUNCTION public.begin_unity_app_release_deletion(uuid, uuid) IS
    'Claims an inactive Unity release for external storage cleanup and returns a retry-stable deletion token.';
COMMENT ON FUNCTION public.finish_unity_app_release_deletion(uuid, uuid, uuid, text, text, text, text) IS
    'Deletes claimed release metadata only after Laravel confirms cleanup of the exact bound provider object.';

ALTER TABLE public.unity_app_releases ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.unity_app_releases FORCE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON TABLE public.unity_app_releases
    FROM PUBLIC, anon, authenticated, service_role;
GRANT SELECT ON TABLE public.unity_app_releases TO service_role;

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_10_19_deletable_unity_app_releases.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_function_privilege(
        'service_role',
        'public.begin_unity_app_release_deletion(uuid,uuid)',
        'EXECUTE'
    ) AS server_release_delete_begin_ready,
    has_function_privilege(
        'service_role',
        'public.finish_unity_app_release_deletion(uuid,uuid,uuid,text,text,text,text)',
        'EXECUTE'
    ) AS server_release_delete_finish_ready,
    NOT has_function_privilege(
        'authenticated',
        'public.begin_unity_app_release_deletion(uuid,uuid)',
        'EXECUTE'
    ) AS browser_release_delete_closed,
    NOT has_table_privilege(
        'service_role',
        'public.unity_app_releases',
        'DELETE'
    ) AS direct_release_delete_closed;
