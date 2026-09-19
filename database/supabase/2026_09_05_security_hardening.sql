-- MathVerse database privilege hardening.
-- Run after every earlier migration in this directory.

begin;

-- Application users only need to use objects in public; schema creation is a
-- deployment concern and must never be available through an API role.
revoke create on schema public from public, anon, authenticated, service_role;
grant usage on schema public to anon, authenticated, service_role;

-- Cover original MathVerse tables that predate the versioned migration files,
-- as well as any later application table added before this migration is rerun.
-- Extension-owned relations are left to their extension's own permissions.
do $public_table_hardening$
declare
    table_record record;
begin
    for table_record in
        select relation.relname as table_name
        from pg_class as relation
        join pg_namespace as namespace on namespace.oid = relation.relnamespace
        where namespace.nspname = 'public'
          and relation.relkind in ('r', 'p')
          and not exists (
              select 1
              from pg_depend as dependency
              where dependency.classid = 'pg_class'::regclass
                and dependency.objid = relation.oid
                and dependency.deptype = 'e'
          )
    loop
        execute format(
            'alter table public.%I enable row level security',
            table_record.table_name
        );
        execute format(
            'revoke all privileges on table public.%I from public, anon',
            table_record.table_name
        );
    end loop;
end
$public_table_hardening$;

do $public_view_hardening$
declare
    view_record record;
begin
    for view_record in
        select relation.relname as view_name,
               relation.relkind as view_kind
        from pg_class as relation
        join pg_namespace as namespace on namespace.oid = relation.relnamespace
        where namespace.nspname = 'public'
          and relation.relkind in ('v', 'm')
          and not exists (
              select 1
              from pg_depend as dependency
              where dependency.classid = 'pg_class'::regclass
                and dependency.objid = relation.oid
                and dependency.deptype = 'e'
          )
    loop
        if view_record.view_kind = 'v' then
            -- PostgreSQL views run with owner permissions by default and can
            -- otherwise bypass the caller's base-table RLS policies.
            execute format(
                'alter view public.%I set (security_invoker = true)',
                view_record.view_name
            );
        end if;
        execute format(
            'revoke all privileges on table public.%I from public, anon',
            view_record.view_name
        );
        if view_record.view_kind = 'm' then
            -- Materialized views cannot use security_invoker and may contain
            -- rows refreshed by a privileged owner, so keep them server-only.
            execute format(
                'revoke all privileges on table public.%I from authenticated',
                view_record.view_name
            );
        end if;
    end loop;
end
$public_view_hardening$;

do $public_sequence_hardening$
declare
    sequence_record record;
begin
    for sequence_record in
        select relation.relname as sequence_name
        from pg_class as relation
        join pg_namespace as namespace on namespace.oid = relation.relnamespace
        where namespace.nspname = 'public'
          and relation.relkind = 'S'
          and not exists (
              select 1
              from pg_depend as dependency
              where dependency.classid = 'pg_class'::regclass
                and dependency.objid = relation.oid
                and dependency.deptype = 'e'
          )
    loop
        execute format(
            'revoke all privileges on sequence public.%I from public, anon',
            sequence_record.sequence_name
        );
    end loop;
end
$public_sequence_hardening$;

-- Retained rollback archives can contain student attempts, quiz content, and
-- audit data. Keep every archive owner-only, including archives created by a
-- rollback script before this migration is rerun.
do $archive_hardening$
declare
    archive_record record;
begin
    for archive_record in
        select relation.relname as table_name
        from pg_class as relation
        join pg_namespace as namespace on namespace.oid = relation.relnamespace
        where namespace.nspname = 'public'
          and relation.relkind in ('r', 'p')
          and strpos(relation.relname, 'rollback_') = 1
    loop
        execute format(
            'alter table public.%I enable row level security',
            archive_record.table_name
        );
        execute format(
            'revoke all privileges on table public.%I from public, anon, authenticated, service_role',
            archive_record.table_name
        );
    end loop;
end
$archive_hardening$;

-- Remove PostgreSQL's implicit PUBLIC execution grant from every existing
-- application function, including legacy functions not represented in this
-- repository. Any deliberately client-callable function must have a separate,
-- explicit authenticated grant; privileged MathVerse functions are granted
-- back to service_role by the allowlist below.
do $public_function_hardening$
declare
    function_record record;
