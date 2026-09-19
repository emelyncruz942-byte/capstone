-- Safe rollback for 2026_08_29_scheduling_governance_and_scale.sql.
-- Retake attempts and governance records are archived before their schema is
-- removed. Run only after backing up the Supabase project.

begin;

set local search_path = public, extensions;

-- Archive every result that cannot fit the former one-result-per-assignment
-- model. The currently counted attempt is retained when one exists.
create table if not exists public.rollback_retake_quiz_results_20260829
    (like public.quiz_results including all);
alter table public.rollback_retake_quiz_results_20260829 enable row level security;
revoke all privileges on table public.rollback_retake_quiz_results_20260829
from public, anon, authenticated, service_role;

with ranked as (
    select id,
           row_number() over (
               partition by session_id, student_id
               order by is_counted desc, attempt_number desc,
                        created_at desc nulls last, id desc
           ) as keep_number
    from public.quiz_results
)
insert into public.rollback_retake_quiz_results_20260829
select qr.*
from public.quiz_results qr
join ranked on ranked.id = qr.id
where ranked.keep_number > 1
on conflict (id) do nothing;

with ranked as (
    select id,
           row_number() over (
               partition by session_id, student_id
               order by is_counted desc, attempt_number desc,
                        created_at desc nulls last, id desc
           ) as keep_number
    from public.quiz_results
)
delete from public.quiz_results qr
using ranked
where qr.id = ranked.id and ranked.keep_number > 1;

-- Archive metadata that otherwise has no representation in the older schema.
create table if not exists public.rollback_quiz_versions_20260829
    (like public.quiz_versions including all);
alter table public.rollback_quiz_versions_20260829 enable row level security;
revoke all privileges on table public.rollback_quiz_versions_20260829
from public, anon, authenticated, service_role;
insert into public.rollback_quiz_versions_20260829
select * from public.quiz_versions on conflict (id) do nothing;

create table if not exists public.rollback_quiz_bookmarks_20260829
    (like public.quiz_bookmarks including all);
alter table public.rollback_quiz_bookmarks_20260829 enable row level security;
revoke all privileges on table public.rollback_quiz_bookmarks_20260829
from public, anon, authenticated, service_role;
insert into public.rollback_quiz_bookmarks_20260829
select * from public.quiz_bookmarks on conflict (quiz_id, user_id) do nothing;

create table if not exists public.rollback_quiz_ratings_20260829
    (like public.quiz_ratings including all);
alter table public.rollback_quiz_ratings_20260829 enable row level security;
revoke all privileges on table public.rollback_quiz_ratings_20260829
from public, anon, authenticated, service_role;
insert into public.rollback_quiz_ratings_20260829
select * from public.quiz_ratings on conflict (quiz_id, user_id) do nothing;

create table if not exists public.rollback_quiz_reports_20260829
    (like public.quiz_reports including all);
alter table public.rollback_quiz_reports_20260829 enable row level security;
revoke all privileges on table public.rollback_quiz_reports_20260829
from public, anon, authenticated, service_role;
insert into public.rollback_quiz_reports_20260829
select * from public.quiz_reports on conflict (id) do nothing;

create table if not exists public.rollback_quiz_session_students_20260829
    (like public.quiz_session_students including all);
alter table public.rollback_quiz_session_students_20260829 enable row level security;
revoke all privileges on table public.rollback_quiz_session_students_20260829
from public, anon, authenticated, service_role;
insert into public.rollback_quiz_session_students_20260829
select * from public.quiz_session_students
on conflict (session_id, student_id) do nothing;

do $$
begin
    if to_regclass('public.class_member_accommodations') is not null then
        execute 'create table if not exists public.rollback_class_member_accommodations_20260829 (like public.class_member_accommodations including all)';
        execute 'alter table public.rollback_class_member_accommodations_20260829 enable row level security';
        execute 'revoke all privileges on table public.rollback_class_member_accommodations_20260829 from public, anon, authenticated, service_role';
        execute 'insert into public.rollback_class_member_accommodations_20260829 select * from public.class_member_accommodations on conflict (class_id, student_id) do nothing';
    end if;
