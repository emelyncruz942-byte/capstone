-- Operational health, durable privileged auditing, bounded trophy ranks, and
-- teacher Learning Hub analytics. Run after 2026_09_11_number_guess_game.sql.

begin;
set local search_path = pg_catalog, public;

do $$
begin
    if to_regclass('public.profiles') is null
       or to_regclass('public.classes') is null
       or to_regclass('public.class_members') is null
       or to_regclass('public.audit_logs') is null
       or to_regclass('public.practice_mastery') is null
       or to_regclass('public.practice_questions') is null
       or to_regclass('public.notification_deliveries') is null
       or to_regclass('public.number_guess_scores') is null then
        raise exception 'Run every MathVerse migration through 2026_09_11_number_guess_game.sql first';
    end if;
end
$$;

create table if not exists public.mathverse_schema_migrations (
    migration_key text primary key check (char_length(migration_key) between 1 and 160),
    applied_at timestamptz not null default now()
);

insert into public.mathverse_schema_migrations (migration_key) values
('2026_08_27_reusable_quizzes_and_class_pages.sql'),
('2026_08_28_archived_classes_and_single_attempts.sql'),
('2026_08_29_scheduling_governance_and_scale.sql'),
('2026_08_30_assignment_usage_and_attempt_integrity.sql'),
('2026_08_30_quiz_regression_and_push_hotfix.sql'),
('2026_08_30_remove_stale_accommodation_triggers.sql'),
('2026_08_30_repeated_shared_class_uses_and_assignment_delete.sql'),
('2026_08_30_shared_assignment_and_quiz_reports.sql'),
('2026_08_31_notification_delivery_policy_followup.sql'),
('2026_08_31_notifications_and_account_security.sql'),
('2026_08_31_notifications_delivery_channels.sql'),
('2026_08_31_quiz_starting_soon_5_minutes.sql'),
('2026_09_01_autonomous_learning_hub.sql'),
('2026_09_01_curriculum_topic_focus.sql'),
('2026_09_05_security_hardening.sql'),
('2026_09_07_quiz_assignment_web_push.sql'),
('2026_09_09_immediate_event_delivery.sql'),
('2026_09_11_number_guess_game.sql'),
('2026_09_12_platform_insights_and_durable_audit.sql')
on conflict (migration_key) do nothing;

create table if not exists public.system_heartbeats (
    component text primary key check (char_length(component) between 1 and 80),
    status text not null default 'ok' check (status in ('ok', 'warning', 'failed')),
    details jsonb not null default '{}'::jsonb check (jsonb_typeof(details) = 'object'),
    checked_at timestamptz not null default now()
);

alter table public.mathverse_schema_migrations enable row level security;
alter table public.system_heartbeats enable row level security;
revoke all on public.mathverse_schema_migrations from public, anon, authenticated;
revoke all on public.system_heartbeats from public, anon, authenticated;
grant select on public.mathverse_schema_migrations to service_role;
grant all on public.system_heartbeats to service_role;

alter table public.audit_logs
    add column if not exists actor_name text,
    add column if not exists event_category text not null default 'security',
    add column if not exists severity text not null default 'info',
    add column if not exists outcome text not null default 'succeeded',
    add column if not exists correlation_id uuid;

update public.audit_logs
set actor_name = coalesce(
        actor_name,
        (select nullif(btrim(concat_ws(' ', p.first_name, p.last_name)), '')
           from public.profiles p where p.id = audit_logs.actor_id),
        'System'
    ),
    event_category = case when action = 'page.viewed' then 'activity' else 'security' end,
    severity = case
        when action like 'user.%' or action like 'teacher.%' then 'high'
        when action = 'page.viewed' then 'info'
        else 'medium'
    end
where actor_name is null;

