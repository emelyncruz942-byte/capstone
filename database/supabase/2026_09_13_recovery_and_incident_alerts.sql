-- Run after all earlier forward migrations. Trash retains the original rows,
-- IDs, children, version history, rosters, assignments and results in place.
begin;
set local search_path = pg_catalog, public;

alter table public.classes add column if not exists deleted_at timestamptz,
    add column if not exists deleted_by uuid references public.profiles(id) on delete set null;
alter table public.quizzes add column if not exists deleted_at timestamptz,
    add column if not exists deleted_by uuid references public.profiles(id) on delete set null;
alter table public.profiles add column if not exists deactivated_at timestamptz,
    add column if not exists deactivated_by uuid references public.profiles(id) on delete set null,
    add column if not exists deactivation_was_suspended boolean not null default false,
    add column if not exists reactivation_tokens_invalid_before timestamptz,
    add column if not exists purge_intent_id uuid;
create index if not exists classes_trash_idx on public.classes (deleted_at desc) where deleted_at is not null;
create index if not exists quizzes_trash_idx on public.quizzes (deleted_at desc) where deleted_at is not null;
create index if not exists profiles_deactivated_idx on public.profiles (deactivated_at desc) where deactivated_at is not null;

-- Restrictive policies cannot be overridden by a legacy permissive policy.
drop policy if exists classes_not_trashed on public.classes;
create policy classes_not_trashed on public.classes as restrictive to authenticated
    using (deleted_at is null) with check (deleted_at is null);
drop policy if exists quizzes_not_trashed on public.quizzes;
create policy quizzes_not_trashed on public.quizzes as restrictive to authenticated
    using (deleted_at is null) with check (deleted_at is null);
revoke delete on public.classes, public.quizzes from public, anon, authenticated, service_role;

-- A previously issued JWT must not retain Data API access after deactivation.
create or replace function public.recovery_account_active() returns boolean
language sql stable security definer set search_path = pg_catalog, public
as $$ select exists (select 1 from public.profiles where id = auth.uid()
    and deactivated_at is null and suspended_at is null
    and (reactivation_tokens_invalid_before is null
        or coalesce(nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'iat',
            nullif(current_setting('request.jwt.claim.iat', true), ''), '0')::bigint
            > floor(extract(epoch from reactivation_tokens_invalid_before))::bigint)); $$;