begin
    for function_record in
        select
            namespace.nspname as schema_name,
            procedure.proname as function_name,
            pg_get_function_identity_arguments(procedure.oid) as identity_arguments,
            procedure.prosecdef as is_security_definer
        from pg_proc as procedure
        join pg_namespace as namespace on namespace.oid = procedure.pronamespace
        where namespace.nspname = 'public'
          and procedure.prokind = 'f'
          and not exists (
              select 1
              from pg_depend as dependency
              where dependency.classid = 'pg_proc'::regclass
                and dependency.objid = procedure.oid
                and dependency.deptype = 'e'
          )
    loop
        if function_record.is_security_definer then
            execute format(
                'revoke all on function %I.%I(%s) from public, anon, authenticated, service_role',
                function_record.schema_name,
                function_record.function_name,
                function_record.identity_arguments
            );
        else
            execute format(
                'revoke all on function %I.%I(%s) from public, anon',
                function_record.schema_name,
                function_record.function_name,
                function_record.identity_arguments
            );
        end if;
    end loop;
end
$public_function_hardening$;

-- PostgreSQL grants function execution by default. Supabase projects create
-- public-schema objects as postgres, so make future function exposure opt-in
-- for every Data API role, including service_role. Future tables and sequences
-- likewise start without a Data API mutation grant until explicitly reviewed.
alter default privileges for role postgres in schema public
revoke execute on functions from anon, authenticated, service_role;

alter default privileges for role postgres in schema public
revoke execute on functions from public;

alter default privileges for role postgres in schema public
revoke all privileges on tables from public, anon, authenticated;

alter default privileges for role postgres in schema public
revoke all privileges on sequences from public, anon, authenticated;

-- Sign-up user metadata is controlled by the person making the Auth request.
-- The profile-creation trigger may copy that metadata, so accept only the two
-- public registration roles unless the request came through the service role
-- or a deliberate owner session. Role changes are server-admin operations.
create or replace function public.enforce_profile_role_boundary()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    request_role text := coalesce(
        nullif(current_setting('request.jwt.claim.role', true), ''),
        nullif(current_setting('request.jwt.claims', true), '')::jsonb ->> 'role',
        ''
    );
    trusted_role_change boolean := request_role = 'service_role'
        or session_user in ('postgres', 'supabase_admin');
begin
    if coalesce(new.role::text, '') not in ('student', 'pending_teacher', 'teacher', 'admin') then
        raise exception 'Invalid MathVerse profile role' using errcode = '42501';
    end if;

    if tg_op = 'INSERT'
       and not trusted_role_change
       and new.role::text not in ('student', 'pending_teacher') then
        raise exception 'Public registration cannot assign a privileged role' using errcode = '42501';
    end if;

    if tg_op = 'UPDATE'
       and new.role is distinct from old.role
       and not trusted_role_change then
        raise exception 'Profile roles can only be changed by MathVerse administration' using errcode = '42501';
    end if;

    return new;
end;
$$;

drop trigger if exists profiles_role_boundary on public.profiles;
create trigger profiles_role_boundary
before insert or update of role on public.profiles
for each row execute function public.enforce_profile_role_boundary();

-- Even if an older broad profile RLS policy remains installed, browser/API
-- clients cannot mutate profiles directly. Validated profile forms and avatar
-- attachment all pass through Laravel's private service-role boundary.
revoke insert, delete, truncate, references, trigger on table public.profiles
from public, anon, authenticated;
revoke update on table public.profiles from public, anon, authenticated;

do $profile_column_hardening$
declare
    profile_column record;
begin
    for profile_column in
        select columns.column_name
        from information_schema.columns
        where columns.table_schema = 'public'
          and columns.table_name = 'profiles'
    loop
        execute format(
            'revoke insert (%I), update (%I), references (%I) on table public.profiles from public, anon, authenticated',
            profile_column.column_name,
            profile_column.column_name,
            profile_column.column_name
        );
    end loop;
end
$profile_column_hardening$;

