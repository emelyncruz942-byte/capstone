-- Let a quiz creator acknowledge a correction without closing the report.
-- Run after 2026_09_13_recovery_and_incident_alerts.sql and deploy the
-- matching Laravel routes/views in the same release.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.profiles') IS NULL
       OR to_regclass('public.quizzes') IS NULL
       OR to_regclass('public.quiz_reports') IS NULL
       OR to_regclass('public.notifications') IS NULL
       OR to_regclass('public.audit_logs') IS NULL
       OR to_regprocedure('public.create_notification(uuid,text,text,text,text,jsonb,text)') IS NULL
       OR to_regprocedure('public.notify_all_admins(text,text,text,text,jsonb,text)') IS NULL
       OR to_regprocedure('public.quiz_management_url(uuid)') IS NULL THEN
        RAISE EXCEPTION 'Required MathVerse quiz, notification, or audit objects are missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_13_recovery_and_incident_alerts.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_09_13_recovery_and_incident_alerts.sql first.';
    END IF;
END
$preflight$;

ALTER TABLE public.quiz_reports
    ADD COLUMN IF NOT EXISTS revised_at timestamp with time zone,
    ADD COLUMN IF NOT EXISTS revised_by uuid;

DO $restore_revision_archive$
BEGIN
    IF to_regclass('public.rollback_quiz_report_revisions_20261012') IS NOT NULL THEN
        UPDATE public.quiz_reports reports
        SET revised_at = archive.revised_at,
            revised_by = CASE
                WHEN EXISTS (
                    SELECT 1 FROM public.profiles
                    WHERE profiles.id = archive.revised_by
                ) THEN archive.revised_by
                ELSE NULL
            END
        FROM public.rollback_quiz_report_revisions_20261012 archive
        WHERE reports.id = archive.report_id
          AND reports.revised_at IS NULL;
    END IF;
END
$restore_revision_archive$;

DO $constraints$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'quiz_reports_revised_by_fkey'
          AND conrelid = 'public.quiz_reports'::regclass
    ) THEN
        ALTER TABLE public.quiz_reports
            ADD CONSTRAINT quiz_reports_revised_by_fkey
            FOREIGN KEY (revised_by) REFERENCES public.profiles(id)
            ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'quiz_reports_revision_pair_check'
          AND conrelid = 'public.quiz_reports'::regclass
    ) THEN
        ALTER TABLE public.quiz_reports
            ADD CONSTRAINT quiz_reports_revision_pair_check
            CHECK (
                revised_at IS NOT NULL OR revised_by IS NULL
            );
    END IF;
END
$constraints$;

CREATE INDEX IF NOT EXISTS quiz_reports_creator_status_created_idx
    ON public.quiz_reports (quiz_creator_id, status, created_at DESC);

-- Teacher report details are fetched by Laravel with an explicit creator-id
-- filter. Do not expose raw report rows (including reporter identifiers)
-- directly through the authenticated Data API.
DROP POLICY IF EXISTS quiz_reports_creator_read ON public.quiz_reports;

CREATE OR REPLACE FUNCTION public.mark_quiz_report_revised(
    p_report_id uuid,
    p_teacher_id uuid
)
RETURNS TABLE (
    report_id uuid,
    report_status text,
    report_revised_at timestamp with time zone,
    revision_changed boolean
)
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    target_report public.quiz_reports%ROWTYPE;
    changed_at timestamp with time zone;
BEGIN
    IF p_report_id IS NULL OR p_teacher_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.profiles
        WHERE id = p_teacher_id
          AND role = 'teacher'
          AND suspended_at IS NULL
          AND deactivated_at IS NULL
    ) THEN
        RAISE EXCEPTION 'The quiz report revision is not permitted.';
    END IF;

    SELECT * INTO target_report
    FROM public.quiz_reports
    WHERE id = p_report_id
    FOR UPDATE;

    IF NOT FOUND OR target_report.quiz_creator_id IS DISTINCT FROM p_teacher_id THEN
        RAISE EXCEPTION 'The quiz report revision is not permitted.';
    END IF;

    IF target_report.quiz_id IS NULL OR NOT EXISTS (
        SELECT 1
        FROM public.quizzes
        WHERE id = target_report.quiz_id
          AND teacher_id = p_teacher_id
          AND deleted_at IS NULL
    ) THEN
        RAISE EXCEPTION 'The quiz must be restored before it can be marked revised.';
    END IF;

    IF target_report.status <> 'pending' THEN
        RAISE EXCEPTION 'Only a report awaiting administrator review can be marked revised.';
    END IF;

    IF target_report.revised_at IS NOT NULL THEN
        RETURN QUERY SELECT
            target_report.id,
            target_report.status,
            target_report.revised_at,
            false;
        RETURN;
    END IF;

    changed_at := clock_timestamp();
    UPDATE public.quiz_reports
    SET revised_at = changed_at,
        revised_by = p_teacher_id
    WHERE id = target_report.id;

    INSERT INTO public.audit_logs (
        actor_id, actor_role, action, target_type, target_id, metadata
    ) VALUES (
        p_teacher_id,
        'teacher',
        'quiz_report.marked_revised',
        'quiz_report',
        target_report.id::text,
        jsonb_build_object(
            'quiz_id', target_report.quiz_id,
            'quiz_topic', target_report.quiz_topic,
            'report_status', target_report.status
        )
    );

    RETURN QUERY SELECT
        target_report.id,
        target_report.status,
        changed_at,
        true;
