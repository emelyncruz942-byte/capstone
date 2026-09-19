-- MathVerse reusable quiz library and class-page redesign
-- Run once in the Supabase SQL Editor before deploying the matching application code.

begin;

create extension if not exists pg_trgm with schema extensions;
set local search_path = public, extensions;

-- The classes table gains only the requested grade level. Visual settings live
-- in a separate one-to-one table so class records stay simple.
alter table public.classes
    add column if not exists grade_level integer;

update public.classes as c
set grade_level = coalesce(
    (
        select p.grade_level
        from public.class_members as cm
        join public.profiles as p on p.id = cm.student_id
        where cm.class_id = c.id
          and p.grade_level between 1 and 6
        group by p.grade_level
        order by count(*) desc, p.grade_level
        limit 1
    ),
    1
)
where c.grade_level is null
   or c.grade_level not between 1 and 6;

alter table public.classes
    alter column grade_level set default 1,
    alter column grade_level set not null;

do $$
begin
    if not exists (
        select 1
        from pg_constraint
        where conname = 'classes_grade_level_check'
          and conrelid = 'public.classes'::regclass
    ) then
        alter table public.classes
            add constraint classes_grade_level_check
            check (grade_level between 1 and 6);
    end if;
end
$$;

create table if not exists public.class_customizations (
    class_id uuid primary key references public.classes(id) on delete cascade,
    theme_color text not null default '#f59e0b'
        check (theme_color = any (array[
            '#f59e0b', '#06b6d4', '#8b5cf6',
            '#22c55e', '#ec4899', '#3b82f6'
        ])),
    icon text not null default 'chalkboard'
        check (icon = any (array[
            'chalkboard', 'calculator', 'rocket',
            'atom', 'shapes', 'gamepad'
        ])),
    banner_pattern text not null default 'grid'
        check (banner_pattern = any (array[
            'grid', 'stars', 'circuit', 'waves', 'plain'
        ])),
    updated_at timestamp with time zone not null default timezone('utc', now())
);

-- Reusable quiz content is deliberately separate from playable sessions.
create table if not exists public.quizzes (
    id uuid primary key default uuid_generate_v4(),
    teacher_id uuid not null references public.profiles(id),
    topic text not null check (char_length(btrim(topic)) between 1 and 150),
    grade_level integer not null check (grade_level between 1 and 6),
    created_at timestamp with time zone not null default timezone('utc', now()),
    updated_at timestamp with time zone not null default timezone('utc', now())
);

create table if not exists public.quiz_questions (
    id bigint generated always as identity primary key,
    quiz_id uuid not null references public.quizzes(id) on delete cascade,
    position integer not null check (position > 0),
    grade integer not null check (grade between 1 and 6),
    type text not null default 'multiple_choice',
    question text not null,
    choice1 text not null,
    choice2 text not null,
    choice3 text not null,
    choice4 text not null,
    choice5 text,
    choice6 text,
    correct_answer text not null,
    created_at timestamp with time zone not null default timezone('utc', now()),
    unique (quiz_id, position)
);

grant select, insert, update, delete
    on public.quizzes, public.quiz_questions, public.class_customizations
    to authenticated, service_role;

grant usage, select
    on sequence public.quiz_questions_id_seq
    to authenticated, service_role;

alter table public.quiz_sessions
    add column if not exists source_quiz_id uuid;

-- Keep the exact legacy values needed by the paired rollback. The archive is
-- intentionally retained after rollback so it can also be audited manually.
create table if not exists public.rollback_quiz_session_state_20260827 (
    session_id uuid primary key,
    status text,
    is_active boolean,
    max_members integer
);
alter table public.rollback_quiz_session_state_20260827 enable row level security;
revoke all privileges on table public.rollback_quiz_session_state_20260827
from public, anon, authenticated, service_role;

insert into public.rollback_quiz_session_state_20260827 (
    session_id, status, is_active, max_members
)
select id, status, is_active, max_members
from public.quiz_sessions
on conflict (session_id) do nothing;

do $$
begin
    if not exists (
        select 1
        from pg_constraint
        where conname = 'quiz_sessions_source_quiz_id_fkey'
          and conrelid = 'public.quiz_sessions'::regclass
    ) then
        alter table public.quiz_sessions
            add constraint quiz_sessions_source_quiz_id_fkey
            foreign key (source_quiz_id)
            references public.quizzes(id)
            on delete set null;
    end if;
end
$$;

-- There is no participant-limit field in quiz creation or assignment. The VR
-- protocol still receives its expected value, fixed at 60 for new sessions.
alter table public.quiz_sessions
    alter column max_members set default 60;

update public.quiz_sessions
set max_members = 60
where max_members is distinct from 60;

