-- Safe rollback for 2026_08_28_archived_classes_and_single_attempts.sql.

begin;

drop trigger if exists quiz_results_first_attempt_guard on public.quiz_results;
drop function if exists public.ignore_repeat_quiz_result();

drop index if exists public.quiz_results_one_attempt_per_assignment_idx;

insert into public.quiz_results
select * from public.rollback_duplicate_quiz_results_20260828
on conflict (id) do nothing;

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
begin
    select grade_level into student_grade from public.profiles where id = new.student_id;
    select grade_level, teacher_id into target_grade, target_teacher from public.classes where id = new.class_id;

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
             and c.grade_level is distinct from new.grade_level
       ) then
        raise exception 'The student is enrolled in a class with a different grade level';
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
               select 1 from public.quiz_sessions where quiz_sessions.class_id = new.id
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
begin
    if new.source_quiz_id is null or new.class_id is null then return new; end if;
    select grade_level into quiz_grade from public.quizzes where id = new.source_quiz_id;
    select grade_level into target_grade from public.classes where id = new.class_id;
    if quiz_grade is null or target_grade is null or quiz_grade <> target_grade then
        raise exception 'Quiz grade (%) does not match class grade (%)', quiz_grade, target_grade;
    end if;
    return new;
end;
$$;

drop index if exists public.classes_teacher_archive_idx;

alter table public.classes
    drop column if exists archived_at;

commit;

-- rollback_duplicate_quiz_results_20260828 intentionally remains as a safety
-- archive. It can be deleted after the restored data has been verified.
