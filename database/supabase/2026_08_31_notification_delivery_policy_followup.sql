-- MathVerse notification delivery policy follow-up.
-- Run after 2026_08_31_notifications_delivery_channels.sql.
--
-- This keeps completed password/email security events in the bell without an
-- extra Web Push, emails every successfully stored allowed quiz attempt, and
-- uses a 5-minute quiz-start window and a 30-minute quiz-due window.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.notifications') is null
       or to_regclass('public.notification_deliveries') is null
       or to_regclass('public.profiles') is null
       or to_regclass('public.quiz_sessions') is null
       or to_regclass('public.quiz_session_students') is null
       or to_regclass('public.quiz_results') is null then
        raise exception 'The notification delivery layer is missing. Run both August 31 notification migrations first.';
    end if;
end
$$;

-- Do not send security Web Push deliveries that are still waiting. Supabase
-- Auth already sends their configured security-notification emails. Bell rows
-- remain untouched.
delete from public.notification_deliveries
where event_type in ('password_changed', 'email_changed')
  and channel = 'web_push'
  and status in ('pending', 'failed');

-- A valid retake result created just before this migration may still be queued
-- as Web Push. Convert only unsent rows with a usable recipient email.
update public.notification_deliveries deliveries
set channel = 'email',
    recipient_email = profiles.email,
    recipient_name = coalesce(
        nullif(btrim(concat_ws(' ', profiles.first_name, profiles.last_name)), ''),
        profiles.username,
        deliveries.recipient_name,
        ''
    ),
    updated_at = timezone('utc', now())
from public.profiles profiles
where deliveries.user_id = profiles.id
  and deliveries.event_type = 'quiz_result_recorded'
  and deliveries.channel = 'web_push'
  and deliveries.status in ('pending', 'failed')
  and nullif(btrim(profiles.email), '') is not null;

create or replace function public.queue_notification_delivery()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    delivery_channel text;
    recipient record;
begin
    -- Laravel sends these two administrator broadcasts directly. Supabase Auth
    -- already emails completed password and email-address security changes.
    if new.type in (
        'teacher_verification',
        'quiz_report_submitted',
        'password_changed',
        'email_changed'
    ) then
        return new;
    end if;

    delivery_channel := case
        when new.type in (
            'teacher_application_received',
            'teacher_approved',
            'account_suspended',
            'account_restored',
            'quiz_assigned',
            'quiz_started',
            'quiz_retake_granted',
            'quiz_excused',
            'removed_from_class',
            'quiz_result_recorded'
        ) then 'email'
        else 'web_push'
    end;

    select
        profiles.email,
        coalesce(
            nullif(btrim(concat_ws(' ', profiles.first_name, profiles.last_name)), ''),
            profiles.username,
            ''
        ) as name
    into recipient
    from public.profiles
    where profiles.id = new.user_id;

    if not found then
        return new;
    end if;

    if delivery_channel = 'email' and nullif(btrim(recipient.email), '') is null then
        delivery_channel := 'web_push';
    end if;

    insert into public.notification_deliveries (
        notification_id,
        user_id,
        channel,
        event_type,
        recipient_email,
        recipient_name,
        title,
        message,
        action_url,
        data,
        delivery_key
    ) values (
        new.id,
        new.user_id,
        delivery_channel,
        new.type,
        case when delivery_channel = 'email' then recipient.email else null end,
        recipient.name,
        new.title,
        new.message,
        new.action_url,
        new.data,
        'notification:' || new.id::text || ':' || delivery_channel
    )
    on conflict (delivery_key) do nothing;

    return new;
end;
$$;

-- Remove premature due-soon bell rows so each unfinished quiz can be armed
-- again when it actually enters its 30-minute window. Already displayed OS
-- notifications cannot be retracted, but no new premature reminders are made.
delete from public.notification_deliveries deliveries
using public.notifications notifications
where deliveries.notification_id = notifications.id
  and notifications.type = 'quiz_due_soon'
  and nullif(notifications.data ->> 'due_at', '') is not null
  and (notifications.data ->> 'due_at')::timestamp with time zone
      > timezone('utc', now()) + interval '30 minutes'
  and deliveries.status in ('pending', 'failed');

delete from public.notifications notifications
where notifications.type = 'quiz_due_soon'
  and nullif(notifications.data ->> 'due_at', '') is not null
  and (notifications.data ->> 'due_at')::timestamp with time zone
      > timezone('utc', now()) + interval '30 minutes';

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

revoke all on function public.queue_notification_delivery()
from public, anon, authenticated;
revoke all on function public.generate_upcoming_quiz_notifications(uuid)
from public, anon, authenticated;
grant execute on function public.generate_upcoming_quiz_notifications(uuid)
to service_role;

notify pgrst, 'reload schema';

commit;