-- These records are changed only by authenticated Laravel actions. Removing
-- direct Data API mutations prevents callers from bypassing join codes,
-- report validation, retake governance, notification ownership checks, or
-- the push-provider allowlist with a handcrafted Supabase request. SELECT
-- grants remain unchanged because existing RLS policies may use them.
revoke insert, update, delete, truncate, references, trigger on table
    public.class_members,
    public.notifications,
    public.push_subscriptions,
    public.quiz_bookmarks,
    public.quiz_ratings,
    public.quiz_reports,
    public.quiz_session_students
from public, anon, authenticated;

do $server_managed_column_hardening$
declare
    managed_column record;
begin
    for managed_column in
        select columns.table_name, columns.column_name
        from information_schema.columns
        where columns.table_schema = 'public'
          and columns.table_name = any (array[
              'class_members',
              'notifications',
              'push_subscriptions',
              'quiz_bookmarks',
              'quiz_ratings',
              'quiz_reports',
              'quiz_session_students'
          ])
    loop
        execute format(
            'revoke insert (%I), update (%I), references (%I) on table public.%I from public, anon, authenticated',
            managed_column.column_name,
            managed_column.column_name,
            managed_column.column_name,
            managed_column.table_name
        );
    end loop;
end
$server_managed_column_hardening$;

-- Laravel sessions are independent of Auth refresh tokens. Record every
-- password change so all older MathVerse sessions are rejected on their next
-- protected request, including sessions on other devices and recovery resets.
alter table public.profiles
    add column if not exists auth_sessions_invalid_before timestamptz;

create or replace function public.invalidate_profile_sessions_after_password_change()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
begin
    if new.encrypted_password is distinct from old.encrypted_password then
        update public.profiles
           set auth_sessions_invalid_before = clock_timestamp()
         where id = new.id;
    end if;

    return new;
end;
$$;

drop trigger if exists auth_users_mathverse_session_invalidation on auth.users;
create trigger auth_users_mathverse_session_invalidation
after update of encrypted_password on auth.users
for each row execute function public.invalidate_profile_sessions_after_password_change();

-- A push endpoint is effectively a device address. Once registered, an
-- upsert may rotate its keys but must never transfer the endpoint to a
-- different account, even if two requests race between lookup and write.
create or replace function public.prevent_push_subscription_owner_change()
returns trigger
language plpgsql
set search_path = pg_catalog, public
as $$
begin
    if new.user_id is distinct from old.user_id then
        raise exception 'A push subscription cannot change owners' using errcode = '42501';
    end if;

    return new;
end;
$$;

drop trigger if exists push_subscriptions_owner_immutable on public.push_subscriptions;
create trigger push_subscriptions_owner_immutable
before update of user_id on public.push_subscriptions
for each row execute function public.prevent_push_subscription_owner_change();

-- Class deletion spans legacy gameplay tables whose foreign keys are not all
-- cascading. Keep the ownership check and every dependent delete in one
-- transaction so a failure cannot leave a partially deleted classroom.
create or replace function public.delete_teacher_class(
    p_teacher_id uuid,
    p_class_id uuid
)
returns table (deleted_class_id uuid)
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
begin
    perform 1
      from public.classes
     where id = p_class_id
       and teacher_id = p_teacher_id
     for update;

    if not found then
        raise exception 'Class not found for this teacher' using errcode = '42501';
    end if;

    update public.profiles
       set class_id = null
     where class_id = p_class_id;

    delete from public.quiz_results
     where session_id in (
        select id from public.quiz_sessions where class_id = p_class_id
     );
    delete from public.quiz_participants
     where session_id in (
        select id from public.quiz_sessions where class_id = p_class_id
     );
    delete from public.quiz_session_students
     where session_id in (
        select id from public.quiz_sessions where class_id = p_class_id
     );
    delete from public.questions
     where session_id in (
        select id from public.quiz_sessions where class_id = p_class_id
     );
    delete from public.quiz_sessions
     where class_id = p_class_id
       and teacher_id = p_teacher_id;
    delete from public.class_members
     where class_id = p_class_id;
    delete from public.class_customizations
     where class_id = p_class_id;
    delete from public.classes
     where id = p_class_id
       and teacher_id = p_teacher_id;

    if not found then
        raise exception 'Class ownership changed during deletion' using errcode = '40001';
    end if;

    deleted_class_id := p_class_id;
    return next;
end;
$$;

