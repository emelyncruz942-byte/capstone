-- MathVerse assignment usage and quiz-attempt integrity hotfix.
-- Run after 2026_08_30_quiz_regression_and_push_hotfix.sql.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.quizzes') is null
       or to_regclass('public.quiz_sessions') is null
       or to_regclass('public.quiz_session_students') is null
       or to_regclass('public.quiz_results') is null then
        raise exception 'Required quiz tables are missing. Run the August 29 and August 30 migrations first.';
    end if;
    if to_regprocedure('public.enforce_quiz_result_attempt()') is null then
        raise exception 'The quiz attempt guard is missing. Run the August 29 scheduling/governance migration first.';
    end if;
end
$$;

-- A quiz use means one shared-library assignment event. Reassigning the quiz
-- to the same class after the earlier assignment ends counts again. The quiz
-- creator's own class assignments never affect shared-library popularity.
create or replace function public.refresh_source_quiz_usage()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if tg_op in ('UPDATE', 'DELETE') and old.source_quiz_id is not null then
        update public.quizzes originals
        set usage_count = (
            select count(*)::integer
            from public.quiz_sessions assignments
            where assignments.source_quiz_id = old.source_quiz_id
              and assignments.class_id is not null
              and assignments.teacher_id <> originals.teacher_id
        )
        where originals.id = old.source_quiz_id;
    end if;

    if tg_op in ('INSERT', 'UPDATE') and new.source_quiz_id is not null then
        update public.quizzes originals
        set usage_count = (
            select count(*)::integer
            from public.quiz_sessions assignments
            where assignments.source_quiz_id = new.source_quiz_id
              and assignments.class_id is not null
              and assignments.teacher_id <> originals.teacher_id
        )
        where originals.id = new.source_quiz_id;
    end if;

    if tg_op = 'DELETE' then
        return old;
    end if;
    return new;
end;
$$;

drop trigger if exists quiz_sessions_refresh_source_usage on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage_insert_delete on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage_update on public.quiz_sessions;

create trigger quiz_sessions_refresh_source_usage_insert_delete
after insert or delete on public.quiz_sessions
for each row execute function public.refresh_source_quiz_usage();

create trigger quiz_sessions_refresh_source_usage_update
after update of source_quiz_id, class_id, teacher_id on public.quiz_sessions
for each row execute function public.refresh_source_quiz_usage();

update public.quizzes originals
set usage_count = (
    select count(*)::integer
    from public.quiz_sessions assignments
    where assignments.source_quiz_id = originals.id
      and assignments.class_id is not null
      and assignments.teacher_id <> originals.teacher_id
);

-- Repair students who received more than one allowance without an explicit
-- retake grant. Keep the first submitted score counted and retain later rows
-- as non-counted evidence instead of deleting them.
drop trigger if exists quiz_results_immutable_guard on public.quiz_results;

update public.quiz_results results
set is_counted = false
from public.quiz_session_students eligibility
where eligibility.session_id = results.session_id
  and eligibility.student_id = results.student_id
  and eligibility.last_retake_granted_at is null
  and results.is_counted;

with first_attempts as (
    select distinct on (results.session_id, results.student_id)
        results.id
    from public.quiz_results results
    join public.quiz_session_students eligibility
      on eligibility.session_id = results.session_id
     and eligibility.student_id = results.student_id
    where eligibility.last_retake_granted_at is null
    order by results.session_id, results.student_id,
             results.attempt_number asc, results.created_at asc, results.id asc
)
update public.quiz_results results
set is_counted = true
from first_attempts
where results.id = first_attempts.id;

update public.quiz_session_students eligibility
set allowed_attempts = case
    when eligibility.eligibility_status = 'excused' then 0
    when exists (
        select 1
        from public.quiz_results results
        where results.session_id = eligibility.session_id
          and results.student_id = eligibility.student_id
    ) then 1
    when exists (
        select 1
        from public.quiz_sessions assignments
        where assignments.id = eligibility.session_id
          and assignments.status = 'completed'
    ) then 0
    else 1
end
where eligibility.last_retake_granted_at is null;

-- This trigger runs alphabetically before the general attempt guard. A repeat
-- insert without an explicit teacher grant is silently ignored, matching the
-- rule that later unapproved plays must not affect stored analytics.
create or replace function public.require_explicit_quiz_retake()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    retake_granted_at timestamp with time zone;
begin
    select last_retake_granted_at into retake_granted_at
    from public.quiz_session_students
    where session_id = new.session_id
      and student_id = new.student_id;

    if retake_granted_at is null and exists (
        select 1
        from public.quiz_results
        where session_id = new.session_id
          and student_id = new.student_id
    ) then
        return null;
    end if;

    return new;
end;
$$;

drop trigger if exists quiz_results_00_explicit_retake_guard on public.quiz_results;
create trigger quiz_results_00_explicit_retake_guard
before insert on public.quiz_results
for each row execute function public.require_explicit_quiz_retake();

-- Reinstall the general lifecycle/allowance guard in case an earlier manual
-- schema edit removed its trigger while leaving the function available.
drop trigger if exists quiz_results_attempt_guard on public.quiz_results;
create trigger quiz_results_attempt_guard
before insert on public.quiz_results
for each row execute function public.enforce_quiz_result_attempt();

-- A client update/upsert must not overwrite an already-recorded score. The
-- nested recount performed by enforce_quiz_result_attempt() remains allowed
-- when an authorized retake inserts a separate result row.
create or replace function public.keep_quiz_results_immutable()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if pg_trigger_depth() = 1
       and (to_jsonb(new) - 'rank') is distinct from (to_jsonb(old) - 'rank') then
        raise exception 'Quiz results are immutable; an authorized retake must create a new result';
    end if;
    return new;
end;
$$;

drop trigger if exists quiz_results_immutable_guard on public.quiz_results;
create trigger quiz_results_immutable_guard
before update on public.quiz_results
for each row execute function public.keep_quiz_results_immutable();

notify pgrst, 'reload schema';

commit;