revoke all on function public.recovery_account_active() from public, anon, service_role;
grant execute on function public.recovery_account_active() to authenticated;
do $policies$
declare relation_record record;
begin
    for relation_record in select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind in ('r', 'p')
          and c.relname not like 'rollback_%'
          and not exists (select 1 from pg_depend d where d.classid = 'pg_class'::regclass
              and d.objid = c.oid and d.deptype = 'e')
    loop
        execute format('drop policy if exists recovery_active_account on public.%I', relation_record.relname);
        execute format('create policy recovery_active_account on public.%I as restrictive to authenticated
            using ((select public.recovery_account_active())) with check ((select public.recovery_account_active()))', relation_record.relname);
    end loop;
end;
$policies$;

create or replace function public.recovery_guard() returns trigger
language plpgsql security definer set search_path = pg_catalog, public
as $$
begin
    if tg_op = 'DELETE' then
        raise exception 'Move this record to Trash instead of permanently deleting it' using errcode = '42501';
    end if;
    if tg_op = 'INSERT' then
        if new.deleted_at is not null or new.deleted_by is not null then
            raise exception 'New records cannot start in Trash' using errcode = '42501';
        end if;
    elsif (new.deleted_at is distinct from old.deleted_at or new.deleted_by is distinct from old.deleted_by)
        and current_setting('mathverse.recovery_transition', true) is distinct from 'on' then
        raise exception 'Use the audited recovery workflow' using errcode = '42501';
    end if;
    if tg_op = 'UPDATE' and old.deleted_at is not null and new.deleted_at is not null then
        if tg_table_name = 'classes' then
            if new.archived_at is null then raise exception 'Restore the class from Trash before reactivating it'; end if;
        elsif tg_table_name = 'quizzes' then
            if new.topic is distinct from old.topic or new.grade_level is distinct from old.grade_level
                or new.visibility is distinct from old.visibility then
                raise exception 'Restore the quiz from Trash before editing it';
            end if;
        end if;
    end if;
    return new;
end;
$$;
create or replace function public.recovery_session_state_guard() returns trigger
language plpgsql security definer set search_path = pg_catalog, public
as $$ begin
    if new.class_id is not null and new.status in ('waiting', 'active') then
        perform 1 from public.classes where id = new.class_id and deleted_at is null and archived_at is null for share;
        if not found then raise exception 'A quiz in an archived or trashed class cannot restart'; end if;
    end if;
    return new;
end; $$;
drop trigger if exists quiz_sessions_recovery_state_guard on public.quiz_sessions;
create trigger quiz_sessions_recovery_state_guard before update of status on public.quiz_sessions
    for each row execute function public.recovery_session_state_guard();
drop trigger if exists classes_recovery_guard on public.classes;
create trigger classes_recovery_guard before insert or update or delete on public.classes
    for each row execute function public.recovery_guard();
drop trigger if exists quizzes_recovery_guard on public.quizzes;
create trigger quizzes_recovery_guard before insert or update or delete on public.quizzes
    for each row execute function public.recovery_guard();

create or replace function public.recovery_quiz_content_guard() returns trigger
language plpgsql security definer set search_path = pg_catalog, public
as $$ begin
    if tg_op <> 'INSERT' then
        perform 1 from public.quizzes where id = old.quiz_id and deleted_at is null for share;
        if not found then raise exception 'Restore the quiz from Trash before changing its content'; end if;
    end if;
    if tg_op <> 'DELETE' then
        perform 1 from public.quizzes where id = new.quiz_id and deleted_at is null for share;
        if not found then raise exception 'Restore the quiz from Trash before changing its content'; end if;
        return new;
    end if;
    return old;
end; $$;
drop trigger if exists quiz_questions_recovery_guard on public.quiz_questions;
create trigger quiz_questions_recovery_guard before insert or update or delete on public.quiz_questions
    for each row execute function public.recovery_quiz_content_guard();
drop trigger if exists quiz_versions_recovery_guard on public.quiz_versions;
create trigger quiz_versions_recovery_guard before insert or update or delete on public.quiz_versions
    for each row execute function public.recovery_quiz_content_guard();

-- Also protect server RPCs from stale tabs assigning trashed quiz content.
create or replace function public.recovery_assignment_guard() returns trigger
language plpgsql security definer set search_path = pg_catalog, public
as $$
begin
    if new.source_quiz_id is not null then
        perform 1 from public.quizzes where id = new.source_quiz_id and deleted_at is null for share;
        if not found then raise exception 'The source quiz is in Trash or unavailable'; end if;
    end if;
    if new.class_id is not null then
        perform 1 from public.classes where id = new.class_id and deleted_at is null and archived_at is null for share;
        if not found then raise exception 'The class is archived, in Trash or unavailable'; end if;
    end if;
    return new;
end;
$$;
drop trigger if exists quiz_sessions_recovery_guard on public.quiz_sessions;
create trigger quiz_sessions_recovery_guard before insert or update of source_quiz_id, class_id on public.quiz_sessions
    for each row execute function public.recovery_assignment_guard();

create or replace function public.set_recovery_item(p_actor_id uuid, p_kind text, p_id uuid, p_restore boolean)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$
declare actor_record record; item_record record; audit_id uuid := gen_random_uuid(); action_name text;
begin
    if p_restore is null then raise exception 'A recovery action is required'; end if;
    select id, role, concat_ws(' ', first_name, last_name) as name into actor_record from public.profiles
        where id = p_actor_id and role in ('teacher', 'admin') and suspended_at is null and deactivated_at is null for share;
    if not found then raise exception 'A current teacher or administrator is required' using errcode = '42501'; end if;
    if p_kind = 'class' then
        select id, teacher_id, class_name as label, deleted_at, deleted_by into item_record from public.classes where id = p_id for update;
        if not found then raise exception 'Record not found'; end if;
        if actor_record.role <> 'admin' and item_record.teacher_id <> p_actor_id then
            raise exception 'Record not found' using errcode = '42501';
        end if;
    elsif p_kind = 'quiz' then
        select id, teacher_id, topic as label, deleted_at, deleted_by, visibility into item_record from public.quizzes where id = p_id for update;
        if not found then raise exception 'Record not found'; end if;
        if item_record.teacher_id <> p_actor_id and (actor_record.role <> 'admin' or item_record.visibility <> 'shared') then
            raise exception 'Record not found' using errcode = '42501';
        end if;
    else raise exception 'Invalid recovery item'; end if;
    if p_restore and actor_record.role <> 'admin' and item_record.deleted_by is distinct from p_actor_id then
        raise exception 'Administrator restoration required' using errcode = '42501';
    end if;
    if coalesce(p_restore, false) = (item_record.deleted_at is null) then
        raise exception 'The record is not in the required recovery state';
    end if;
    perform set_config('mathverse.recovery_transition', 'on', true);
    if p_kind = 'class' then
        -- Finish live sessions BEFORE archiving. Restore never revives timers,
        -- eligibility or a stale student profile's active-class pointer.
        if not p_restore then
            update public.quiz_sessions set status = 'completed', is_active = false, retake_mode = false,
                ended_at = coalesce(ended_at, now()) where class_id = p_id and status in ('waiting', 'active');
            update public.profiles set class_id = null where class_id = p_id;
        end if;
        update public.classes set deleted_at = case when p_restore then null else now() end,
            deleted_by = case when p_restore then null else p_actor_id end,
            archived_at = coalesce(archived_at, now()) where id = p_id;
    else
        update public.quizzes set deleted_at = case when p_restore then null else now() end,
            deleted_by = case when p_restore then null else p_actor_id end where id = p_id;
        if not p_restore and actor_record.role = 'admin' then
            update public.quiz_reports set status = 'reviewed', reviewed_by = p_actor_id, reviewed_at = now()
                where quiz_id = p_id and status = 'pending';
        end if;
    end if;
    perform set_config('mathverse.recovery_transition', 'off', true);
    action_name := p_kind || case when p_restore then '.trash_restored' else '.trashed' end;
    -- Outcome and mutation commit together, including teacher-originated trash.
    insert into public.audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id,
        metadata, event_category, severity, outcome, correlation_id)
    values (p_actor_id, actor_record.role, actor_record.name, action_name, p_kind, p_id::text,
        jsonb_build_object('label', item_record.label, 'restored_as_archived', p_kind = 'class' and p_restore),
        'security', 'high', 'succeeded', audit_id);
    return jsonb_build_object('id', p_id, 'restored', p_restore, 'correlation_id', audit_id);
