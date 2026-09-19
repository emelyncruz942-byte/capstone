-- Roll back the August 31 notification delivery policy follow-up.
-- This restores 24-hour due reminders, security-event Web Push, and an email
-- receipt only for attempt 1. It cannot retract notifications already sent.

begin;

set local search_path = public, extensions;

create or replace function public.queue_notification_delivery()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    delivery_channel text;
    recipient record;
    result_attempt integer := 1;
begin
    if new.type in ('teacher_verification', 'quiz_report_submitted') then
        return new;
    end if;

    if new.type = 'quiz_result_recorded'
       and coalesce(new.data ->> 'attempt_number', '') ~ '^[0-9]+$' then
        result_attempt := (new.data ->> 'attempt_number')::integer;
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
            'removed_from_class'
        ) then 'email'
        when new.type = 'quiz_result_recorded' and result_attempt = 1 then 'email'
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
        notification_id, user_id, channel, event_type, recipient_email,
        recipient_name, title, message, action_url, data, delivery_key
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
              or sessions.due_at between timezone('utc', now()) and timezone('utc', now()) + interval '24 hours'
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

        if assignment_row.due_at between timezone('utc', now()) and timezone('utc', now()) + interval '24 hours' then
            notification_id := public.create_notification(
                assignment_row.student_id,
                'quiz_due_soon',
                'Quiz due within 24 hours',
                coalesce(assignment_row.topic, 'A quiz') || ' is due soon.',
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
