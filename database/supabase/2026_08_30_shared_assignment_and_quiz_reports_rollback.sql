-- Roll back 2026_08_30_shared_assignment_and_quiz_reports.sql.
-- Run only after rolling the application code back to its previous commit.

begin;

set local search_path = public, extensions;

drop function if exists public.assign_shared_quiz_to_classes(
    uuid, uuid, uuid[], text, integer, integer,
    timestamp with time zone, timestamp with time zone, jsonb
);

drop index if exists public.quiz_reports_status_reviewed_idx;

drop trigger if exists quiz_reports_snapshot_context on public.quiz_reports;
drop function if exists public.snapshot_quiz_report_context();

alter table public.quiz_reports
    drop constraint if exists quiz_reports_quiz_creator_id_fkey,
    drop constraint if exists quiz_reports_quiz_id_fkey;

-- This succeeds only while every report still references an existing quiz.
-- Restore or remove orphaned report rows first if a quiz was deleted after the
-- forward migration.
alter table public.quiz_reports
    alter column quiz_id set not null,
    add constraint quiz_reports_quiz_id_fkey
        foreign key (quiz_id) references public.quizzes(id) on delete cascade,
    drop column if exists quiz_topic,
    drop column if exists quiz_grade_level,
    drop column if exists quiz_creator_id,
    drop column if exists question_text;

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

drop trigger if exists quiz_sessions_grade_guard on public.quiz_sessions;
create trigger quiz_sessions_grade_guard
before insert or update of class_id, source_quiz_id on public.quiz_sessions
for each row execute function public.enforce_quiz_assignment_grade();

alter table public.quiz_sessions
    drop constraint if exists quiz_sessions_grade_level_check,
    drop column if exists grade_level;

notify pgrst, 'reload schema';

commit;