end;
$$;

-- Old clients/previous app releases must also be recoverable, not destructive.
create or replace function public.delete_teacher_class(p_teacher_id uuid, p_class_id uuid)
returns table (deleted_class_id uuid) language plpgsql security definer set search_path = pg_catalog, public
as $$ begin
    perform public.set_recovery_item(p_teacher_id, 'class', p_class_id, false);
    return query select p_class_id;
end; $$;

create or replace function public.set_account_deactivated(p_actor_id uuid, p_id uuid, p_restore boolean)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$
declare actor_record record; target_record public.profiles%rowtype; audit_id uuid := gen_random_uuid();
begin
    if p_restore is null then raise exception 'A recovery action is required'; end if;
    select role, concat_ws(' ', first_name, last_name) as name into actor_record from public.profiles
        where id = p_actor_id and role = 'admin' and suspended_at is null and deactivated_at is null for share;
    if not found then raise exception 'A current administrator is required' using errcode = '42501'; end if;
    select * into target_record from public.profiles where id = p_id and role in ('student', 'teacher', 'pending_teacher') for update;
    if not found then raise exception 'Account not found'; end if;
    if target_record.purge_intent_id is not null then raise exception 'Permanent deletion is already pending'; end if;
    if coalesce(p_restore, false) = (target_record.deactivated_at is null) then raise exception 'Invalid deactivation state'; end if;
    update public.profiles set
        deactivated_at = case when p_restore then null else now() end,
        deactivated_by = case when p_restore then null else p_actor_id end,
        deactivation_was_suspended = case when p_restore then false else suspended_at is not null end,
        suspended_at = case when p_restore and not target_record.deactivation_was_suspended then null else coalesce(suspended_at, now()) end,
        reactivation_tokens_invalid_before = case when p_restore then now() else reactivation_tokens_invalid_before end,
        auth_sessions_invalid_before = now() where id = p_id;
    insert into public.audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id,
        metadata, event_category, severity, outcome, correlation_id)
    values (p_actor_id, 'admin', actor_record.name,
        case when p_restore then 'user.reactivated' else 'user.deactivated' end, 'profile', p_id::text,
        jsonb_build_object('role', target_record.role, 'previously_suspended',
            case when p_restore then target_record.deactivation_was_suspended else target_record.suspended_at is not null end),
        'security', 'high', 'succeeded', audit_id);
    return jsonb_build_object('id', p_id, 'restored', p_restore, 'correlation_id', audit_id);
