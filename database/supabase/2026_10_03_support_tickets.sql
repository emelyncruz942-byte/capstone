-- MathVerse support tickets for authenticated students and teachers.
-- Run after 2026_10_02_vr_server_authority_and_request_guards.sql and deploy
-- the matching Laravel application in the same release.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.profiles') IS NULL
       OR to_regclass('public.notifications') IS NULL
       OR to_regprocedure('public.create_notification(uuid,text,text,text,text,jsonb,text)') IS NULL
       OR to_regprocedure('public.notify_all_admins(text,text,text,text,jsonb,text)') IS NULL THEN
        RAISE EXCEPTION 'Required MathVerse profile and notification objects are missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_10_02_vr_server_authority_and_request_guards.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_10_02_vr_server_authority_and_request_guards.sql first.';
    END IF;
END
$preflight$;

CREATE TABLE IF NOT EXISTS public.support_tickets (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    reporter_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
    reporter_role text NOT NULL CHECK (reporter_role IN ('student', 'teacher')),
    reporter_name text NOT NULL CHECK (char_length(reporter_name) BETWEEN 1 AND 200),
    reporter_email text CHECK (
        reporter_email IS NULL OR char_length(reporter_email) BETWEEN 3 AND 320
    ),
    category text NOT NULL CHECK (category IN (
        'bug', 'error', 'account', 'quiz', 'vr', 'accessibility', 'other'
    )),
    subject text NOT NULL CHECK (char_length(subject) BETWEEN 5 AND 160),
    description text NOT NULL CHECK (char_length(description) BETWEEN 20 AND 5000),
    page_url text CHECK (
        page_url IS NULL
        OR (
            char_length(page_url) BETWEEN 8 AND 2048
            AND page_url ~ '^https://mathmetaverse\.space(?:/[^?#[:space:]]*)?$'
        )
    ),
    reference_id text CHECK (
        reference_id IS NULL OR reference_id ~ '^MV-[A-F0-9]{16}$'
    ),
    status text NOT NULL DEFAULT 'open'
        CHECK (status IN ('open', 'in_progress', 'resolved', 'closed')),
    priority text NOT NULL DEFAULT 'normal'
        CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    admin_response text CHECK (
        admin_response IS NULL OR char_length(admin_response) BETWEEN 1 AND 3000
    ),
    assigned_to uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
    updated_by uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
    lock_version integer NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    resolved_at timestamp with time zone,
    created_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    CHECK (
        status NOT IN ('resolved', 'closed')
        OR (admin_response IS NOT NULL AND resolved_at IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS support_tickets_reporter_updated_idx
    ON public.support_tickets (reporter_id, updated_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS support_tickets_status_priority_updated_idx
    ON public.support_tickets (status, priority, updated_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS support_tickets_reference_idx
    ON public.support_tickets (reference_id)
    WHERE reference_id IS NOT NULL;

CREATE OR REPLACE FUNCTION public.prepare_support_ticket_insert()
RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    reporter public.profiles%ROWTYPE;
BEGIN
    SELECT * INTO reporter
    FROM public.profiles
    WHERE id = NEW.reporter_id
      AND role IN ('student', 'teacher')
      AND suspended_at IS NULL;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Only active students and teachers can submit support tickets.';
    END IF;

    NEW.reporter_role := reporter.role;
    NEW.reporter_name := left(
        coalesce(
            nullif(btrim(concat_ws(' ', reporter.first_name, reporter.last_name)), ''),
            'MathVerse user'
        ),
        200
    );
    NEW.reporter_email := nullif(left(btrim(reporter.email), 320), '');
    NEW.category := btrim(NEW.category);
    NEW.subject := btrim(NEW.subject);
    NEW.description := btrim(NEW.description);
    NEW.page_url := nullif(btrim(NEW.page_url), '');
    NEW.reference_id := nullif(upper(btrim(NEW.reference_id)), '');
    NEW.status := 'open';
    NEW.priority := 'normal';
    NEW.admin_response := NULL;
    NEW.assigned_to := NULL;
    NEW.updated_by := NULL;
    NEW.lock_version := 1;
    NEW.resolved_at := NULL;
    NEW.created_at := clock_timestamp();
    NEW.updated_at := NEW.created_at;

    RETURN NEW;
END;
$$;
-- Trigger functions are deliberately not callable through PostgREST. PostgreSQL
-- invokes them as the owning trigger function regardless of caller EXECUTE.
REVOKE ALL ON FUNCTION public.prepare_support_ticket_insert()
    FROM PUBLIC, anon, authenticated, service_role;

CREATE OR REPLACE FUNCTION public.prepare_support_ticket_update()
RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
BEGIN
    IF ROW(
        NEW.reporter_id, NEW.reporter_role, NEW.reporter_name, NEW.reporter_email,
        NEW.category, NEW.subject, NEW.description, NEW.page_url, NEW.reference_id,
        NEW.created_at
    ) IS DISTINCT FROM ROW(
        OLD.reporter_id, OLD.reporter_role, OLD.reporter_name, OLD.reporter_email,
        OLD.category, OLD.subject, OLD.description, OLD.page_url, OLD.reference_id,
        OLD.created_at
    ) THEN
        RAISE EXCEPTION 'Support ticket report details are immutable.';
    END IF;

    IF NEW.updated_by IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = NEW.updated_by
          AND role = 'admin'
          AND suspended_at IS NULL
    ) THEN
        RAISE EXCEPTION 'An active administrator is required to update a support ticket.';
    END IF;

    IF NEW.assigned_to IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = NEW.assigned_to
          AND role = 'admin'
          AND suspended_at IS NULL
    ) THEN
        RAISE EXCEPTION 'Support tickets can only be assigned to an active administrator.';
    END IF;

    NEW.admin_response := nullif(btrim(NEW.admin_response), '');
    IF NEW.status IN ('resolved', 'closed') AND NEW.admin_response IS NULL THEN
        RAISE EXCEPTION 'A response is required before resolving or closing a ticket.';
    END IF;

    NEW.resolved_at := CASE
        WHEN NEW.status IN ('resolved', 'closed')
            THEN coalesce(OLD.resolved_at, clock_timestamp())
        ELSE NULL
    END;
    NEW.updated_at := clock_timestamp();
    NEW.lock_version := OLD.lock_version + 1;

    RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION public.prepare_support_ticket_update()
    FROM PUBLIC, anon, authenticated, service_role;

DROP TRIGGER IF EXISTS support_tickets_prepare_insert ON public.support_tickets;
CREATE TRIGGER support_tickets_prepare_insert
BEFORE INSERT ON public.support_tickets
FOR EACH ROW EXECUTE FUNCTION public.prepare_support_ticket_insert();

DROP TRIGGER IF EXISTS support_tickets_prepare_update ON public.support_tickets;
CREATE TRIGGER support_tickets_prepare_update
BEFORE UPDATE ON public.support_tickets
FOR EACH ROW EXECUTE FUNCTION public.prepare_support_ticket_update();

CREATE OR REPLACE FUNCTION public.notify_support_ticket_changed()
RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        PERFORM public.notify_all_admins(
            'support_ticket_submitted',
            'New support ticket',
            left(NEW.reporter_name || ' reported: ' || NEW.subject, 600),
            '/admin/support-tickets/' || NEW.id::text,
            jsonb_build_object(
                'ticket_id', NEW.id,
                'category', NEW.category,
                'reference_id', NEW.reference_id
            ),
            'support-ticket-admin:' || NEW.id::text
        );
    ELSIF OLD.status IS DISTINCT FROM NEW.status
          OR OLD.admin_response IS DISTINCT FROM NEW.admin_response THEN
        PERFORM public.create_notification(
            NEW.reporter_id,
            'support_ticket_updated',
            'Support ticket updated',
            CASE
                WHEN NEW.status = 'resolved' THEN 'Your support ticket was resolved.'
                WHEN NEW.status = 'closed' THEN 'Your support ticket was closed.'
                WHEN NEW.status = 'in_progress' THEN 'An administrator is reviewing your support ticket.'
                ELSE 'Your support ticket was reopened.'
            END,
            '/support-tickets/' || NEW.id::text,
            jsonb_build_object('ticket_id', NEW.id, 'status', NEW.status),
            'support-ticket-reporter:' || NEW.id::text || ':' || NEW.lock_version::text
        );
    END IF;

    RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION public.notify_support_ticket_changed()
    FROM PUBLIC, anon, authenticated, service_role;

DROP TRIGGER IF EXISTS support_tickets_notify_change ON public.support_tickets;
CREATE TRIGGER support_tickets_notify_change
AFTER INSERT OR UPDATE OF status, admin_response ON public.support_tickets
FOR EACH ROW EXECUTE FUNCTION public.notify_support_ticket_changed();

ALTER TABLE public.support_tickets ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.support_tickets FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS support_tickets_read_own ON public.support_tickets;
CREATE POLICY support_tickets_read_own
ON public.support_tickets FOR SELECT
TO authenticated
USING (auth.uid() = reporter_id);

DROP POLICY IF EXISTS support_tickets_insert_own ON public.support_tickets;
CREATE POLICY support_tickets_insert_own
ON public.support_tickets FOR INSERT
TO authenticated
WITH CHECK (
    auth.uid() = reporter_id
    AND EXISTS (
        SELECT 1 FROM public.profiles
        WHERE id = auth.uid()
          AND role IN ('student', 'teacher')
          AND suspended_at IS NULL
    )
);

DROP POLICY IF EXISTS support_tickets_admin_read ON public.support_tickets;
CREATE POLICY support_tickets_admin_read
ON public.support_tickets FOR SELECT
TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.profiles
        WHERE id = auth.uid()
          AND role = 'admin'
          AND suspended_at IS NULL
    )
);

DROP POLICY IF EXISTS support_tickets_admin_update ON public.support_tickets;
CREATE POLICY support_tickets_admin_update
ON public.support_tickets FOR UPDATE
TO authenticated
USING (
    EXISTS (
        SELECT 1 FROM public.profiles
        WHERE id = auth.uid()
          AND role = 'admin'
          AND suspended_at IS NULL
    )
)
WITH CHECK (
    EXISTS (
        SELECT 1 FROM public.profiles
        WHERE id = auth.uid()
          AND role = 'admin'
          AND suspended_at IS NULL
    )
);

REVOKE ALL PRIVILEGES ON TABLE public.support_tickets
    FROM PUBLIC, anon, authenticated;
GRANT SELECT ON TABLE public.support_tickets TO authenticated;
GRANT ALL PRIVILEGES ON TABLE public.support_tickets TO service_role;

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_10_03_support_tickets.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_table_privilege('authenticated', 'public.support_tickets', 'SELECT')
        AS authenticated_can_read_with_rls,
    NOT has_table_privilege('authenticated', 'public.support_tickets', 'INSERT')
        AS direct_authenticated_writes_closed,
    NOT has_table_privilege('anon', 'public.support_tickets', 'SELECT')
        AS anonymous_access_closed,
    has_table_privilege('service_role', 'public.support_tickets', 'INSERT,UPDATE')
        AS server_ticket_writes_ready,
    NOT has_function_privilege(
        'authenticated', 'public.prepare_support_ticket_update()', 'EXECUTE'
    ) AS trigger_function_direct_calls_closed;