alter table public.audit_logs
    drop constraint if exists audit_logs_event_category_check,
    add constraint audit_logs_event_category_check check (event_category in ('security', 'activity')),
    drop constraint if exists audit_logs_severity_check,
    add constraint audit_logs_severity_check check (severity in ('info', 'medium', 'high', 'critical')),
    drop constraint if exists audit_logs_outcome_check,
    add constraint audit_logs_outcome_check check (outcome in ('pending', 'succeeded', 'failed'));

create index if not exists audit_logs_category_created_idx on public.audit_logs (event_category, created_at desc);
create index if not exists audit_logs_actor_created_idx on public.audit_logs (actor_id, created_at desc);
create index if not exists audit_logs_action_created_idx on public.audit_logs (action, created_at desc);
drop index if exists public.audit_logs_correlation_idx;
create unique index audit_logs_correlation_idx on public.audit_logs (correlation_id) where correlation_id is not null;

create table if not exists public.privileged_audit_outbox (
    id uuid primary key default gen_random_uuid(),
    actor_id uuid references public.profiles(id) on delete set null,
    actor_role text not null,
    actor_name text not null,
    action text not null check (char_length(action) between 1 and 100),
    target_type text not null check (char_length(target_type) between 1 and 80),
    target_id text,
    metadata jsonb not null default '{}'::jsonb check (jsonb_typeof(metadata) = 'object'),
    status text not null default 'pending' check (status in ('pending', 'succeeded', 'failed')),
    completion_metadata jsonb not null default '{}'::jsonb check (jsonb_typeof(completion_metadata) = 'object'),
    last_error text,
    created_at timestamptz not null default now(),
    completed_at timestamptz
);
create index if not exists privileged_audit_outbox_open_idx
    on public.privileged_audit_outbox (created_at) where status = 'pending';
alter table public.privileged_audit_outbox enable row level security;
revoke all on public.privileged_audit_outbox from public, anon, authenticated, service_role;
grant select on public.privileged_audit_outbox to service_role;

create or replace function public.create_privileged_audit_intent(
    p_actor_id uuid, p_action text, p_target_type text,
    p_target_id text default null, p_metadata jsonb default '{}'::jsonb
) returns jsonb
language plpgsql security definer set search_path = pg_catalog, public
as $$
declare
    actor_record record;
    intent_id uuid := gen_random_uuid();
begin
    if p_actor_id is null
       or nullif(btrim(p_action), '') is null or char_length(p_action) > 100
       or nullif(btrim(p_target_type), '') is null or char_length(p_target_type) > 80
       or jsonb_typeof(coalesce(p_metadata, '{}'::jsonb)) <> 'object' then
        raise exception 'Invalid privileged audit intent';
    end if;

    select role, coalesce(nullif(btrim(concat_ws(' ', first_name, last_name)), ''), email, 'Administrator') as display_name
      into actor_record
      from public.profiles
     where id = p_actor_id and role = 'admin' and suspended_at is null;
    if not found then raise exception 'A current administrator is required'; end if;

    insert into public.privileged_audit_outbox
        (id, actor_id, actor_role, actor_name, action, target_type, target_id, metadata)
    values
        (intent_id, p_actor_id, actor_record.role, actor_record.display_name,
         left(btrim(p_action), 100), left(btrim(p_target_type), 80),
         nullif(left(coalesce(p_target_id, ''), 200), ''), coalesce(p_metadata, '{}'::jsonb));

    insert into public.audit_logs
        (actor_id, actor_role, actor_name, action, target_type, target_id,
         metadata, event_category, severity, outcome, correlation_id)
    values
        (p_actor_id, actor_record.role, actor_record.display_name,
         left(btrim(p_action), 100), left(btrim(p_target_type), 80),
         nullif(left(coalesce(p_target_id, ''), 200), ''), coalesce(p_metadata, '{}'::jsonb),
         'security', 'high', 'pending', intent_id);

    return jsonb_build_object('intent_id', intent_id);
end;
$$;