-- Preserve every existing quiz by copying it into the reusable library. Its
-- existing session/questions remain untouched for historical results.
insert into public.quizzes (
    id, teacher_id, topic, grade_level, created_at, updated_at
)
select
    qs.id,
    qs.teacher_id,
    coalesce(nullif(btrim(qs.topic), ''), 'Mixed Math'),
    case
        when c.grade_level between 1 and 6 then c.grade_level
        else coalesce((
            select min(q.grade)
            from public.questions as q
            where q.session_id = qs.id
              and q.grade between 1 and 6
        ), 1)
    end,
    coalesce(qs.created_at, timezone('utc', now())),
    coalesce(qs.created_at, timezone('utc', now()))
from public.quiz_sessions as qs
left join public.classes as c on c.id = qs.class_id
where qs.source_quiz_id is null
on conflict (id) do nothing;

insert into public.quiz_questions (
    quiz_id, position, grade, type, question,
    choice1, choice2, choice3, choice4, choice5, choice6,
    correct_answer, created_at
)
select
    q.session_id,
    row_number() over (partition by q.session_id order by q.id)::integer,
    case when q.grade between 1 and 6 then q.grade else z.grade_level end,
    q.type,
    q.question,
    q.choice1,
    q.choice2,
    q.choice3,
    q.choice4,
    q.choice5,
    q.choice6,
    q.correct_answer,
    q.created_at
from public.questions as q
join public.quizzes as z on z.id = q.session_id
where q.session_id is not null
  and not exists (
      select 1
      from public.quiz_questions as qq
      where qq.quiz_id = q.session_id
  );

update public.quiz_sessions
set source_quiz_id = id
where source_quiz_id is null
  and exists (
      select 1 from public.quizzes where quizzes.id = quiz_sessions.id
  );

-- Old unassigned sessions are now represented by their reusable quiz records.
-- Keep the session rows for old results, but prevent their old codes from being
-- presented as current assignments.
update public.quiz_sessions
set status = 'completed',
    is_active = false
where class_id is null;

create index if not exists quizzes_grade_created_idx
    on public.quizzes (grade_level, created_at desc);

create index if not exists quizzes_teacher_created_idx
    on public.quizzes (teacher_id, created_at desc);

create index if not exists quizzes_topic_search_idx
    on public.quizzes using gin (topic gin_trgm_ops);

create index if not exists quiz_questions_quiz_position_idx
    on public.quiz_questions (quiz_id, position);

create index if not exists quiz_sessions_class_status_idx
    on public.quiz_sessions (class_id, status, created_at desc);

create unique index if not exists quiz_sessions_one_open_quiz_per_class_idx
    on public.quiz_sessions (class_id, source_quiz_id)
    where class_id is not null
      and source_quiz_id is not null
      and status in ('waiting', 'active');

-- Enforce grade matching in the database as well as in Laravel.
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
    select grade_level into student_grade
    from public.profiles
    where id = new.student_id;

    select grade_level, teacher_id into target_grade, target_teacher
    from public.classes
    where id = new.class_id;

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

drop trigger if exists class_members_grade_guard on public.class_members;
create trigger class_members_grade_guard
before insert or update on public.class_members
for each row execute function public.enforce_class_member_grade();

create or replace function public.prevent_student_membership_exit()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    old_class_teacher uuid;
    new_class_teacher uuid;
begin
    select teacher_id into old_class_teacher
    from public.classes
    where id = old.class_id;

    if tg_op = 'UPDATE' then
        select teacher_id into new_class_teacher
        from public.classes
        where id = new.class_id;
    end if;

    if auth.uid() is not null
       and (
           auth.uid() is distinct from old_class_teacher
           or (tg_op = 'UPDATE' and auth.uid() is distinct from new_class_teacher)
       ) then
        raise exception 'Only the class teacher can remove or move a student membership';
    end if;

    if tg_op = 'DELETE' then
        return old;
    end if;

    return new;
end;
$$;

drop trigger if exists class_members_student_exit_guard on public.class_members;
create trigger class_members_student_exit_guard
before update or delete on public.class_members
for each row execute function public.prevent_student_membership_exit();

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

drop trigger if exists profiles_grade_change_guard on public.profiles;
create trigger profiles_grade_change_guard
before update of grade_level on public.profiles
for each row execute function public.prevent_enrolled_student_grade_change();

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

drop trigger if exists classes_grade_change_guard on public.classes;
create trigger classes_grade_change_guard
before update of grade_level on public.classes
for each row execute function public.prevent_enrolled_class_grade_change();

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
    if new.source_quiz_id is null or new.class_id is null then
        return new;
    end if;

    select grade_level into quiz_grade
    from public.quizzes
    where id = new.source_quiz_id;

    select grade_level into target_grade
    from public.classes
    where id = new.class_id;

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