end
$$;

create table if not exists public.rollback_audit_logs_20260829
    (like public.audit_logs including all);
alter table public.rollback_audit_logs_20260829 enable row level security;
revoke all privileges on table public.rollback_audit_logs_20260829
from public, anon, authenticated, service_role;
insert into public.rollback_audit_logs_20260829 overriding system value
select * from public.audit_logs on conflict (id) do nothing;

-- Remove new RLS policies before removing their tables.
drop policy if exists quiz_versions_owner_read on public.quiz_versions;
drop policy if exists quiz_versions_owner_insert on public.quiz_versions;
drop policy if exists quiz_bookmarks_own_all on public.quiz_bookmarks;
drop policy if exists quiz_ratings_own_all on public.quiz_ratings;
drop policy if exists quiz_reports_own_read on public.quiz_reports;
drop policy if exists quiz_reports_own_insert on public.quiz_reports;
do $$
begin
    if to_regclass('public.class_member_accommodations') is not null then
        execute 'drop policy if exists class_accommodations_teacher_all on public.class_member_accommodations';
    end if;
end
$$;
drop policy if exists quiz_session_students_self_read on public.quiz_session_students;
drop policy if exists quiz_session_students_teacher_all on public.quiz_session_students;
drop policy if exists audit_logs_admin_read on public.audit_logs;

-- Restore the prior reusable-library read boundary.
drop policy if exists quizzes_teacher_read on public.quizzes;
create policy quizzes_teacher_read
on public.quizzes for select to authenticated
using (
    exists (
        select 1 from public.profiles
        where profiles.id = auth.uid()
          and profiles.role = 'teacher'
    )
);

drop policy if exists quiz_questions_teacher_read on public.quiz_questions;
create policy quiz_questions_teacher_read
on public.quiz_questions for select to authenticated
using (
    exists (
        select 1
        from public.quizzes
        join public.profiles on profiles.id = auth.uid()
        where quizzes.id = quiz_questions.quiz_id
          and profiles.role = 'teacher'
    )
);

drop trigger if exists quiz_results_attempt_guard on public.quiz_results;
drop trigger if exists quiz_sessions_freeze_attempts on public.quiz_sessions;
drop trigger if exists quiz_sessions_seed_students on public.quiz_sessions;
drop trigger if exists class_members_open_quiz_eligibility on public.class_members;
drop trigger if exists class_members_revoke_quiz_eligibility on public.class_members;
do $$
begin
    if to_regclass('public.class_member_accommodations') is not null then
        execute 'drop trigger if exists class_member_accommodation_propagation on public.class_member_accommodations';
    end if;
end
$$;
drop trigger if exists quiz_ratings_refresh_summary on public.quiz_ratings;
drop trigger if exists quizzes_refresh_source_usage on public.quizzes;
drop trigger if exists quiz_sessions_refresh_source_usage on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage_insert_delete on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage_update on public.quiz_sessions;
drop trigger if exists quizzes_enforce_usage_count on public.quizzes;
drop trigger if exists quizzes_auto_verify_admin on public.quizzes;

drop function if exists public.enforce_quiz_result_attempt();
drop function if exists public.freeze_completed_assignment_attempts();
drop function if exists public.seed_quiz_session_students();
drop function if exists public.add_member_to_open_quiz_sessions();
drop function if exists public.revoke_member_open_quiz_eligibility();
drop function if exists public.propagate_member_accommodation();
drop function if exists public.refresh_quiz_rating_summary();
drop function if exists public.refresh_source_quiz_usage();
drop function if exists public.enforce_quiz_usage_count();
drop function if exists public.delete_open_quiz_assignment(uuid, uuid, uuid);
drop function if exists public.auto_verify_admin_quiz();
drop function if exists public.restore_quiz_version_v2(uuid, integer, uuid);
drop function if exists public.restore_quiz_version(uuid, integer, uuid);
drop function if exists public.advance_quiz_session_schedule(uuid);
drop function if exists public.grant_quiz_retake(uuid, uuid, uuid, text, timestamp with time zone);