end;
$$;

create or replace function public.prepare_account_purge(p_actor_id uuid, p_id uuid)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$
declare target_record public.profiles%rowtype; audit_id uuid;
begin
    select * into target_record from public.profiles where id = p_id for update;
    if not found or target_record.role not in ('student', 'teacher', 'pending_teacher')
        or target_record.deactivated_at is null or target_record.deactivated_at > now() - interval '7 days'
        or target_record.purge_intent_id is not null then raise exception 'Deactivate the account for seven days before permanent deletion'; end if;
    if exists (select 1 from public.classes where teacher_id = p_id)
       or exists (select 1 from public.quizzes where teacher_id = p_id) then
        raise exception 'This account still owns retained classes or quizzes. Preserve or transfer those records before permanent deletion';
    end if;
    audit_id := (public.create_privileged_audit_intent(p_actor_id, 'user.permanently_deleted', 'profile', p_id::text,
        jsonb_build_object('role', target_record.role, 'deactivated_at', target_record.deactivated_at))->>'intent_id')::uuid;
    update public.profiles set purge_intent_id = audit_id where id = p_id;
    return jsonb_build_object('intent_id', audit_id);
end;
$$;

create or replace function public.cancel_account_purge(p_actor_id uuid, p_id uuid, p_intent_id uuid)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$ begin
    perform 1 from public.profiles where id = p_actor_id and role = 'admin' and suspended_at is null and deactivated_at is null;
    if not found then raise exception 'A current administrator is required'; end if;
    update public.profiles set purge_intent_id = null where id = p_id and purge_intent_id = p_intent_id;
    if not found then raise exception 'Pending permanent deletion not found'; end if;
    perform public.complete_privileged_audit_intent(p_intent_id, false, '{"account_remains_deactivated":true}'::jsonb, 'Authentication deletion did not complete');
    return jsonb_build_object('cancelled', true);
end; $$;

