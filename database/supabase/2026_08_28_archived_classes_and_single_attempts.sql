-- Archived classrooms and one counted attempt per student/assignment.
-- Run after 2026_08_27_reusable_quizzes_and_class_pages.sql.

begin;

alter table public.classes
    add column if not exists archived_at timestamp with time zone;

create index if not exists classes_teacher_archive_idx
    on public.classes (teacher_id, archived_at, created_at desc);

-- Preserve duplicate legacy attempts before keeping the earliest result. The
-- archive is only needed if this migration is later rolled back.
create table if not exists public.rollback_duplicate_quiz_results_20260828
    (like public.quiz_results including all);
alter table public.rollback_duplicate_quiz_results_20260828 enable row level security;
revoke all privileges on table public.rollback_duplicate_quiz_results_20260828
from public, anon, authenticated, service_role;

with ranked_results as (
    select id,
           row_number() over (
               partition by session_id, student_id
               order by created_at asc nulls last, id asc
           ) as attempt_number
    from public.quiz_results
)
insert into public.rollback_duplicate_quiz_results_20260828
select qr.*
from public.quiz_results as qr
join ranked_results as ranked on ranked.id = qr.id
where ranked.attempt_number > 1
on conflict (id) do nothing;

with ranked_results as (
    select id,
           row_number() over (
               partition by session_id, student_id
               order by created_at asc nulls last, id asc
           ) as attempt_number
    from public.quiz_results
)
delete from public.quiz_results as qr
using ranked_results as ranked
where qr.id = ranked.id
  and ranked.attempt_number > 1;

create unique index if not exists quiz_results_one_attempt_per_assignment_idx
    on public.quiz_results (session_id, student_id);

-- Normal repeat submissions are ignored instead of replacing the first score.
-- The unique index above remains the race-condition safety net.
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

create or replace function public.enforce_class_member_grade()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    student_grade integer;
    target_grade integer;
    target_teacher uuid;
    target_archived_at timestamp with time zone;
begin
    select grade_level into student_grade
    from public.profiles
    where id = new.student_id;

    select grade_level, teacher_id, archived_at
    into target_grade, target_teacher, target_archived_at
    from public.classes
    where id = new.class_id;

    if target_archived_at is not null then
        raise exception 'Students cannot join an archived class';
    end if;

    if auth.uid() is not null
       and auth.uid() is distinct from new.student_id
       and auth.uid() is distinct from target_teacher then
        raise exception 'Only the student or class teacher can create this membership';
    end if;

    if student_grade is null or target_grade is null or student_grade <> target_grade then
        raise exception 'Student grade (%) does not match class grade (%)', student_grade, target_grade;
    end if;

    return new;
end;
$$;

create or replace function public.prevent_enrolled_student_grade_change()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if new.role = 'student'
       and new.grade_level is distinct from old.grade_level
       and exists (
           select 1
           from public.class_members as cm
           join public.classes as c on c.id = cm.class_id
           where cm.student_id = new.id
             and c.archived_at is null
             and c.grade_level is distinct from new.grade_level
       ) then
        raise exception 'The student is enrolled in an active class with a different grade level';
    end if;

    return new;
end;
$$;

create or replace function public.prevent_enrolled_class_grade_change()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if new.grade_level is distinct from old.grade_level
       and (
           exists (
               select 1
               from public.class_members as cm
               join public.profiles as p on p.id = cm.student_id
               where cm.class_id = new.id
                 and p.grade_level is distinct from new.grade_level
           )
           or exists (
               select 1
               from public.quiz_sessions
               where quiz_sessions.class_id = new.id
           )
       ) then
        raise exception 'A class with mismatched members or quiz history cannot change grade level';
    end if;

    return new;
end;
$$;

create or replace function public.enforce_quiz_assignment_grade()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    quiz_grade integer;
    target_grade integer;
    target_archived_at timestamp with time zone;
begin
    if new.source_quiz_id is null or new.class_id is null then
        return new;
    end if;

    select grade_level into quiz_grade
    from public.quizzes
    where id = new.source_quiz_id;

    select grade_level, archived_at into target_grade, target_archived_at
    from public.classes
    where id = new.class_id;

    if target_archived_at is not null then
        raise exception 'Quizzes cannot be assigned to an archived class';
    end if;

    if quiz_grade is null or target_grade is null or quiz_grade <> target_grade then
        raise exception 'Quiz grade (%) does not match class grade (%)', quiz_grade, target_grade;
    end if;

    return new;
end;
$$;

commit;