END;
$$;

REVOKE ALL ON FUNCTION public.mark_quiz_report_revised(uuid, uuid)
    FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.mark_quiz_report_revised(uuid, uuid)
    TO service_role;

-- New and resolved report notifications now open the creator's report details
-- instead of dropping a teacher on the general quiz-management page.
CREATE OR REPLACE FUNCTION public.notify_quiz_report_changed()
RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
SET timezone = 'UTC'
AS $$
DECLARE
    report_topic text;
    creator_action_url text;
    creator_role text;
BEGIN
    report_topic := coalesce(NEW.quiz_topic, 'A shared quiz');
    SELECT role INTO creator_role
    FROM public.profiles
    WHERE id = NEW.quiz_creator_id;
    creator_action_url := CASE
        WHEN creator_role = 'teacher'
            THEN '/teacher/quiz-reports/' || NEW.id::text
        WHEN creator_role = 'admin'
            THEN '/admin/quiz-reports/' || NEW.id::text
        ELSE public.quiz_management_url(NEW.quiz_creator_id)
    END;

    IF TG_OP = 'INSERT' THEN
        PERFORM public.notify_all_admins(
            'quiz_report_submitted',
            'New quiz report',
            report_topic || ' was reported and needs moderation.',
            '/admin/quiz-reports/' || NEW.id::text,
            jsonb_build_object('report_id', NEW.id, 'quiz_id', NEW.quiz_id),
            'quiz-report-admin:' || NEW.id::text
        );
        PERFORM public.create_notification(
            NEW.quiz_creator_id,
            'quiz_reported',
            'Your shared quiz was reported',
            report_topic || ' was submitted for administrator review.',
            creator_action_url,
            jsonb_build_object('report_id', NEW.id, 'quiz_id', NEW.quiz_id),
            'quiz-report-creator:' || NEW.id::text
        );
    ELSIF OLD.status IS DISTINCT FROM NEW.status
          AND NEW.status IN ('reviewed', 'dismissed') THEN
        PERFORM public.create_notification(
            NEW.reporter_id,
            'quiz_report_resolved',
            'Quiz report ' || NEW.status,
            'Your report for ' || report_topic || ' was ' || NEW.status || ' by an administrator.',
            '/teacher/quiz-library',
            jsonb_build_object('report_id', NEW.id, 'status', NEW.status),
            'quiz-report-resolution:' || NEW.id::text || ':' || NEW.status || ':reporter'
        );
        PERFORM public.create_notification(
            NEW.quiz_creator_id,
            'quiz_report_resolved',
            'Quiz report ' || NEW.status,
            'The administrator ' || NEW.status || ' a report for ' || report_topic || '.',
            creator_action_url,
            jsonb_build_object('report_id', NEW.id, 'status', NEW.status),
            'quiz-report-resolution:' || NEW.id::text || ':' || NEW.status || ':creator'
        );
    END IF;

    RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION public.notify_quiz_report_changed()
    FROM PUBLIC, anon, authenticated, service_role;

DROP TRIGGER IF EXISTS quiz_reports_notify_change ON public.quiz_reports;
CREATE TRIGGER quiz_reports_notify_change
AFTER INSERT OR UPDATE OF status ON public.quiz_reports
FOR EACH ROW EXECUTE FUNCTION public.notify_quiz_report_changed();

UPDATE public.notifications notifications
SET action_url = CASE
    WHEN EXISTS (
        SELECT 1 FROM public.profiles
        WHERE profiles.id = reports.quiz_creator_id
          AND profiles.role = 'admin'
    ) THEN '/admin/quiz-reports/' || reports.id::text
    ELSE '/teacher/quiz-reports/' || reports.id::text
END
FROM public.quiz_reports reports
WHERE notifications.user_id = reports.quiz_creator_id
  AND notifications.data ->> 'report_id' = reports.id::text
  AND notifications.type IN ('quiz_reported', 'quiz_report_resolved');

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_10_12_teacher_quiz_report_revisions.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_table_privilege('authenticated', 'public.quiz_reports', 'SELECT')
        AS authenticated_report_reads_use_rls,
    NOT has_function_privilege(
        'authenticated', 'public.mark_quiz_report_revised(uuid,uuid)', 'EXECUTE'
    ) AS direct_teacher_rpc_calls_closed,
    has_function_privilege(
        'service_role', 'public.mark_quiz_report_revised(uuid,uuid)', 'EXECUTE'
    ) AS server_revision_rpc_ready;