revoke all on function public.recovery_guard() from public, anon, authenticated, service_role;
revoke all on function public.recovery_assignment_guard() from public, anon, authenticated, service_role;
revoke all on function public.recovery_session_state_guard() from public, anon, authenticated, service_role;
revoke all on function public.recovery_quiz_content_guard() from public, anon, authenticated, service_role;
revoke all on function public.set_recovery_item(uuid, text, uuid, boolean) from public, anon, authenticated;
revoke all on function public.set_account_deactivated(uuid, uuid, boolean) from public, anon, authenticated;
revoke all on function public.prepare_account_purge(uuid, uuid) from public, anon, authenticated;
revoke all on function public.cancel_account_purge(uuid, uuid, uuid) from public, anon, authenticated;
grant execute on function public.set_recovery_item(uuid, text, uuid, boolean) to service_role;
grant execute on function public.set_account_deactivated(uuid, uuid, boolean) to service_role;
grant execute on function public.prepare_account_purge(uuid, uuid) to service_role;
grant execute on function public.cancel_account_purge(uuid, uuid, uuid) to service_role;

create table if not exists public.incident_events (
    id uuid primary key default gen_random_uuid(),
    reference_id text not null unique check (reference_id ~ '^MV-[A-F0-9]{16}$'),
    kind text not null check (kind in ('error', 'auth_failure', 'access_denied', 'rate_limited', 'action_failure')),
    actor_id uuid references public.profiles(id) on delete set null,
    subject_hash text not null check (subject_hash ~ '^[a-f0-9]{64}$'),
    network_hash text not null check (network_hash ~ '^[a-f0-9]{64}$'),
    route_name text not null check (char_length(route_name) <= 200),
    http_status integer not null check (http_status between 200 and 599),
    created_at timestamptz not null default now()
);
create index if not exists incident_events_recent_idx on public.incident_events (created_at desc, kind);
create index if not exists incident_events_subject_idx on public.incident_events (subject_hash, created_at desc);
create index if not exists incident_events_network_idx on public.incident_events (network_hash, created_at desc);

create table if not exists public.system_incidents (
    id uuid primary key default gen_random_uuid(),
    signal_key text not null unique check (char_length(signal_key) between 1 and 100),
    severity text not null check (severity in ('warning', 'critical')),
    status text not null default 'open' check (status in ('open', 'acknowledged', 'resolved')),
    summary text not null check (char_length(summary) between 1 and 600),
    metrics jsonb not null default '{}'::jsonb,
    first_seen_at timestamptz not null default now(), last_seen_at timestamptz not null default now(),
    acknowledged_at timestamptz, acknowledged_by uuid references public.profiles(id) on delete set null,
    resolved_at timestamptz, last_notified_at timestamptz,
    notification_generation integer not null default 1,
    delivered_channels jsonb not null default '{}'::jsonb,
    notification_attempts integer not null default 0,
    notification_error text, notification_retry_at timestamptz,
    notification_lock uuid, notification_locked_until timestamptz
);
alter table public.incident_events enable row level security;
alter table public.system_incidents enable row level security;
revoke all on public.incident_events, public.system_incidents from public, anon, authenticated, service_role;
grant select, insert on public.incident_events to service_role;
grant select on public.system_incidents to service_role;

create or replace function public.incident_signal_counts(p_window_seconds integer default 600)
returns jsonb language sql stable security definer set search_path = pg_catalog, public
as $$
with recent as (select * from public.incident_events where created_at >= now()
    - make_interval(secs => greatest(60, least(coalesce(p_window_seconds, 600), 3600)))),
subject_failures as (select count(*) as n from recent where kind in ('auth_failure', 'access_denied', 'rate_limited') group by subject_hash),
network_failures as (select count(*) as n from recent where kind in ('auth_failure', 'access_denied', 'rate_limited') group by network_hash),
admin_activity as (select actor_id, count(*) as n from public.audit_logs
    where created_at >= now() - make_interval(secs => greatest(60, least(coalesce(p_window_seconds, 600), 3600)))
      and actor_role = 'admin' and event_category = 'security' and outcome <> 'failed'
      and action in ('user.suspended', 'user.deactivated', 'user.permanently_deleted', 'user.deleted',
        'teacher.approved', 'teacher.rejected', 'quiz.trashed', 'class.trashed') group by actor_id)