create or replace function public.complete_privileged_audit_intent(
    p_intent_id uuid, p_succeeded boolean,
    p_metadata jsonb default '{}'::jsonb, p_error text default null
) returns jsonb
language plpgsql security definer set search_path = pg_catalog, public
as $$
declare
    intent_record public.privileged_audit_outbox%rowtype;
    final_status text := case when coalesce(p_succeeded, false) then 'succeeded' else 'failed' end;
begin
    if jsonb_typeof(coalesce(p_metadata, '{}'::jsonb)) <> 'object' then
        raise exception 'Invalid privileged audit completion metadata';
    end if;

    select * into intent_record from public.privileged_audit_outbox
     where id = p_intent_id for update;
    if not found then raise exception 'Privileged audit intent not found'; end if;
    if intent_record.status <> 'pending' then
        return jsonb_build_object('completed', intent_record.status = final_status, 'status', intent_record.status);
    end if;

    update public.privileged_audit_outbox
       set status = final_status,
           completion_metadata = coalesce(p_metadata, '{}'::jsonb),
           last_error = nullif(left(coalesce(p_error, ''), 1000), ''),
           completed_at = now()
     where id = p_intent_id;

    update public.audit_logs
       set metadata = intent_record.metadata || coalesce(p_metadata, '{}'::jsonb)
                || jsonb_build_object(
                    'completed_at', now(),
                    'duration_ms', greatest(0, floor(extract(epoch from (now() - intent_record.created_at)) * 1000)::bigint)
                )
                || case when nullif(btrim(coalesce(p_error, '')), '') is null then '{}'::jsonb
                        else jsonb_build_object('error', left(btrim(p_error), 1000)) end,
           severity = case when final_status = 'failed' then 'critical' else 'high' end,
           outcome = final_status
     where correlation_id = intent_record.id and outcome = 'pending';
    if not found then raise exception 'Pending privileged audit event not found'; end if;

    return jsonb_build_object('completed', true, 'status', final_status);
end;
$$;

create or replace function public.search_audit_logs(
    p_search text, p_category text, p_actor_role text, p_action text,
    p_outcome text, p_from timestamptz, p_to timestamptz,
    p_limit integer, p_offset integer
) returns table (
    id bigint, actor_id uuid, actor_role text, actor_name text, action text,
    target_type text, target_id text, metadata jsonb, event_category text,
    severity text, outcome text, correlation_id uuid, created_at timestamptz,
    total_count bigint
) language sql stable security definer set search_path = pg_catalog, public
as $$
    select logs.id, logs.actor_id, logs.actor_role,
        coalesce(nullif(logs.actor_name, ''),
                 nullif(btrim(concat_ws(' ', profiles.first_name, profiles.last_name)), ''),
                 case when logs.actor_id is null then 'System' else 'Former user' end),
        logs.action, logs.target_type, logs.target_id, logs.metadata,
        logs.event_category, logs.severity, logs.outcome, logs.correlation_id,
        logs.created_at, count(*) over ()
    from public.audit_logs logs
    left join public.profiles profiles on profiles.id = logs.actor_id
    where (
        nullif(btrim(coalesce(p_search, '')), '') is null
        or position(lower(btrim(p_search)) in lower(concat_ws(' ',
            coalesce(logs.actor_name, ''), coalesce(profiles.first_name, ''),
            coalesce(profiles.last_name, ''), coalesce(profiles.email, ''),
            logs.action, logs.target_type, coalesce(logs.target_id, ''),
            coalesce(logs.metadata::text, '')
        ))) > 0
    )
      and (nullif(p_category, '') is null or logs.event_category = p_category)
      and (nullif(p_actor_role, '') is null or coalesce(logs.actor_role, 'system') = p_actor_role)
      and (nullif(p_action, '') is null or logs.action = p_action)
      and (nullif(p_outcome, '') is null or logs.outcome = p_outcome)
      and (p_from is null or logs.created_at >= p_from)
      and (p_to is null or logs.created_at < p_to)
    order by logs.created_at desc, logs.id desc
    limit greatest(1, least(coalesce(p_limit, 30), 100))
    offset greatest(0, coalesce(p_offset, 0));
