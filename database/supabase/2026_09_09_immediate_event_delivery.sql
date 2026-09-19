-- Send quiz-availability alerts by Web Push and start user-requested emails
-- immediately while retaining the protected outbox for automatic retries.
-- Run after 2026_09_07_quiz_assignment_web_push.sql.

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

-- pg_net queues the receipt callback after the surrounding result transaction
-- commits, so email latency does not slow or roll back a VR quiz submission.
create extension if not exists pg_net with schema extensions;

alter table public.notification_deliveries
    add column if not exists dispatch_token uuid;

update public.notification_deliveries
set dispatch_token = uuid_generate_v4()
where dispatch_token is null;

alter table public.notification_deliveries
    alter column dispatch_token set default uuid_generate_v4(),
    alter column dispatch_token set not null;

create unique index if not exists notification_deliveries_dispatch_token_idx
    on public.notification_deliveries (dispatch_token);

alter table public.notification_deliveries enable row level security;
revoke all on table public.notification_deliveries
from public, anon, authenticated;
grant all on table public.notification_deliveries
to service_role;

-- Avoid a delivery-key collision if a matching Web Push row was already made
-- manually for the same notification.
delete from public.notification_deliveries email_delivery
using public.notification_deliveries push_delivery
where email_delivery.event_type = 'quiz_started'
  and email_delivery.channel = 'email'
  and email_delivery.status in ('pending', 'failed')
  and push_delivery.notification_id = email_delivery.notification_id
  and push_delivery.channel = 'web_push';

-- Preserve unsent availability alerts but move them out of SMTP.
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
where event_type = 'quiz_started'
  and channel = 'email'
  and status in ('pending', 'failed')
  and user_id is not null;

delete from public.notification_deliveries
where event_type = 'quiz_started'
  and channel = 'email'
  and status in ('pending', 'failed')
  and user_id is null;

create or replace function public.queue_notification_delivery()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog, public
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

-- Quiz results can be inserted directly by the VR client, outside a Laravel
-- request. A row-scoped, random capability lets pg_net ask Laravel to claim
-- that exact receipt immediately without exposing SMTP or service credentials.
create or replace function public.request_immediate_quiz_receipt_delivery()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
begin
    if new.event_type <> 'quiz_result_recorded' or new.channel <> 'email' then
        return new;
    end if;

    begin
        perform net.http_post(
            url := 'https://mathmetaverse.space/api/notification-deliveries/quiz-receipt',
            headers := jsonb_build_object(
                'Content-Type', 'application/json',
                'User-Agent', 'MathVerse-Database-Delivery/1.0'
            ),
            body := jsonb_build_object(
                'delivery_id', new.id,
                'dispatch_token', new.dispatch_token
            ),
            timeout_milliseconds := 30000
        );
    exception when others then
        -- Never make an accepted quiz result fail because the callback could
        -- not be queued. The minute worker remains the durable fallback.
        null;
    end;

    return new;
end;
$$;

drop trigger if exists notification_deliveries_immediate_quiz_receipt
on public.notification_deliveries;
create trigger notification_deliveries_immediate_quiz_receipt
after insert on public.notification_deliveries
for each row execute function public.request_immediate_quiz_receipt_delivery();

revoke all on function public.request_immediate_quiz_receipt_delivery()
from public, anon, authenticated;

notify pgrst, 'reload schema';

commit;
