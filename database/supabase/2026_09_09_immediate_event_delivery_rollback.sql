-- Roll back immediate receipt callbacks and restore quiz-availability email.
-- Already sent email and Web Push alerts cannot be retracted.

begin;

set local search_path = public, extensions;

drop trigger if exists notification_deliveries_immediate_quiz_receipt
on public.notification_deliveries;
drop function if exists public.request_immediate_quiz_receipt_delivery();

-- Avoid conflicts if an email row for the same notification already exists.
delete from public.notification_deliveries push_delivery
using public.notification_deliveries email_delivery
where push_delivery.event_type = 'quiz_started'
  and push_delivery.channel = 'web_push'
  and push_delivery.status in ('pending', 'failed')
  and email_delivery.notification_id = push_delivery.notification_id
  and email_delivery.channel = 'email';

update public.notification_deliveries deliveries
set channel = 'email',
    recipient_email = profiles.email,
    recipient_name = coalesce(
        nullif(btrim(concat_ws(' ', profiles.first_name, profiles.last_name)), ''),
        profiles.username,
        deliveries.recipient_name,
        ''
    ),
    delivery_key = case
        when deliveries.notification_id is not null
            then 'notification:' || deliveries.notification_id::text || ':email'
        else deliveries.delivery_key || ':email'
    end,
    status = 'pending',
    attempts = 0,
    available_at = timezone('utc', now()),
    locked_at = null,
    locked_by = null,
    last_error = null,
    updated_at = timezone('utc', now())
from public.profiles profiles
where deliveries.user_id = profiles.id
  and deliveries.event_type = 'quiz_started'
  and deliveries.channel = 'web_push'
  and deliveries.status in ('pending', 'failed')
  and nullif(btrim(profiles.email), '') is not null;

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

drop index if exists public.notification_deliveries_dispatch_token_idx;
alter table public.notification_deliveries
    drop column if exists dispatch_token;

-- pg_net is intentionally retained because another database feature may use it.

notify pgrst, 'reload schema';

commit;