$$;

revoke all on function public.create_privileged_audit_intent(uuid, text, text, text, jsonb) from public, anon, authenticated;
revoke all on function public.complete_privileged_audit_intent(uuid, boolean, jsonb, text) from public, anon, authenticated;
revoke all on function public.search_audit_logs(text, text, text, text, text, timestamptz, timestamptz, integer, integer) from public, anon, authenticated;
grant execute on function public.create_privileged_audit_intent(uuid, text, text, text, jsonb) to service_role;
grant execute on function public.complete_privileged_audit_intent(uuid, boolean, jsonb, text) to service_role;
grant execute on function public.search_audit_logs(text, text, text, text, text, timestamptz, timestamptz, integer, integer) to service_role;

create or replace function public.student_trophy_leaderboard(
    p_student_id uuid, p_limit integer default 10
) returns jsonb
language plpgsql stable security definer set search_path = pg_catalog, public
as $$
declare
    student_grade integer;
    safe_limit integer := greatest(3, least(coalesce(p_limit, 10), 50));
    rows_json jsonb := '[]'::jsonb;
    personal_rank bigint;
begin
    select grade_level into student_grade from public.profiles
     where id = p_student_id and role = 'student' and suspended_at is null;
    if not found or student_grade not between 1 and 6 then raise exception 'Student profile unavailable'; end if;

    with ranked as (
        select profiles.id, profiles.first_name, profiles.last_name,
               profiles.leaderboard_alias, profiles.show_on_leaderboard,
               profiles.trophies, profiles.level, profiles.grade_level,
               row_number() over (order by profiles.trophies desc, profiles.level desc,
                   profiles.last_name asc, profiles.first_name asc, profiles.id asc) as position
        from public.profiles
        where profiles.role = 'student' and profiles.grade_level = student_grade
          and profiles.suspended_at is null
    )
    select coalesce(jsonb_agg(jsonb_build_object(
        'rank', ranked.position, 'id', ranked.id,
        'display_name', case
            when coalesce(ranked.show_on_leaderboard, true) = false and ranked.id <> p_student_id
                then 'Anonymous Student'
            else coalesce(nullif(btrim(ranked.leaderboard_alias), ''),
                nullif(btrim(concat_ws(' ', coalesce(nullif(btrim(ranked.first_name), ''), 'Student'),
                    case when nullif(btrim(ranked.last_name), '') is null then null
                         else left(btrim(ranked.last_name), 1) || '.' end)), ''), 'Student') end,
        'trophies', ranked.trophies, 'level', ranked.level,
        'grade_level', ranked.grade_level, 'is_current', ranked.id = p_student_id
    ) order by ranked.position) filter (where ranked.position <= safe_limit or ranked.id = p_student_id), '[]'::jsonb),
    max(ranked.position) filter (where ranked.id = p_student_id)
    into rows_json, personal_rank from ranked;

    return jsonb_build_object('grade_level', student_grade, 'personal_rank', personal_rank, 'leaderboard', rows_json);
end;
$$;
revoke all on function public.student_trophy_leaderboard(uuid, integer) from public, anon, authenticated;
grant execute on function public.student_trophy_leaderboard(uuid, integer) to service_role;

create or replace function public.teacher_learning_hub_analytics(
    p_teacher_id uuid, p_class_id uuid default null, p_days integer default 30
) returns jsonb
language plpgsql stable security definer set search_path = pg_catalog, public
as $$
declare
    safe_days integer := greatest(7, least(coalesce(p_days, 30), 180));
    period_start timestamptz := now() - make_interval(days => greatest(7, least(coalesce(p_days, 30), 180)));
    result jsonb;