-- Restrict every privileged function installed by MathVerse. Trigger
-- functions do not need client execution, while server RPC calls use the
-- service_role key. An explicit pg_catalog-first search path prevents object
-- shadowing inside SECURITY DEFINER code.
do $hardening$
declare
    function_record record;
    mathverse_functions constant text[] := array[
        'acknowledge_system_incident',
        'arcade_game_dashboard',
        'arcade_hub_dashboard',
        'cancel_account_purge',
        'complete_privileged_audit_intent',
        'create_privileged_audit_intent',
        'finish_arcade_game',
        'finish_incident_notification',
        'incident_signal_counts',
        'notify_incident_admins',
        'prepare_account_purge',
        'prune_incident_events',
        'recovery_account_active',
        'recovery_assignment_guard',
        'recovery_guard',
        'recovery_session_state_guard',
        'recovery_quiz_content_guard',
        'search_audit_logs',
        'set_account_deactivated',
        'set_recovery_item',
        'start_arcade_game',
        'student_trophy_leaderboard',
        'submit_arcade_answer',
        'sync_incident_signal',
        'teacher_learning_hub_analytics',
        'add_member_to_open_quiz_sessions',
        'advance_quiz_session_schedule',
        'assign_shared_quiz_to_classes',
        'auto_verify_admin_quiz',
        'claim_notification_deliveries',
        'create_notification',
        'delete_open_quiz_assignment',
        'delete_teacher_class',
        'enforce_class_member_grade',
        'enforce_profile_role_boundary',
        'enforce_quiz_assignment_grade',
        'enforce_quiz_result_attempt',
        'enforce_quiz_usage_count',
        'freeze_completed_assignment_attempts',
        'finish_number_guess_game',
        'generate_upcoming_quiz_notifications',
        'grant_quiz_retake',
        'handle_auth_security_change',
        'ignore_repeat_quiz_result',
        'invalidate_profile_sessions_after_password_change',
        'keep_quiz_results_immutable',
        'number_guess_dashboard',
        'notify_all_admins',
        'notify_class_archive_changed',
        'notify_class_membership_changed',
        'notify_profile_created',
        'notify_profile_state_changed',
        'notify_quiz_eligibility_changed',
        'notify_quiz_eligibility_created',
        'notify_quiz_report_changed',
        'notify_quiz_result_created',
        'notify_quiz_session_created',
        'notify_quiz_status_changed',
        'notify_quiz_verification_changed',
        'prevent_enrolled_class_grade_change',
        'prevent_enrolled_student_grade_change',
        'prevent_student_membership_exit',
        'queue_notification_delivery',
        'quiz_management_url',
        'refresh_quiz_rating_summary',
        'refresh_source_quiz_usage',
        'request_immediate_quiz_receipt_delivery',
        'require_explicit_quiz_retake',
        'restore_quiz_version',
        'restore_quiz_version_v2',
        'reveal_practice_hint',
        'revoke_member_open_quiz_eligibility',
        'seed_quiz_session_students',
        'snapshot_quiz_report_context',
        'start_number_guess_game',
        'submit_number_guess',
        'submit_practice_answer'
    ];
begin
    for function_record in
        select
            namespace.nspname as schema_name,
            procedure.proname as function_name,
            pg_get_function_identity_arguments(procedure.oid) as identity_arguments
        from pg_proc as procedure
        join pg_namespace as namespace on namespace.oid = procedure.pronamespace
        where namespace.nspname = 'public'
          and procedure.prokind = 'f'
          and procedure.prosecdef
          and procedure.proname = any (mathverse_functions)
    loop
        execute format(
            'alter function %I.%I(%s) set search_path = pg_catalog, public',
            function_record.schema_name,
            function_record.function_name,
            function_record.identity_arguments
        );
        execute format(
            'revoke all on function %I.%I(%s) from public, anon, authenticated',
            function_record.schema_name,
            function_record.function_name,
            function_record.identity_arguments
        );
        execute format(
            'grant execute on function %I.%I(%s) to %I',
            function_record.schema_name,
            function_record.function_name,
            function_record.identity_arguments,
            case when function_record.function_name = 'recovery_account_active' then 'authenticated' else 'service_role' end
        );
    end loop;
end
$hardening$;

commit;
