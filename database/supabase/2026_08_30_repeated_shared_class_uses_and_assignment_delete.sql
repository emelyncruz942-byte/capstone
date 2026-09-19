-- Count repeated shared-library assignment events and support atomic deletion
-- of waiting/active assignments. Safe to run more than once.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.quizzes') is null
       or to_regclass('public.quiz_sessions') is null
       or to_regclass('public.questions') is null
       or to_regclass('public.quiz_results') is null
       or to_regclass('public.quiz_participants') is null
       or to_regclass('public.quiz_session_students') is null then
        raise exception 'Required quiz tables are missing. Run the reusable-quiz and August 29 migrations first.';
    end if;
end
$$;

-- One use is one assignment event made by someone other than the source quiz
-- owner. A later assignment to the same class is therefore another use, while
-- assigning a quiz from its creator's own VR Quiz Bees remains zero-impact.
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

drop trigger if exists quizzes_refresh_source_usage on public.quizzes;
drop trigger if exists quiz_sessions_refresh_source_usage on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage_insert_delete on public.quiz_sessions;
drop trigger if exists quiz_sessions_refresh_source_usage_update on public.quiz_sessions;

create trigger quiz_sessions_refresh_source_usage_insert_delete
after insert or delete on public.quiz_sessions
for each row execute function public.refresh_source_quiz_usage();

create trigger quiz_sessions_refresh_source_usage_update
after update of source_quiz_id, class_id, teacher_id on public.quiz_sessions
for each row execute function public.refresh_source_quiz_usage();

-- Older deployments of the multi-class assignment RPC finish by writing a
-- distinct-class total. Recalculate any attempted counter write so that old
-- function bodies cannot overwrite the event-based value installed here.
create or replace function public.enforce_quiz_usage_count()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    new.usage_count := (
        select count(*)::integer
        from public.quiz_sessions assignments
        where assignments.source_quiz_id = new.id
          and assignments.class_id is not null
          and assignments.teacher_id <> new.teacher_id
    );
    return new;
end;
$$;

drop trigger if exists quizzes_enforce_usage_count on public.quizzes;
create trigger quizzes_enforce_usage_count
before insert or update of usage_count, teacher_id on public.quizzes
for each row execute function public.enforce_quiz_usage_count();

-- Correct every existing counter immediately using the new definition.
update public.quizzes originals
set usage_count = (
    select count(*)::integer
    from public.quiz_sessions assignments
    where assignments.source_quiz_id = originals.id
      and assignments.class_id is not null
      and assignments.teacher_id <> originals.teacher_id
);

-- Delete the assignment and all of its dependent gameplay rows in one
-- transaction. Ended assignments are deliberately immutable.
create or replace function public.delete_open_quiz_assignment(
    p_teacher_id uuid,
    p_class_id uuid,
    p_session_id uuid
)
returns table (
    was_shared_assignment boolean,
    remaining_usage_count integer
)
language plpgsql
security definer
set search_path = public
as $$
declare
    assignment_row public.quiz_sessions%rowtype;
    source_owner_id uuid;
    shared_assignment boolean := false;
    remaining_count integer := 0;
begin
    select assignments.*
    into assignment_row
    from public.quiz_sessions assignments
    where assignments.id = p_session_id
    for update;

    if not found then
        raise exception using errcode = 'P0002', message = 'Quiz assignment not found.';
    end if;
    if assignment_row.teacher_id is distinct from p_teacher_id
       or assignment_row.class_id is distinct from p_class_id then
        raise exception using errcode = '42501', message = 'You do not own this quiz assignment.';
    end if;
    if coalesce(assignment_row.status, 'waiting') not in ('waiting', 'active') then
        raise exception using errcode = '22023', message = 'Ended quiz assignments cannot be deleted.';
    end if;

    if assignment_row.source_quiz_id is not null then
        select source_quiz.teacher_id
        into source_owner_id
        from public.quizzes source_quiz
        where source_quiz.id = assignment_row.source_quiz_id;

        shared_assignment := source_owner_id is not null
            and source_owner_id <> p_teacher_id;
    end if;

    delete from public.quiz_results results
    where results.session_id = p_session_id;

    delete from public.quiz_participants participants
    where participants.session_id = p_session_id;

    delete from public.quiz_session_students eligibility
    where eligibility.session_id = p_session_id;

    delete from public.questions questions
    where questions.session_id = p_session_id;

    delete from public.quiz_sessions assignments
    where assignments.id = p_session_id;

    if assignment_row.source_quiz_id is not null then
        update public.quizzes originals
        set usage_count = (
            select count(*)::integer
            from public.quiz_sessions assignments
            where assignments.source_quiz_id = originals.id
              and assignments.class_id is not null
              and assignments.teacher_id <> originals.teacher_id
        )
        where originals.id = assignment_row.source_quiz_id
        returning originals.usage_count into remaining_count;
    end if;

    was_shared_assignment := shared_assignment;
    remaining_usage_count := coalesce(remaining_count, 0);
    return next;
end;
$$;

revoke all on function public.delete_open_quiz_assignment(uuid, uuid, uuid)
from public, anon, authenticated;
grant execute on function public.delete_open_quiz_assignment(uuid, uuid, uuid)
to service_role;

notify pgrst, 'reload schema';

commit;