begin
    if not exists (select 1 from public.profiles where id = p_teacher_id and role = 'teacher' and suspended_at is null) then
        raise exception 'Teacher profile unavailable';
    end if;
    if p_class_id is not null and not exists (
        select 1 from public.classes where id = p_class_id and teacher_id = p_teacher_id and archived_at is null
    ) then raise exception 'Class is unavailable'; end if;

    with selected_classes as (
        select id, class_name, grade_level from public.classes
         where teacher_id = p_teacher_id and archived_at is null
           and (p_class_id is null or id = p_class_id)
    ),
    available_classes as (
        select id, class_name, grade_level from public.classes
         where teacher_id = p_teacher_id and archived_at is null
    ),
    selected_students as (
        select distinct p.id, p.first_name, p.last_name, p.grade_level
        from selected_classes c join public.class_members m on m.class_id = c.id
        join public.profiles p on p.id = m.student_id
        where p.role = 'student' and p.suspended_at is null
    ),
    period_questions as (
        select q.* from public.practice_questions q
        join selected_students s on s.id = q.student_id
        where q.answered_at is not null and q.answered_at >= period_start
    ),
    selected_mastery as (
        select m.* from public.practice_mastery m join selected_students s on s.id = m.student_id
    ),
    topic_points as (
        select q.student_id, q.competency_key, q.mastery_after,
            row_number() over (partition by q.student_id, q.competency_key order by q.answered_at, q.id) as first_position,
            row_number() over (partition by q.student_id, q.competency_key order by q.answered_at desc, q.id desc) as last_position
        from period_questions q where q.mastery_after is not null
    ),
    topic_improvements as (
        select student_id, competency_key,
            (max(mastery_after) filter (where last_position = 1) -
             max(mastery_after) filter (where first_position = 1))::numeric as improvement
        from topic_points group by student_id, competency_key
    ),
    student_activity as (
        select student_id, count(*)::integer answers,
            count(*) filter (where is_correct)::integer correct,
            coalesce(sum(hints_revealed), 0)::integer hints,
            count(*) filter (where hints_revealed > 0)::integer hinted_answers,
            max(answered_at) last_practiced_at
        from period_questions group by student_id
    ),
    student_mastery as (
        select student_id, round(avg(mastery_score)::numeric, 1) mastery,
            count(*) filter (where mastery_score >= 90)::integer mastered_topics,
            count(*)::integer practised_topics
        from selected_mastery group by student_id
    ),
    student_improvement as (
        select student_id, round(avg(improvement)::numeric, 1) improvement
        from topic_improvements group by student_id
    )
    select jsonb_build_object(
        'period_days', safe_days,
        'generated_at', now(),
        'summary', jsonb_build_object(
            'students', (select count(*) from selected_students),
            'active_students', (select count(distinct student_id) from period_questions),
            'answers', (select count(*) from period_questions),
            'accuracy', coalesce((select round((100.0 * count(*) filter (where is_correct) / nullif(count(*), 0))::numeric, 1) from period_questions), 0),
            'average_mastery', coalesce((select round(avg(mastery_score)::numeric, 1) from selected_mastery), 0),
            'hints_used', coalesce((select sum(hints_revealed) from period_questions), 0),
            'hint_usage_rate', coalesce((select round((100.0 * count(*) filter (where hints_revealed > 0) / nullif(count(*), 0))::numeric, 1) from period_questions), 0),
            'improvement', coalesce((select round(avg(improvement)::numeric, 1) from topic_improvements), 0)
        ),
        'available_classes', coalesce((select jsonb_agg(jsonb_build_object('id', c.id, 'name', c.class_name, 'grade_level', c.grade_level) order by c.class_name) from available_classes c), '[]'::jsonb),
        'classes', coalesce((select jsonb_agg(to_jsonb(x) order by x.class_name) from (
            select c.id, c.class_name, c.grade_level,
                count(distinct m.student_id)::integer students,
                count(distinct m.student_id) filter (where a.answers > 0)::integer active_students,
                coalesce(sum(a.answers), 0)::integer answers,
                coalesce(round((100.0 * sum(a.correct) / nullif(sum(a.answers), 0))::numeric, 1), 0) accuracy,
                coalesce(round(avg(sm.mastery)::numeric, 1), 0) mastery,
                coalesce(sum(a.hints), 0)::integer hints_used,
                coalesce(round(avg(si.improvement)::numeric, 1), 0) improvement
            from selected_classes c left join public.class_members m on m.class_id = c.id
            left join student_activity a on a.student_id = m.student_id
            left join student_mastery sm on sm.student_id = m.student_id
            left join student_improvement si on si.student_id = m.student_id
            group by c.id, c.class_name, c.grade_level
        ) x), '[]'::jsonb),
        'students', coalesce((select jsonb_agg(to_jsonb(x) order by x.last_practiced_at desc nulls last, x.name) from (
            select s.id, btrim(concat_ws(' ', s.first_name, s.last_name)) name, s.grade_level,
                coalesce(a.answers, 0) answers,
                coalesce(round((100.0 * a.correct / nullif(a.answers, 0))::numeric, 1), 0) accuracy,
                coalesce(a.hints, 0) hints_used,
                coalesce(round((100.0 * a.hinted_answers / nullif(a.answers, 0))::numeric, 1), 0) hint_usage_rate,
                coalesce(sm.mastery, 0) mastery, coalesce(sm.mastered_topics, 0) mastered_topics,
                coalesce(sm.practised_topics, 0) practised_topics,
                coalesce(si.improvement, 0) improvement, a.last_practiced_at,
                coalesce((select string_agg(c.class_name, ', ' order by c.class_name)
                    from selected_classes c join public.class_members cm on cm.class_id = c.id
                    where cm.student_id = s.id), '') classes
            from selected_students s left join student_activity a on a.student_id = s.id
            left join student_mastery sm on sm.student_id = s.id
            left join student_improvement si on si.student_id = s.id
            order by a.last_practiced_at desc nulls last, name limit 200
        ) x), '[]'::jsonb),
        'weak_topics', coalesce((select jsonb_agg(to_jsonb(x) order by x.mastery, x.attempts desc) from (
            select competency_key, grade_level, count(distinct student_id)::integer students,
                round(avg(mastery_score)::numeric, 1) mastery, sum(attempts)::integer attempts,
                coalesce(round((100.0 * sum(correct_answers) / nullif(sum(attempts), 0))::numeric, 1), 0) accuracy,
                sum(hints_used)::integer hints_used
            from selected_mastery where attempts > 0 and mastery_score < 90
            group by competency_key, grade_level order by avg(mastery_score), sum(attempts) desc limit 12
        ) x), '[]'::jsonb),
        'daily_activity', coalesce((select jsonb_agg(to_jsonb(x) order by x.activity_date) from (
            select (answered_at at time zone 'UTC')::date activity_date,
                count(*)::integer answers, count(*) filter (where is_correct)::integer correct,
                coalesce(sum(hints_revealed), 0)::integer hints_used
            from period_questions group by (answered_at at time zone 'UTC')::date
        ) x), '[]'::jsonb),
        'recent_activity', coalesce((select jsonb_agg(to_jsonb(x) order by x.answered_at desc) from (
            select q.id, btrim(concat_ws(' ', p.first_name, p.last_name)) student_name,
                p.grade_level, q.competency_key, q.is_correct, q.hints_revealed hints_used,
                q.mastery_after, q.answered_at
            from period_questions q join public.profiles p on p.id = q.student_id
            order by q.answered_at desc limit 20
        ) x), '[]'::jsonb)
    ) into result;

    return result;
end;
$$;
revoke all on function public.teacher_learning_hub_analytics(uuid, uuid, integer) from public, anon, authenticated;
grant execute on function public.teacher_learning_hub_analytics(uuid, uuid, integer) to service_role;

notify pgrst, 'reload schema';
commit;