select jsonb_build_object('errors', (select count(*) from recent where kind = 'error'),
    'action_failures', (select count(*) from recent where kind = 'action_failure'),
    'repeated_failures', greatest(coalesce((select max(n) from subject_failures), 0), coalesce((select max(n) from network_failures), 0)),
    'admin_actions', coalesce((select max(n) from admin_activity), 0),
    'delivery_failures', (select count(*) from public.notification_deliveries where status = 'failed' and event_type <> 'incident_alert'),
    'stuck_deliveries', (select count(*) from public.notification_deliveries where status = 'sending'
        and locked_at < now() - interval '10 minutes' and event_type <> 'incident_alert'),
    'recent_references', coalesce((select jsonb_agg(reference_id) from (select reference_id from recent
        where kind = 'error' order by created_at desc limit 5) refs), '[]'::jsonb));
$$;

-- One lock shared by scheduler, external monitor and concurrent deployments.
-- Persist each successful channel, so a failed webhook does not resend email.
create or replace function public.sync_incident_signal(p_key text, p_active boolean, p_severity text,
    p_summary text, p_metrics jsonb, p_repeat_seconds integer default 3600)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$
declare incident public.system_incidents%rowtype; lease uuid := gen_random_uuid(); new_batch boolean;
begin
    if p_key is null or char_length(p_key) not between 1 and 100 or p_severity not in ('warning', 'critical')
       or p_summary is null or char_length(p_summary) not between 1 and 600
       or jsonb_typeof(p_metrics) is distinct from 'object' then raise exception 'Invalid incident signal'; end if;
    if not coalesce(p_active, false) then
        update public.system_incidents set status = 'resolved', resolved_at = now(), last_seen_at = now(),
            notification_lock = null, notification_locked_until = null where signal_key = p_key and status <> 'resolved';
        return jsonb_build_object('notify', false);
    end if;
    insert into public.system_incidents (signal_key, severity, summary, metrics)
        values (p_key, p_severity, p_summary, p_metrics) on conflict (signal_key) do nothing;
    select * into incident from public.system_incidents where signal_key = p_key for update;
    new_batch := incident.status = 'resolved' or (p_severity = 'critical' and incident.severity <> 'critical')
        or (incident.last_notified_at is not null and incident.last_notified_at <= now()
            - make_interval(secs => greatest(300, least(coalesce(p_repeat_seconds, 3600), 86400))));
    -- Do not steal a live notification lease even during severity escalation.
    if incident.notification_locked_until > now() then
        update public.system_incidents set last_seen_at = now(), summary = p_summary, metrics = p_metrics where id = incident.id;
        return jsonb_build_object('notify', false);
    end if;
    update public.system_incidents set severity = p_severity, summary = p_summary, metrics = p_metrics, last_seen_at = now(),
        status = case when incident.status = 'resolved' or (p_severity = 'critical' and incident.severity <> 'critical') then 'open' else status end,
        first_seen_at = case when incident.status = 'resolved' then now() else first_seen_at end,
        resolved_at = null,
        acknowledged_at = case when new_batch then null else acknowledged_at end,
        acknowledged_by = case when new_batch then null else acknowledged_by end,
        delivered_channels = case when new_batch then '{}'::jsonb else delivered_channels end,
        notification_generation = notification_generation + case when new_batch then 1 else 0 end,
        notification_attempts = case when new_batch then 0 else notification_attempts end,
        notification_retry_at = case when new_batch then null else notification_retry_at end,
        last_notified_at = case when new_batch then null else last_notified_at end
        where id = incident.id returning * into incident;
    if incident.status = 'acknowledged' or incident.last_notified_at is not null
       or incident.notification_retry_at > now() then return jsonb_build_object('notify', false); end if;
    update public.system_incidents set notification_lock = lease, notification_locked_until = now() + interval '5 minutes'
        where id = incident.id;
    return to_jsonb(incident) || jsonb_build_object('notify', true, 'lease', lease);
end;
$$;