-- New library tables are readable by teachers, but only the creator can change
-- a reusable quiz. Class customization follows class ownership/membership.
alter table public.quizzes enable row level security;
alter table public.quiz_questions enable row level security;
alter table public.class_customizations enable row level security;
alter table public.class_members enable row level security;

drop policy if exists class_members_student_self_select on public.class_members;
create policy class_members_student_self_select
on public.class_members for select to authenticated
using (student_id = auth.uid());

drop policy if exists class_members_student_join on public.class_members;
create policy class_members_student_join
on public.class_members for insert to authenticated
with check (student_id = auth.uid());

drop policy if exists class_members_teacher_select on public.class_members;
create policy class_members_teacher_select
on public.class_members for select to authenticated
using (
    exists (
        select 1 from public.classes
        where classes.id = class_members.class_id
          and classes.teacher_id = auth.uid()
    )
);

-- Restrictive means any older permissive roster policy must also satisfy this
-- boundary: students see only their own membership; the class owner sees all.
drop policy if exists class_members_roster_boundary on public.class_members;
create policy class_members_roster_boundary
on public.class_members as restrictive for select to authenticated
using (
    student_id = auth.uid()
    or exists (
        select 1 from public.classes
        where classes.id = class_members.class_id
          and classes.teacher_id = auth.uid()
    )
);

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

drop policy if exists quizzes_owner_insert on public.quizzes;
create policy quizzes_owner_insert
on public.quizzes for insert to authenticated
with check (teacher_id = auth.uid());

drop policy if exists quizzes_owner_update on public.quizzes;
create policy quizzes_owner_update
on public.quizzes for update to authenticated
using (teacher_id = auth.uid())
with check (teacher_id = auth.uid());

drop policy if exists quizzes_owner_delete on public.quizzes;
create policy quizzes_owner_delete
on public.quizzes for delete to authenticated
using (teacher_id = auth.uid());

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

drop policy if exists quiz_questions_owner_insert on public.quiz_questions;
create policy quiz_questions_owner_insert
on public.quiz_questions for insert to authenticated
with check (
    exists (
        select 1 from public.quizzes
        where quizzes.id = quiz_questions.quiz_id
          and quizzes.teacher_id = auth.uid()
    )
);

drop policy if exists quiz_questions_owner_update on public.quiz_questions;
create policy quiz_questions_owner_update
on public.quiz_questions for update to authenticated
using (
    exists (
        select 1 from public.quizzes
        where quizzes.id = quiz_questions.quiz_id
          and quizzes.teacher_id = auth.uid()
    )
)
with check (
    exists (
        select 1 from public.quizzes
        where quizzes.id = quiz_questions.quiz_id
          and quizzes.teacher_id = auth.uid()
    )
);

drop policy if exists quiz_questions_owner_delete on public.quiz_questions;
create policy quiz_questions_owner_delete
on public.quiz_questions for delete to authenticated
using (
    exists (
        select 1 from public.quizzes
        where quizzes.id = quiz_questions.quiz_id
          and quizzes.teacher_id = auth.uid()
    )
);

drop policy if exists class_customizations_member_read on public.class_customizations;
create policy class_customizations_member_read
on public.class_customizations for select to authenticated
using (
    exists (
        select 1 from public.classes
        where classes.id = class_customizations.class_id
          and classes.teacher_id = auth.uid()
    )
    or exists (
        select 1 from public.class_members
        where class_members.class_id = class_customizations.class_id
          and class_members.student_id = auth.uid()
    )
);

drop policy if exists class_customizations_owner_insert on public.class_customizations;
create policy class_customizations_owner_insert
on public.class_customizations for insert to authenticated
with check (
    exists (
        select 1 from public.classes
        where classes.id = class_customizations.class_id
          and classes.teacher_id = auth.uid()
    )
);

drop policy if exists class_customizations_owner_update on public.class_customizations;
create policy class_customizations_owner_update
on public.class_customizations for update to authenticated
using (
    exists (
        select 1 from public.classes
        where classes.id = class_customizations.class_id
          and classes.teacher_id = auth.uid()
    )
)
with check (
    exists (
        select 1 from public.classes
        where classes.id = class_customizations.class_id
          and classes.teacher_id = auth.uid()
    )
);

drop policy if exists class_customizations_owner_delete on public.class_customizations;
create policy class_customizations_owner_delete
on public.class_customizations for delete to authenticated
using (
    exists (
        select 1 from public.classes
        where classes.id = class_customizations.class_id
          and classes.teacher_id = auth.uid()
    )
);

commit;
