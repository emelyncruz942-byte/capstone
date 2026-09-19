-- Reliable email/Web Push outbox for MathVerse notification events.
-- Run after 2026_08_31_notifications_and_account_security.sql.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.notifications') is null
       or to_regclass('public.profiles') is null then
        raise exception 'The MathVerse notification layer is missing. Run 2026_08_31_notifications_and_account_security.sql first.';
    end if;
    if to_regprocedure('public.require_explicit_quiz_retake()') is null
       or not exists (
           select 1
           from pg_trigger
           where tgrelid = 'public.quiz_results'::regclass
             and tgname = 'quiz_results_00_explicit_retake_guard'
             and not tgisinternal
       ) then
        raise exception 'The explicit-retake guard is missing. Run 2026_08_30_assignment_usage_and_attempt_integrity.sql first.';
    end if;
end
$$;

create table if not exists public.notification_deliveries (
    id uuid primary key default uuid_generate_v4(),
    notification_id uuid references public.notifications(id) on delete set null,
    user_id uuid references public.profiles(id) on delete set null,
    channel text not null check (channel in ('email', 'web_push')),
    event_type text not null check (char_length(event_type) between 1 and 80),
    recipient_email text,
    recipient_name text,
    title text not null check (char_length(title) between 1 and 140),
    message text not null check (char_length(message) between 1 and 600),
    action_url text check (
        action_url is null
        or (
            char_length(action_url) between 1 and 500
            and action_url like '/%'
            and action_url not like '//%'
        )
    ),
    data jsonb not null default '{}'::jsonb,
    delivery_key text not null unique,
    status text not null default 'pending'
        check (status in ('pending', 'sending', 'sent', 'failed')),
    attempts integer not null default 0 check (attempts between 0 and 5),
    available_at timestamp with time zone not null default timezone('utc', now()),
    locked_at timestamp with time zone,
    locked_by uuid,
    delivered_at timestamp with time zone,
    last_error text,
    created_at timestamp with time zone not null default timezone('utc', now()),
    updated_at timestamp with time zone not null default timezone('utc', now()),
    check (channel <> 'email' or recipient_email is not null)
);

create index if not exists notification_deliveries_pending_idx
    on public.notification_deliveries (available_at, created_at)
    where status in ('pending', 'failed', 'sending') and attempts < 5;
create index if not exists notification_deliveries_user_idx
    on public.notification_deliveries (user_id, created_at desc);

alter table public.notification_deliveries enable row level security;
revoke all on public.notification_deliveries from public, anon, authenticated;
grant all on public.notification_deliveries to service_role;

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
    -- These two browser pushes are sent immediately by the existing Laravel
    -- admin broadcast calls. Password and completed email-address changes are
    -- already sent by Supabase Auth. Excluding all four here prevents duplicate
    -- operating-system alerts while keeping their bell entries available.
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

    -- A legacy profile without an email must never make the originating bell
    -- insert fail; Web Push remains available for that account.
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

drop trigger if exists notifications_queue_delivery on public.notifications;
create trigger notifications_queue_delivery
after insert on public.notifications
for each row execute function public.queue_notification_delivery();

revoke all on function public.queue_notification_delivery()
from public, anon, authenticated;

create or replace function public.claim_notification_deliveries(
    p_limit integer,
    p_worker_id uuid
)
returns setof public.notification_deliveries
language plpgsql
security definer
set search_path = public
as $$
begin
    if p_worker_id is null then
        raise exception 'A delivery worker ID is required';
    end if;

    delete from public.notification_deliveries deliveries
    where (
        deliveries.status = 'sent'
        and deliveries.delivered_at < timezone('utc', now()) - interval '30 days'
    ) or (
        deliveries.status = 'failed'
        and deliveries.attempts >= 5
        and deliveries.updated_at < timezone('utc', now()) - interval '30 days'
    );

    return query
    with candidates as (
        select deliveries.id
        from public.notification_deliveries deliveries
        where deliveries.attempts < 5
          and deliveries.available_at <= timezone('utc', now())
          and (
              deliveries.status in ('pending', 'failed')
              or (
                  deliveries.status = 'sending'
                  and deliveries.locked_at < timezone('utc', now()) - interval '10 minutes'
              )
          )
        order by deliveries.created_at asc
        for update skip locked
        limit greatest(1, least(coalesce(p_limit, 50), 100))
    )
    update public.notification_deliveries deliveries
    set status = 'sending',
        attempts = deliveries.attempts + 1,
        locked_at = timezone('utc', now()),
        locked_by = p_worker_id,
        updated_at = timezone('utc', now())
    from candidates
    where deliveries.id = candidates.id
    returning deliveries.*;
end;
$$;

revoke all on function public.claim_notification_deliveries(integer, uuid)
from public, anon, authenticated;
grant execute on function public.claim_notification_deliveries(integer, uuid)
to service_role;

