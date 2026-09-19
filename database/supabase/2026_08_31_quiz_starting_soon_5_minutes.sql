-- MathVerse five-minute quiz-start reminder window.
-- Run after 2026_08_31_notification_delivery_policy_followup.sql.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.notifications') is null
       or to_regclass('public.notification_deliveries') is null
       or to_regclass('public.quiz_sessions') is null
       or to_regclass('public.quiz_session_students') is null
       or to_regclass('public.quiz_results') is null then
        raise exception 'The notification layer is missing. Run the August 31 notification migrations first.';
    end if;
end
$$;

-- Remove earlier start reminders so unfinished quizzes can be rearmed only
-- after entering the five-minute window. Browser alerts that were already
-- delivered cannot be retracted, but their old bell rows are removed below.
delete from public.notification_deliveries deliveries
using public.notifications notifications
where deliveries.notification_id = notifications.id
  and notifications.type = 'quiz_starts_soon'
  and deliveries.status in ('pending', 'failed', 'sending');

delete from public.notifications notifications
where notifications.type = 'quiz_starts_soon';

create or replace function public.generate_upcoming_quiz_notifications(
    p_user_id uuid default null
)
returns table (created_count integer)
language plpgsql
security definer
set search_path = public
as $$
declare
    assignment_row record;
    notification_id uuid;
    inserted_count integer := 0;
begin
    for assignment_row in
        select
            sessions.id,
            sessions.class_id,
            sessions.topic,
            sessions.status,
            sessions.available_at,
            sessions.due_at,
            eligibility.student_id
        from public.quiz_sessions sessions
        join public.quiz_session_students eligibility
          on eligibility.session_id = sessions.id
         and eligibility.eligibility_status = 'eligible'
        where sessions.status in ('waiting', 'active')
          and (p_user_id is null or eligibility.student_id = p_user_id)
          and not exists (
              select 1
              from public.quiz_results results
              where results.session_id = sessions.id
                and results.student_id = eligibility.student_id
                and results.is_counted = true
          )
          and (
              sessions.available_at between timezone('utc', now()) and timezone('utc', now()) + interval '5 minutes'
              or sessions.due_at between timezone('utc', now()) and timezone('utc', now()) + interval '30 minutes'
          )
    loop
        if assignment_row.status = 'waiting'
           and assignment_row.available_at between timezone('utc', now()) and timezone('utc', now()) + interval '5 minutes' then
            notification_id := public.create_notification(
                assignment_row.student_id,
                'quiz_starts_soon',
                'Quiz starts within 5 minutes',
                coalesce(assignment_row.topic, 'A quiz') || ' will become available in 5 minutes or less.',
                '/student/classes/' || assignment_row.class_id::text,
                jsonb_build_object('session_id', assignment_row.id, 'available_at', assignment_row.available_at),
                'quiz-starts-soon:' || assignment_row.id::text || ':' || assignment_row.student_id::text
                    || ':' || assignment_row.available_at::text
            );
            if notification_id is not null then inserted_count := inserted_count + 1; end if;
        end if;

        if assignment_row.due_at between timezone('utc', now()) and timezone('utc', now()) + interval '30 minutes' then
            notification_id := public.create_notification(
                assignment_row.student_id,
                'quiz_due_soon',
                'Quiz due within 30 minutes',
                coalesce(assignment_row.topic, 'A quiz') || ' is due in 30 minutes or less.',
                '/student/classes/' || assignment_row.class_id::text,
                jsonb_build_object('session_id', assignment_row.id, 'due_at', assignment_row.due_at),
                'quiz-due-soon:' || assignment_row.id::text || ':' || assignment_row.student_id::text
                    || ':' || assignment_row.due_at::text
            );
            if notification_id is not null then inserted_count := inserted_count + 1; end if;
        end if;
    end loop;

    created_count := inserted_count;
    return next;
end;
$$;

revoke all on function public.generate_upcoming_quiz_notifications(uuid)
from public, anon, authenticated;
grant execute on function public.generate_upcoming_quiz_notifications(uuid)
to service_role;

notify pgrst, 'reload schema';

commit;
