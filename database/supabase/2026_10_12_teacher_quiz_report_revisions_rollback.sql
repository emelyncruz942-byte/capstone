-- Roll back 2026_10_12_teacher_quiz_report_revisions.sql while preserving
-- teacher revision acknowledgements in a runtime-inaccessible archive.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.quiz_reports') IS NULL THEN
        RAISE EXCEPTION 'The MathVerse quiz-report schema is missing.';
    END IF;
END
$preflight$;

CREATE TABLE IF NOT EXISTS public.rollback_quiz_report_revisions_20261012 (
    report_id uuid PRIMARY KEY,
    revised_at timestamp with time zone NOT NULL,
    revised_by uuid,
    archived_at timestamp with time zone NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE public.rollback_quiz_report_revisions_20261012
    ALTER COLUMN revised_by DROP NOT NULL;
ALTER TABLE public.rollback_quiz_report_revisions_20261012 ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.rollback_quiz_report_revisions_20261012 FORCE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON TABLE public.rollback_quiz_report_revisions_20261012
    FROM PUBLIC, anon, authenticated, service_role;

INSERT INTO public.rollback_quiz_report_revisions_20261012 (
    report_id, revised_at, revised_by, archived_at
)
SELECT id, revised_at, revised_by, clock_timestamp()
FROM public.quiz_reports
WHERE revised_at IS NOT NULL
ON CONFLICT (report_id) DO UPDATE
SET revised_at = EXCLUDED.revised_at,
    revised_by = EXCLUDED.revised_by,
    archived_at = EXCLUDED.archived_at;

-- Restore the notification destinations used before creator report details
-- were introduced.
UPDATE public.notifications notifications
SET action_url = public.quiz_management_url(notifications.user_id)
FROM public.quiz_reports reports
WHERE notifications.user_id = reports.quiz_creator_id
  AND notifications.data ->> 'report_id' = reports.id::text
  AND notifications.type IN ('quiz_reported', 'quiz_report_resolved');

CREATE OR REPLACE FUNCTION public.notify_quiz_report_changed()
RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
    report_topic text;
BEGIN
    report_topic := coalesce(NEW.quiz_topic, 'A shared quiz');

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
            public.quiz_management_url(NEW.quiz_creator_id),
            jsonb_build_object('report_id', NEW.id, 'quiz_id', NEW.quiz_id),
            'quiz-report-creator:' || NEW.id::text
        );
    ELSIF OLD.status IS DISTINCT FROM NEW.status AND NEW.status IN ('reviewed', 'dismissed') THEN
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
            public.quiz_management_url(NEW.quiz_creator_id),
            jsonb_build_object('report_id', NEW.id, 'status', NEW.status),
            'quiz-report-resolution:' || NEW.id::text || ':' || NEW.status || ':creator'
        );
    END IF;

    RETURN NEW;
END;
$$;
REVOKE ALL ON FUNCTION public.notify_quiz_report_changed()
    FROM PUBLIC, anon, authenticated, service_role;

DROP FUNCTION IF EXISTS public.mark_quiz_report_revised(uuid, uuid);
DROP POLICY IF EXISTS quiz_reports_creator_read ON public.quiz_reports;
DROP INDEX IF EXISTS public.quiz_reports_creator_status_created_idx;

ALTER TABLE public.quiz_reports
    DROP CONSTRAINT IF EXISTS quiz_reports_revision_pair_check,
    DROP CONSTRAINT IF EXISTS quiz_reports_revised_by_fkey,
    DROP COLUMN IF EXISTS revised_at,
    DROP COLUMN IF EXISTS revised_by;

DELETE FROM public.mathverse_schema_migrations
WHERE migration_key = '2026_10_12_teacher_quiz_report_revisions.sql';

NOTIFY pgrst, 'reload schema';
COMMIT;
