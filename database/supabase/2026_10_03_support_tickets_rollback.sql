-- Emergency rollback for 2026_10_03_support_tickets.sql.
-- Restore application code that does not expose the ticket routes first.
-- Existing ticket records are copied to a locked owner-only archive.

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

DO $archive_support_tickets$
BEGIN
    IF to_regclass('public.support_tickets') IS NOT NULL THEN
        CREATE TABLE IF NOT EXISTS public.rollback_support_tickets_20261003
            (LIKE public.support_tickets INCLUDING ALL);
        ALTER TABLE public.rollback_support_tickets_20261003 ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.rollback_support_tickets_20261003 FORCE ROW LEVEL SECURITY;
        REVOKE ALL PRIVILEGES ON TABLE public.rollback_support_tickets_20261003
            FROM PUBLIC, anon, authenticated, service_role;

        INSERT INTO public.rollback_support_tickets_20261003
        SELECT * FROM public.support_tickets
        ON CONFLICT (id) DO NOTHING;
    END IF;
END
$archive_support_tickets$;

DELETE FROM public.notifications
WHERE type IN ('support_ticket_submitted', 'support_ticket_updated');

DROP TABLE IF EXISTS public.support_tickets;
DROP FUNCTION IF EXISTS public.notify_support_ticket_changed();
DROP FUNCTION IF EXISTS public.prepare_support_ticket_update();
DROP FUNCTION IF EXISTS public.prepare_support_ticket_insert();

DELETE FROM public.mathverse_schema_migrations
WHERE migration_key = '2026_10_03_support_tickets.sql';

NOTIFY pgrst, 'reload schema';
COMMIT;
