-- Safe rollback for 2026_08_27_reusable_quizzes_and_class_pages.sql
-- New reusable content/customization is archived before the new tables are removed.

begin;

create table if not exists public.rollback_quizzes_20260827
    (like public.quizzes including all);
alter table public.rollback_quizzes_20260827 enable row level security;
revoke all privileges on table public.rollback_quizzes_20260827
from public, anon, authenticated, service_role;
insert into public.rollback_quizzes_20260827
select * from public.quizzes
on conflict (id) do nothing;

create table if not exists public.rollback_quiz_questions_20260827
    (like public.quiz_questions including all);
alter table public.rollback_quiz_questions_20260827 enable row level security;
revoke all privileges on table public.rollback_quiz_questions_20260827
from public, anon, authenticated, service_role;
insert into public.rollback_quiz_questions_20260827
overriding system value
select * from public.quiz_questions
on conflict (id) do nothing;

create table if not exists public.rollback_class_customizations_20260827
    (like public.class_customizations including all);
alter table public.rollback_class_customizations_20260827 enable row level security;
revoke all privileges on table public.rollback_class_customizations_20260827
from public, anon, authenticated, service_role;
insert into public.rollback_class_customizations_20260827
select * from public.class_customizations
on conflict (class_id) do nothing;

create table if not exists public.rollback_class_grades_20260827 (
    class_id uuid primary key,
    grade_level integer
);
alter table public.rollback_class_grades_20260827 enable row level security;
revoke all privileges on table public.rollback_class_grades_20260827
from public, anon, authenticated, service_role;
insert into public.rollback_class_grades_20260827 (class_id, grade_level)
select id, grade_level from public.classes
on conflict (class_id) do update
set grade_level = excluded.grade_level;

drop trigger if exists class_members_grade_guard on public.class_members;
drop trigger if exists class_members_student_exit_guard on public.class_members;
drop trigger if exists profiles_grade_change_guard on public.profiles;
drop trigger if exists classes_grade_change_guard on public.classes;
drop trigger if exists quiz_sessions_grade_guard on public.quiz_sessions;

drop function if exists public.enforce_class_member_grade();
drop function if exists public.prevent_student_membership_exit();
drop function if exists public.prevent_enrolled_student_grade_change();
drop function if exists public.prevent_enrolled_class_grade_change();
drop function if exists public.enforce_quiz_assignment_grade();

drop policy if exists class_members_student_self_select on public.class_members;
drop policy if exists class_members_student_join on public.class_members;
drop policy if exists class_members_teacher_select on public.class_members;
drop policy if exists class_members_roster_boundary on public.class_members;

drop index if exists public.quiz_sessions_one_open_quiz_per_class_idx;
drop index if exists public.quiz_sessions_class_status_idx;

-- Restore legacy session values exactly as they were before the migration.
update public.quiz_sessions as qs
set status = legacy.status,
    is_active = legacy.is_active,
    max_members = legacy.max_members
from public.rollback_quiz_session_state_20260827 as legacy
where legacy.session_id = qs.id;

alter table public.quiz_sessions
    drop constraint if exists quiz_sessions_source_quiz_id_fkey,
    drop column if exists source_quiz_id,
    alter column max_members set default 50;

drop table if exists public.quiz_questions;
drop table if exists public.quizzes;
drop table if exists public.class_customizations;

alter table public.classes
    drop constraint if exists classes_grade_level_check,
    drop column if exists grade_level;

commit;

-- The rollback_*_20260827 archive tables intentionally remain so quizzes,
-- visual settings, and legacy session state are recoverable.