create or replace function public.finish_incident_notification(p_id uuid, p_lease uuid, p_channels jsonb, p_complete boolean, p_error text)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$ begin
    if jsonb_typeof(p_channels) is distinct from 'object' then raise exception 'Invalid notification channels'; end if;
    update public.system_incidents set delivered_channels = delivered_channels || p_channels,
        last_notified_at = case when p_complete then now() else null end,
        notification_attempts = notification_attempts + 1,
        notification_error = nullif(left(p_error, 500), ''),
        notification_retry_at = case when p_complete then null else now()
            + case when notification_attempts >= 4 then interval '1 hour' else interval '2 minutes' end end,
        notification_lock = null, notification_locked_until = null
        where id = p_id and notification_lock = p_lease;
    return jsonb_build_object('completed', found);
end; $$;

create or replace function public.notify_incident_admins(p_id uuid, p_generation integer)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$ declare incident public.system_incidents%rowtype; begin
    select * into incident from public.system_incidents where id = p_id and notification_generation = p_generation and status = 'open';
    if not found then return jsonb_build_object('saved', false); end if;
    perform public.notify_all_admins('incident_alert', 'MathVerse ' || incident.severity || ' incident',
        left(incident.summary || ' Incident: ' || p_id::text, 600), '/admin/incidents',
        jsonb_build_object('incident_id', p_id), 'incident:' || p_id::text || ':' || p_generation::text);
    return jsonb_build_object('saved', true);
end; $$;

create or replace function public.acknowledge_system_incident(p_actor_id uuid, p_id uuid)
returns jsonb language plpgsql security definer set search_path = pg_catalog, public
as $$
declare actor_name text;
begin
    select concat_ws(' ', first_name, last_name) into actor_name from public.profiles
        where id = p_actor_id and role = 'admin' and suspended_at is null and deactivated_at is null;
    if not found then raise exception 'A current administrator is required'; end if;
    update public.system_incidents set status = 'acknowledged', acknowledged_at = now(), acknowledged_by = p_actor_id
        where id = p_id and status = 'open';
    if not found then raise exception 'Open incident not found'; end if;
    insert into public.audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id,
        metadata, event_category, severity, outcome)
    values (p_actor_id, 'admin', actor_name, 'incident.acknowledged', 'system_incident', p_id::text,
        '{}'::jsonb, 'security', 'high', 'succeeded');
    return jsonb_build_object('acknowledged', true);
end; $$;

create or replace function public.prune_incident_events() returns bigint
language plpgsql security definer set search_path = pg_catalog, public
as $$ declare removed bigint; begin
    delete from public.incident_events where created_at < now() - interval '30 days';
    get diagnostics removed = row_count;
    return removed;
end; $$;
revoke all on function public.incident_signal_counts(integer) from public, anon, authenticated;
revoke all on function public.sync_incident_signal(text, boolean, text, text, jsonb, integer) from public, anon, authenticated;
revoke all on function public.finish_incident_notification(uuid, uuid, jsonb, boolean, text) from public, anon, authenticated;
revoke all on function public.acknowledge_system_incident(uuid, uuid) from public, anon, authenticated;
revoke all on function public.prune_incident_events() from public, anon, authenticated;
revoke all on function public.notify_incident_admins(uuid, integer) from public, anon, authenticated;
grant execute on function public.incident_signal_counts(integer) to service_role;
grant execute on function public.sync_incident_signal(text, boolean, text, text, jsonb, integer) to service_role;
grant execute on function public.finish_incident_notification(uuid, uuid, jsonb, boolean, text) to service_role;
grant execute on function public.acknowledge_system_incident(uuid, uuid) to service_role;
grant execute on function public.prune_incident_events() to service_role;
grant execute on function public.notify_incident_admins(uuid, integer) to service_role;

insert into public.mathverse_schema_migrations (migration_key)
values ('2026_09_13_recovery_and_incident_alerts.sql') on conflict (migration_key) do nothing;
commit;
