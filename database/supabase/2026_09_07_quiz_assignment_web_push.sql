-- Route high-volume quiz-assignment alerts to Web Push instead of email.
-- Run after 2026_09_05_security_hardening.sql.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.notifications') is null
       or to_regclass('public.notification_deliveries') is null
       or to_regclass('public.profiles') is null then
        raise exception 'The notification delivery layer is missing. Run the earlier notification migrations first.';
    end if;
end
$$;

-- Avoid a unique-key conflict if an earlier manual operation already created
-- a Web Push delivery for the same bell notification.
delete from public.notification_deliveries email_delivery
using public.notification_deliveries push_delivery
where email_delivery.event_type = 'quiz_assigned'
  and email_delivery.channel = 'email'
  and email_delivery.status in ('pending', 'failed')
  and push_delivery.notification_id = email_delivery.notification_id
  and push_delivery.channel = 'web_push';

-- Preserve unsent assignment alerts while moving them out of the SMTP queue.
update public.notification_deliveries
set channel = 'web_push',
    recipient_email = null,
    delivery_key = case
        when notification_id is not null
            then 'notification:' || notification_id::text || ':web_push'
        else delivery_key || ':web_push'
    end,
    status = 'pending',
    attempts = 0,
    available_at = timezone('utc', now()),
    locked_at = null,
    locked_by = null,
    last_error = null,
    updated_at = timezone('utc', now())
where event_type = 'quiz_assigned'
  and channel = 'email'
  and status in ('pending', 'failed')
  and user_id is not null;

-- A deleted account cannot receive Web Push and should not retain a stale
-- assignment email merely because its profile no longer exists.
delete from public.notification_deliveries
where event_type = 'quiz_assigned'
  and channel = 'email'
  and status in ('pending', 'failed')
  and user_id is null;

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
    -- Laravel sends these administrator broadcasts directly. Auth security
    -- messages already have their own provider-managed email delivery.
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

revoke all on function public.queue_notification_delivery()
from public, anon, authenticated;
grant execute on function public.queue_notification_delivery()
to service_role;

commit;