drop index if exists public.quiz_results_attempt_number_idx;
drop index if exists public.quiz_results_one_counted_attempt_idx;
drop index if exists public.quiz_results_counted_session_idx;

alter table public.quiz_results
    drop constraint if exists quiz_results_attempt_number_check,
    drop constraint if exists quiz_results_score_range_check,
    drop column if exists attempt_number,
    drop column if exists is_counted;

-- Restore the one stored result behavior from the August 28 migration.
create unique index if not exists quiz_results_one_attempt_per_assignment_idx
    on public.quiz_results (session_id, student_id);

create or replace function public.ignore_repeat_quiz_result()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if exists (
        select 1 from public.quiz_results
        where session_id = new.session_id
          and student_id = new.student_id
    ) then
        return null;
    end if;
    return new;
end;
$$;

drop trigger if exists quiz_results_first_attempt_guard on public.quiz_results;
create trigger quiz_results_first_attempt_guard
before insert on public.quiz_results
for each row execute function public.ignore_repeat_quiz_result();

drop index if exists public.quiz_sessions_schedule_idx;
drop index if exists public.quiz_sessions_class_lifecycle_idx;
drop index if exists public.quiz_session_students_student_idx;
drop index if exists public.quiz_reports_status_created_idx;
drop index if exists public.quiz_bookmarks_user_created_idx;
drop index if exists public.quizzes_visibility_grade_idx;
drop index if exists public.quizzes_library_verified_idx;
drop index if exists public.quizzes_library_priority_created_idx;
drop index if exists public.quiz_sessions_source_quiz_usage_idx;
drop index if exists public.profiles_role_grade_name_idx;
drop index if exists public.profiles_email_search_idx;
drop index if exists public.profiles_first_name_search_idx;
drop index if exists public.profiles_last_name_search_idx;
drop index if exists public.class_members_class_student_idx;
drop index if exists public.audit_logs_created_idx;

drop table if exists public.quiz_versions;
drop table if exists public.quiz_bookmarks;
drop table if exists public.quiz_ratings;
drop table if exists public.quiz_reports;
drop table if exists public.quiz_session_students;
drop table if exists public.class_member_accommodations;
drop table if exists public.audit_logs;

alter table public.quizzes
    drop constraint if exists quizzes_source_quiz_id_fkey,
    drop constraint if exists quizzes_verified_by_fkey,
    drop constraint if exists quizzes_visibility_check,
    drop constraint if exists quizzes_version_check,
    drop constraint if exists quizzes_usage_count_check,
    drop constraint if exists quizzes_rating_summary_check,
    drop column if exists visibility,
    drop column if exists source_quiz_id,
    drop column if exists version,
    drop column if exists usage_count,
    drop column if exists rating_average,
    drop column if exists rating_count,
    drop column if exists is_verified,
    drop column if exists verified_at,
    drop column if exists verified_by;

alter table public.quiz_sessions
    drop constraint if exists quiz_sessions_schedule_check,
    drop column if exists assigned_at,
    drop column if exists available_at,
    drop column if exists due_at,
    drop column if exists started_at,
    drop column if exists ended_at,
    drop column if exists retake_mode;

alter table public.profiles
    drop constraint if exists profiles_suspended_by_fkey,
    drop constraint if exists profiles_suspension_reason_check,
    drop constraint if exists profiles_leaderboard_alias_check,
    drop column if exists suspended_at,
    drop column if exists suspended_by,
    drop column if exists suspension_reason,
    drop column if exists leaderboard_alias,
    drop column if exists show_on_leaderboard;

commit;

-- rollback_*_20260829 tables intentionally remain until their data is checked
-- and a separate database backup is confirmed.
