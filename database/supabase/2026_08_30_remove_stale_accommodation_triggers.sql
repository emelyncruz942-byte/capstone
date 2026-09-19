-- Remove stale database-trigger dependencies on the deleted accommodations
-- feature. Safe to rerun after the August 29 scheduling migration.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.quiz_sessions') is null
       or to_regclass('public.class_members') is null
       or to_regclass('public.quiz_session_students') is null then
        raise exception 'Required assignment tables are missing. Run the August 29 scheduling migration first.';
    end if;
end
$$;

-- An early version stored extended-time settings in this table and copied
-- them through the two trigger functions below. The feature was removed, but
-- a database that only dropped the table can retain the old function bodies.
do $$
begin
    if to_regclass('public.class_member_accommodations') is not null then
        execute 'drop trigger if exists class_member_accommodation_propagation on public.class_member_accommodations';
    end if;
end
$$;

drop function if exists public.propagate_member_accommodation();
drop table if exists public.class_member_accommodations;

alter table public.quiz_session_students
    drop column if exists additional_time_seconds;

create or replace function public.seed_quiz_session_students()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if new.class_id is null then
        return new;
    end if;

    insert into public.quiz_session_students (
        session_id,
        student_id,
        eligible_at
    )
    select
        new.id,
        class_members.student_id,
        coalesce(new.assigned_at, timezone('utc', now()))
    from public.class_members class_members
    where class_members.class_id = new.class_id
    on conflict (session_id, student_id) do nothing;

    return new;
end;
$$;

drop trigger if exists quiz_sessions_seed_students on public.quiz_sessions;
create trigger quiz_sessions_seed_students
after insert on public.quiz_sessions
for each row execute function public.seed_quiz_session_students();

create or replace function public.add_member_to_open_quiz_sessions()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    insert into public.quiz_session_students (
        session_id,
        student_id,
        eligible_at
    )
    select
        quiz_sessions.id,
        new.student_id,
        coalesce(new.joined_at, timezone('utc', now()))
    from public.quiz_sessions quiz_sessions
    where quiz_sessions.class_id = new.class_id
      and quiz_sessions.status in ('waiting', 'active')
      and (
          quiz_sessions.due_at is null
          or quiz_sessions.due_at > timezone('utc', now())
      )
    on conflict (session_id, student_id) do nothing;

    return new;
end;
$$;

drop trigger if exists class_members_open_quiz_eligibility on public.class_members;
create trigger class_members_open_quiz_eligibility
after insert on public.class_members
for each row execute function public.add_member_to_open_quiz_sessions();

-- Repair eligibility that may be missing from assignments or memberships
-- created before the stale trigger first failed.
insert into public.quiz_session_students (
    session_id,
    student_id,
    eligible_at
)
select
    quiz_sessions.id,
    class_members.student_id,
    greatest(
        coalesce(class_members.joined_at, timezone('utc', now())),
        coalesce(quiz_sessions.assigned_at, quiz_sessions.created_at, timezone('utc', now()))
    )
from public.quiz_sessions quiz_sessions
join public.class_members class_members
  on class_members.class_id = quiz_sessions.class_id
where quiz_sessions.status in ('waiting', 'active')
  and (
      quiz_sessions.due_at is null
      or quiz_sessions.due_at > timezone('utc', now())
  )
on conflict (session_id, student_id) do nothing;

notify pgrst, 'reload schema';

commit;

-- Expected result: both functions are accommodation-free, the obsolete table
-- is absent, and the obsolete eligibility column is absent.
select
    to_regclass('public.class_member_accommodations') is null
        as accommodation_table_removed,
    not exists (
        select 1
        from information_schema.columns columns
        where columns.table_schema = 'public'
          and columns.table_name = 'quiz_session_students'
          and columns.column_name = 'additional_time_seconds'
    ) as accommodation_column_removed,
    position(
        'class_member_accommodations' in pg_get_functiondef(
            'public.seed_quiz_session_students()'::regprocedure
        )
    ) = 0 as assignment_trigger_fixed,
    position(
        'class_member_accommodations' in pg_get_functiondef(
            'public.add_member_to_open_quiz_sessions()'::regprocedure
        )
    ) = 0 as class_join_trigger_fixed;
