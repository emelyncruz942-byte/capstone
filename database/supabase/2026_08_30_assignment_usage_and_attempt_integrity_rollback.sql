-- Roll back only the August 30 assignment-usage/attempt-integrity hotfix.

begin;

set local search_path = public, extensions;

drop trigger if exists quiz_results_immutable_guard on public.quiz_results;
drop trigger if exists quiz_results_00_explicit_retake_guard on public.quiz_results;
drop function if exists public.keep_quiz_results_immutable();
drop function if exists public.require_explicit_quiz_retake();

-- Restore the former behavior in which the latest stored result is counted.
update public.quiz_results results
set is_counted = false
from public.quiz_session_students eligibility
where eligibility.session_id = results.session_id
  and eligibility.student_id = results.student_id
  and eligibility.last_retake_granted_at is null
  and results.is_counted;

with latest_attempts as (
    select distinct on (results.session_id, results.student_id)
        results.id
    from public.quiz_results results
    join public.quiz_session_students eligibility
      on eligibility.session_id = results.session_id
     and eligibility.student_id = results.student_id
    where eligibility.last_retake_granted_at is null
    order by results.session_id, results.student_id,
             results.attempt_number desc, results.created_at desc, results.id desc
)
update public.quiz_results results
set is_counted = true
from latest_attempts
where results.id = latest_attempts.id;

update public.quiz_session_students eligibility
set allowed_attempts = case
    when assignments.status = 'completed' then (
        select count(*)::integer
        from public.quiz_results results
        where results.session_id = eligibility.session_id
          and results.student_id = eligibility.student_id
    )
    else greatest(1, (
        select count(*)::integer
        from public.quiz_results results
        where results.session_id = eligibility.session_id
          and results.student_id = eligibility.student_id
    ))
end
from public.quiz_sessions assignments
where assignments.id = eligibility.session_id
  and eligibility.last_retake_granted_at is null;

create or replace function public.refresh_source_quiz_usage()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if tg_op in ('UPDATE', 'DELETE') and old.source_quiz_id is not null then
        update public.quizzes
        set usage_count = (
            select count(*)::integer
            from public.quiz_sessions assignments
            where assignments.source_quiz_id = old.source_quiz_id
              and assignments.class_id is not null
        )
        where id = old.source_quiz_id;
    end if;
    if tg_op in ('INSERT', 'UPDATE') and new.source_quiz_id is not null then
        update public.quizzes
        set usage_count = (
            select count(*)::integer
            from public.quiz_sessions assignments
            where assignments.source_quiz_id = new.source_quiz_id
              and assignments.class_id is not null
        )
        where id = new.source_quiz_id;
    end if;
    if tg_op = 'DELETE' then
        return old;
    end if;
    return new;
end;
$$;

drop trigger if exists quiz_sessions_refresh_source_usage_insert_delete on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage_update on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage on public.quiz_sessions;
create trigger quiz_sessions_refresh_source_usage_insert_delete
after insert or delete on public.quiz_sessions
for each row execute function public.refresh_source_quiz_usage();
create trigger quiz_sessions_refresh_source_usage_update
after update of source_quiz_id, class_id on public.quiz_sessions
for each row execute function public.refresh_source_quiz_usage();

update public.quizzes originals
set usage_count = (
    select count(*)::integer
    from public.quiz_sessions assignments
    where assignments.source_quiz_id = originals.id
      and assignments.class_id is not null
);

notify pgrst, 'reload schema';

commit;