-- Applicant receipt plus the existing administrator bell notification.
create or replace function public.notify_profile_created()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    applicant_name text;
begin
    if new.role = 'pending_teacher' then
        applicant_name := coalesce(
            nullif(btrim(concat_ws(' ', new.first_name, new.last_name)), ''),
            new.email,
            'A teacher applicant'
        );

        perform public.create_notification(
            new.id,
            'teacher_application_received',
            'Teacher application received',
            'We received your MathVerse teacher registration. An administrator will review it before teacher access is enabled.',
            '/',
            jsonb_build_object('submitted_at', new.created_at),
            'teacher-application-received:' || new.id::text
        );

        perform public.notify_all_admins(
            'teacher_verification',
            'Teacher verification requested',
            applicant_name || ' registered and is waiting for approval.',
            '/admin/dashboard?section=role-verify',
            jsonb_build_object('teacher_id', new.id),
            'teacher-verification:' || new.id::text
        );
    end if;

    return new;
end;
$$;

-- Approval, suspension, and restoration all create user-facing events.
create or replace function public.notify_profile_state_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if old.role = 'pending_teacher' and new.role = 'teacher' then
        perform public.create_notification(
            new.id,
            'teacher_approved',
            'Teacher account approved',
            'Your MathVerse teacher application was approved. You can now create classes and quizzes.',
            '/teacher/dashboard',
            '{}'::jsonb,
            'teacher-approved:' || new.id::text
        );
    end if;

    if old.suspended_at is null and new.suspended_at is not null then
        perform public.create_notification(
            new.id,
            'account_suspended',
            'Account suspended',
            'An administrator suspended your MathVerse account access.',
            null,
            jsonb_build_object('reason', new.suspension_reason),
            'account-suspended:' || new.suspended_at::text
        );
    end if;

    if old.suspended_at is not null and new.suspended_at is null then
        perform public.create_notification(
            new.id,
            'account_restored',
            'Account access restored',
            'An administrator restored your MathVerse account access.',
            case
                when new.role = 'teacher' then '/teacher/dashboard'
                when new.role = 'admin' then '/admin/dashboard'
                else '/student/dashboard'
            end,
            '{}'::jsonb,
            'account-restored:' || old.suspended_at::text
        );
    end if;

    return new;
end;
$$;

-- A normal scheduled/manual opening emails every eligible student. Retake
-- mode is intentionally excluded because its eligibility trigger already
-- creates one targeted retake event for the authorized student.
create or replace function public.notify_quiz_status_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    eligibility_row record;
begin
    if old.status is not distinct from new.status
       or new.status not in ('active', 'completed') then
        return new;
    end if;

    if (new.status = 'active' and new.retake_mode)
       or (new.status = 'completed' and old.retake_mode) then
        return new;
    end if;

    for eligibility_row in
        select student_id
        from public.quiz_session_students
        where session_id = new.id and eligibility_status = 'eligible'
    loop
        perform public.create_notification(
            eligibility_row.student_id,
            case when new.status = 'active' then 'quiz_started' else 'quiz_ended' end,
            case when new.status = 'active' then 'Quiz available' else 'Quiz ended' end,
            coalesce(new.topic, 'Your quiz') || case
                when new.status = 'active' then ' is now available.'
                else ' has ended. Results and answers are now available when permitted.'
            end,
            '/student/classes/' || new.class_id::text,
            jsonb_build_object(
                'session_id', new.id,
                'class_id', new.class_id,
                'available_at', new.available_at,
                'due_at', new.due_at
            ),
            'quiz-status:' || new.id::text || ':' || new.status || ':' || eligibility_row.student_id::text
        );
    end loop;

    return new;
end;
$$;

-- Duplicate client inserts are already stopped by the explicit-retake and
-- allowance guards. Include the server-assigned attempt number so the initial
-- result and each separately teacher-authorized retake receive one receipt.
create or replace function public.notify_quiz_result_created()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    session_row record;
    student_row record;
begin
    select id, teacher_id, class_id, topic into session_row
    from public.quiz_sessions where id = new.session_id;
    select first_name, last_name into student_row
    from public.profiles where id = new.student_id;

    if session_row.id is null then
        return new;
    end if;

    perform public.create_notification(
        new.student_id,
        'quiz_result_recorded',
        'Quiz submission saved',
        coalesce(session_row.topic, 'Quiz') || ': ' || new.correct_answers::text
            || ' of ' || new.total_questions::text || ' correct.',
        '/student/classes/' || session_row.class_id::text,
        jsonb_build_object(
            'session_id', new.session_id,
            'correct_answers', new.correct_answers,
            'total_questions', new.total_questions,
            'attempt_number', coalesce(new.attempt_number, 1),
            'is_counted', new.is_counted
        ),
        'quiz-result-student:' || new.id::text
    );

    perform public.create_notification(
        session_row.teacher_id,
        'quiz_submitted',
        'Student submitted a quiz',
        coalesce(
            nullif(btrim(concat_ws(' ', student_row.first_name, student_row.last_name)), ''),
            'A student'
        ) || ' completed ' || coalesce(session_row.topic, 'a quiz') || '.',
        '/teacher/classes/' || session_row.class_id::text || '/quizzes/' || new.session_id::text || '/results',
        jsonb_build_object(
            'session_id', new.session_id,
            'student_id', new.student_id,
            'attempt_number', coalesce(new.attempt_number, 1)
        ),
        'quiz-result-teacher:' || new.id::text
    );

    return new;
end;
$$;

notify pgrst, 'reload schema';

commit;
