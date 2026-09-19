-- MathVerse assignment scheduling, fair attempts, library governance, audit,
-- version restoration, and scale indexes.
-- Run after 2026_08_28_archived_classes_and_single_attempts.sql.

begin;

set local search_path = public, extensions;

-- -------------------------------------------------------------------------
-- Assignment lifecycle and reusable-quiz governance
-- -------------------------------------------------------------------------

alter table public.quiz_sessions
    add column if not exists assigned_at timestamp with time zone,
    add column if not exists available_at timestamp with time zone,
    add column if not exists due_at timestamp with time zone,
    add column if not exists started_at timestamp with time zone,
    add column if not exists ended_at timestamp with time zone,
    add column if not exists retake_mode boolean not null default false;

update public.quiz_sessions
set assigned_at = coalesce(assigned_at, created_at, timezone('utc', now())),
    available_at = case
        when status in ('active', 'completed')
            then coalesce(available_at, created_at, timezone('utc', now()))
        else available_at
    end,
    started_at = case
        when status = 'active' then coalesce(started_at, created_at, timezone('utc', now()))
        when status = 'completed' then coalesce(started_at, created_at, timezone('utc', now()))
        else started_at
    end,
    ended_at = case
        when status = 'completed' then coalesce(
            ended_at,
            (select max(qr.created_at) from public.quiz_results qr where qr.session_id = quiz_sessions.id),
            created_at,
            timezone('utc', now())
        )
        else ended_at
    end;

alter table public.quiz_sessions
    alter column assigned_at set default timezone('utc', now()),
    alter column assigned_at set not null,
    alter column available_at drop default;

alter table public.quiz_sessions
    drop constraint if exists quiz_sessions_schedule_check;
alter table public.quiz_sessions
    add constraint quiz_sessions_schedule_check
    check (due_at is null or due_at > coalesce(available_at, assigned_at));

alter table public.quizzes
    add column if not exists visibility text not null default 'shared',
    add column if not exists source_quiz_id uuid,
    add column if not exists version integer not null default 1,
    add column if not exists usage_count integer not null default 0,
    add column if not exists rating_average numeric(3,2) not null default 0,
    add column if not exists rating_count integer not null default 0,
    add column if not exists verified_at timestamp with time zone,
    add column if not exists verified_by uuid;

alter table public.quizzes
    add column if not exists is_verified boolean
    generated always as (verified_at is not null) stored;

do $$
begin
    if not exists (
        select 1 from pg_constraint
        where conname = 'quizzes_visibility_check'
          and conrelid = 'public.quizzes'::regclass
    ) then
        alter table public.quizzes
            add constraint quizzes_visibility_check
            check (visibility in ('private', 'shared'));
    end if;

    if not exists (
        select 1 from pg_constraint
        where conname = 'quizzes_version_check'
          and conrelid = 'public.quizzes'::regclass
    ) then
        alter table public.quizzes
            add constraint quizzes_version_check check (version > 0),
            add constraint quizzes_usage_count_check check (usage_count >= 0),
            add constraint quizzes_rating_summary_check
                check (rating_count >= 0 and rating_average between 0 and 5);
    end if;

    if not exists (
        select 1 from pg_constraint
        where conname = 'quizzes_source_quiz_id_fkey'
          and conrelid = 'public.quizzes'::regclass
    ) then
        alter table public.quizzes
            add constraint quizzes_source_quiz_id_fkey
            foreign key (source_quiz_id) references public.quizzes(id) on delete set null;
    end if;

    if not exists (
        select 1 from pg_constraint
        where conname = 'quizzes_verified_by_fkey'
          and conrelid = 'public.quizzes'::regclass
    ) then
        alter table public.quizzes
            add constraint quizzes_verified_by_fkey
            foreign key (verified_by) references public.profiles(id) on delete set null;
    end if;
end
$$;

create table if not exists public.quiz_versions (
    id uuid primary key default uuid_generate_v4(),
    quiz_id uuid not null references public.quizzes(id) on delete cascade,
    version integer not null check (version > 0),
    topic text not null,
    grade_level integer not null check (grade_level between 1 and 6),
    visibility text not null check (visibility in ('private', 'shared')),
    questions jsonb not null default '[]'::jsonb,
    created_by uuid references public.profiles(id) on delete set null,
    created_at timestamp with time zone not null default timezone('utc', now()),
    unique (quiz_id, version)
);

create table if not exists public.quiz_bookmarks (
    quiz_id uuid not null references public.quizzes(id) on delete cascade,
    user_id uuid not null references public.profiles(id) on delete cascade,
    created_at timestamp with time zone not null default timezone('utc', now()),
    primary key (quiz_id, user_id)
);

create table if not exists public.quiz_ratings (
    quiz_id uuid not null references public.quizzes(id) on delete cascade,
    user_id uuid not null references public.profiles(id) on delete cascade,
    rating integer not null check (rating between 1 and 5),
    created_at timestamp with time zone not null default timezone('utc', now()),
    updated_at timestamp with time zone not null default timezone('utc', now()),
    primary key (quiz_id, user_id)
);

create table if not exists public.quiz_reports (
    id uuid primary key default uuid_generate_v4(),
    quiz_id uuid not null references public.quizzes(id) on delete cascade,
    question_id bigint references public.quiz_questions(id) on delete set null,
    reporter_id uuid references public.profiles(id) on delete set null,
    reason text not null check (reason in (
        'incorrect_answer', 'unclear_question', 'inappropriate', 'duplicate', 'other'
    )),
    details text check (details is null or char_length(details) <= 1000),
    status text not null default 'pending'
        check (status in ('pending', 'reviewed', 'dismissed')),
    reviewed_by uuid references public.profiles(id) on delete set null,
    reviewed_at timestamp with time zone,
    created_at timestamp with time zone not null default timezone('utc', now())
);

-- Keep partial reruns safe if quiz_reports was created before question-specific
-- reports were introduced.
alter table public.quiz_reports
    add column if not exists question_id bigint;

do $$
begin
    if not exists (
        select 1 from pg_constraint
        where conname = 'quiz_reports_question_id_fkey'
          and conrelid = 'public.quiz_reports'::regclass
    ) then
        alter table public.quiz_reports
            add constraint quiz_reports_question_id_fkey
            foreign key (question_id) references public.quiz_questions(id)
            on delete set null;
    end if;
end
$$;

-- Add account controls before result/retake functions refer to them.
alter table public.profiles
    add column if not exists suspended_at timestamp with time zone,
    add column if not exists suspended_by uuid,
    add column if not exists suspension_reason text,
    add column if not exists leaderboard_alias text,
    add column if not exists show_on_leaderboard boolean not null default true;

do $$
begin
    if not exists (
        select 1 from pg_constraint
        where conname = 'profiles_suspended_by_fkey'
          and conrelid = 'public.profiles'::regclass
    ) then
        alter table public.profiles
            add constraint profiles_suspended_by_fkey
            foreign key (suspended_by) references public.profiles(id) on delete set null,
            add constraint profiles_suspension_reason_check
                check (suspension_reason is null or char_length(suspension_reason) <= 500),
            add constraint profiles_leaderboard_alias_check
                check (leaderboard_alias is null or char_length(btrim(leaderboard_alias)) between 2 and 30);
    end if;
end
$$;

-- -------------------------------------------------------------------------
-- Per-student eligibility and retained retake history
-- -------------------------------------------------------------------------

-- Remove the former extended-time feature on reruns of an earlier draft.
do $$
begin
    if to_regclass('public.class_member_accommodations') is not null then
        execute 'drop trigger if exists class_member_accommodation_propagation on public.class_member_accommodations';
    end if;
end
$$;
drop function if exists public.propagate_member_accommodation();
drop table if exists public.class_member_accommodations;

create table if not exists public.quiz_session_students (
    session_id uuid not null references public.quiz_sessions(id) on delete cascade,
    student_id uuid not null references public.profiles(id) on delete cascade,
    eligibility_status text not null default 'eligible'
        check (eligibility_status in ('eligible', 'excused')),
    allowed_attempts integer not null default 1 check (allowed_attempts >= 0),
    eligible_at timestamp with time zone not null default timezone('utc', now()),
    excused_at timestamp with time zone,
    excused_by uuid references public.profiles(id) on delete set null,
    excuse_reason text check (excuse_reason is null or char_length(excuse_reason) <= 500),
    last_retake_granted_at timestamp with time zone,
    last_retake_granted_by uuid references public.profiles(id) on delete set null,
    retake_due_at timestamp with time zone,
    retake_reason text check (retake_reason is null or char_length(retake_reason) <= 500),
    primary key (session_id, student_id)
);

alter table public.quiz_session_students
    drop column if exists additional_time_seconds;

alter table public.quiz_results
    add column if not exists attempt_number integer,
    add column if not exists is_counted boolean not null default true;

update public.quiz_results set attempt_number = 1 where attempt_number is null;

alter table public.quiz_results
    alter column attempt_number set default 1,
    alter column attempt_number set not null;

do $$
begin
    if not exists (
        select 1 from pg_constraint
        where conname = 'quiz_results_attempt_number_check'
          and conrelid = 'public.quiz_results'::regclass
    ) then
        alter table public.quiz_results
            add constraint quiz_results_attempt_number_check check (attempt_number > 0),
            add constraint quiz_results_score_range_check check (
                total_questions >= 0
                and correct_answers >= 0
                and correct_answers <= total_questions
            );
    end if;
end
$$;

drop trigger if exists quiz_results_first_attempt_guard on public.quiz_results;
drop function if exists public.ignore_repeat_quiz_result();
drop index if exists public.quiz_results_one_attempt_per_assignment_idx;

create unique index if not exists quiz_results_attempt_number_idx
    on public.quiz_results (session_id, student_id, attempt_number);

create unique index if not exists quiz_results_one_counted_attempt_idx
    on public.quiz_results (session_id, student_id) where is_counted;

-- Existing completed assignments are frozen at the number of attempts already
-- stored. Open assignments allow one initial attempt.
insert into public.quiz_session_students (
    session_id, student_id, eligibility_status, allowed_attempts, eligible_at
)
select
    qs.id,
    cm.student_id,
    'eligible',
    case
        when qs.status = 'completed' then (
            select count(*)::integer from public.quiz_results qr
            where qr.session_id = qs.id and qr.student_id = cm.student_id
        )
        else greatest(1, (
            select count(*)::integer from public.quiz_results qr
            where qr.session_id = qs.id and qr.student_id = cm.student_id
        ))
    end,
    greatest(cm.joined_at, qs.assigned_at)
from public.quiz_sessions qs
join public.class_members cm on cm.class_id = qs.class_id
where cm.joined_at <= coalesce(qs.ended_at, qs.due_at, timezone('utc', now()))
on conflict (session_id, student_id) do nothing;

-- Preserve results belonging to students no longer in the current roster.
insert into public.quiz_session_students (
    session_id, student_id, eligibility_status, allowed_attempts, eligible_at
)
select
    qr.session_id,
    qr.student_id,
    'eligible',
    count(*)::integer,
    min(coalesce(qr.created_at, timezone('utc', now())))
from public.quiz_results qr
group by qr.session_id, qr.student_id
on conflict (session_id, student_id) do update
set allowed_attempts = greatest(
    quiz_session_students.allowed_attempts,
    excluded.allowed_attempts
);

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

    insert into public.quiz_session_students (session_id, student_id, eligible_at)
    select
        new.id,
        cm.student_id,
        coalesce(new.assigned_at, timezone('utc', now()))
    from public.class_members cm
    where cm.class_id = new.class_id
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
    insert into public.quiz_session_students (session_id, student_id, eligible_at)
    select
        qs.id,
        new.student_id,
        coalesce(new.joined_at, timezone('utc', now()))
    from public.quiz_sessions qs
    where qs.class_id = new.class_id
      and qs.status in ('waiting', 'active')
      and (qs.due_at is null or qs.due_at > timezone('utc', now()))
    on conflict (session_id, student_id) do nothing;

    return new;
end;
$$;

drop trigger if exists class_members_open_quiz_eligibility on public.class_members;
create trigger class_members_open_quiz_eligibility
after insert on public.class_members
for each row execute function public.add_member_to_open_quiz_sessions();

-- Removing a student must revoke unfinished attempts even if they retained a
-- room code. Completed work remains eligible history; an untaken assignment is
-- recorded as excused rather than unfairly counted as missed.
create or replace function public.revoke_member_open_quiz_eligibility()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    class_teacher uuid;
begin
    select teacher_id into class_teacher
    from public.classes
    where id = old.class_id;

    update public.quiz_session_students qss
    set allowed_attempts = (
            select count(*)::integer
            from public.quiz_results qr
            where qr.session_id = qss.session_id
              and qr.student_id = qss.student_id
        ),
        eligibility_status = case when exists (
            select 1 from public.quiz_results qr
            where qr.session_id = qss.session_id
              and qr.student_id = qss.student_id
        ) then 'eligible' else 'excused' end,
        excused_at = case when exists (
            select 1 from public.quiz_results qr
            where qr.session_id = qss.session_id
              and qr.student_id = qss.student_id
        ) then null else timezone('utc', now()) end,
        excused_by = case when exists (
            select 1 from public.quiz_results qr
            where qr.session_id = qss.session_id
              and qr.student_id = qss.student_id
        ) then null else class_teacher end,
        excuse_reason = case when exists (
            select 1 from public.quiz_results qr
            where qr.session_id = qss.session_id
              and qr.student_id = qss.student_id
        ) then null else 'Removed from class' end
    from public.quiz_sessions qs
    where qss.session_id = qs.id
      and qss.student_id = old.student_id
      and qs.class_id = old.class_id
      and qs.status in ('waiting', 'active');

    return old;
end;
$$;

drop trigger if exists class_members_revoke_quiz_eligibility on public.class_members;
create trigger class_members_revoke_quiz_eligibility
after delete on public.class_members
for each row execute function public.revoke_member_open_quiz_eligibility();

create or replace function public.freeze_completed_assignment_attempts()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if new.status = 'completed' and old.status is distinct from new.status then
        update public.quiz_session_students qss
        set allowed_attempts = (
            select count(*)::integer
            from public.quiz_results qr
            where qr.session_id = qss.session_id
              and qr.student_id = qss.student_id
        )
        where qss.session_id = new.id;
    end if;
    return new;
end;
$$;

drop trigger if exists quiz_sessions_freeze_attempts on public.quiz_sessions;
create trigger quiz_sessions_freeze_attempts
after update of status on public.quiz_sessions
for each row execute function public.freeze_completed_assignment_attempts();

create or replace function public.enforce_quiz_result_attempt()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    assignment public.quiz_sessions%rowtype;
    eligibility public.quiz_session_students%rowtype;
    attempts_used integer;
begin
    select * into assignment
    from public.quiz_sessions
    where id = new.session_id
    for update;

    if assignment.id is null then
        raise exception 'This quiz assignment does not exist';
    end if;

    if assignment.status = 'waiting'
       and assignment.available_at is not null
       and assignment.available_at <= timezone('utc', now())
       and (assignment.due_at is null or assignment.due_at > timezone('utc', now())) then
        update public.quiz_sessions
        set status = 'active',
            is_active = true,
            started_at = coalesce(started_at, available_at, timezone('utc', now()))
        where id = assignment.id;
        assignment.status := 'active';
        assignment.is_active := true;
        assignment.started_at := coalesce(
            assignment.started_at,
            assignment.available_at,
            timezone('utc', now())
        );
    end if;

    if exists (
        select 1 from public.profiles
        where id = new.student_id and suspended_at is not null
    ) then
        raise exception 'This account is suspended';
    end if;

    if assignment.available_at is not null
       and assignment.available_at > timezone('utc', now()) then
        raise exception 'This quiz assignment is not available yet';
    end if;

    if assignment.due_at is not null
       and assignment.due_at <= timezone('utc', now()) then
        raise exception 'This quiz assignment is past due';
    end if;

    if assignment.status <> 'active' or not assignment.is_active then
        raise exception 'This quiz assignment is not active';
    end if;

    select * into eligibility
    from public.quiz_session_students
    where session_id = new.session_id
      and student_id = new.student_id
    for update;

    if eligibility.session_id is null or eligibility.eligibility_status <> 'eligible' then
        raise exception 'This student is not eligible for the quiz assignment';
    end if;

    if eligibility.retake_due_at is not null
       and eligibility.retake_due_at <= timezone('utc', now()) then
        raise exception 'This student retake window is past due';
    end if;

    if auth.uid() is not null and auth.uid() is distinct from new.student_id then
        raise exception 'A student can submit only their own quiz result';
    end if;

    select count(*)::integer into attempts_used
    from public.quiz_results
    where session_id = new.session_id
      and student_id = new.student_id;

    if attempts_used >= eligibility.allowed_attempts then
        raise exception 'No quiz attempts remain for this assignment';
    end if;

    update public.quiz_results
    set is_counted = false
    where session_id = new.session_id
      and student_id = new.student_id
      and is_counted;

    new.attempt_number := attempts_used + 1;
    new.is_counted := true;
    return new;
end;
$$;

drop trigger if exists quiz_results_attempt_guard on public.quiz_results;
create trigger quiz_results_attempt_guard
before insert on public.quiz_results
for each row execute function public.enforce_quiz_result_attempt();

-- Granting a retake changes the student allowance and assignment lifecycle in
-- one database transaction, so a partial controller failure cannot leave them
-- out of sync.
create or replace function public.grant_quiz_retake(
    p_session_id uuid,
    p_student_id uuid,
    p_teacher_id uuid,
    p_reason text,
    p_due_at timestamp with time zone default null
)
returns table (new_allowed_attempts integer, retake_due_at timestamp with time zone)
language plpgsql
security definer
set search_path = public
as $$
declare
    assignment public.quiz_sessions%rowtype;
    eligibility public.quiz_session_students%rowtype;
    attempts_used integer;
    next_allowed integer;
    next_due timestamp with time zone;
    session_due timestamp with time zone;
begin
    select * into assignment
    from public.quiz_sessions
    where id = p_session_id and teacher_id = p_teacher_id
    for update;

    if assignment.id is null then
        raise exception 'Quiz assignment not found for this teacher';
    end if;

    if assignment.status <> 'completed' and not assignment.retake_mode then
        raise exception 'End the original quiz before granting a retake';
    end if;

    select * into eligibility
    from public.quiz_session_students
    where session_id = p_session_id and student_id = p_student_id
    for update;

    if eligibility.session_id is null then
        raise exception 'Student is not eligible for this assignment';
    end if;

    if exists (
        select 1 from public.profiles
        where id = p_student_id and suspended_at is not null
    ) then
        raise exception 'A suspended student cannot receive a retake';
    end if;

    if p_reason is null or char_length(btrim(p_reason)) = 0
       or char_length(p_reason) > 500 then
        raise exception 'A retake reason between 1 and 500 characters is required';
    end if;

    next_due := coalesce(p_due_at, timezone('utc', now()) + interval '1 day');
    if next_due <= timezone('utc', now()) then
        raise exception 'The retake due date must be in the future';
    end if;

    select count(*)::integer into attempts_used
    from public.quiz_results
    where session_id = p_session_id and student_id = p_student_id;

    next_allowed := greatest(attempts_used, eligibility.allowed_attempts) + 1;

    update public.quiz_session_students
    set eligibility_status = 'eligible',
        allowed_attempts = next_allowed,
        excused_at = null,
        excused_by = null,
        excuse_reason = null,
        last_retake_granted_at = timezone('utc', now()),
        last_retake_granted_by = p_teacher_id,
        retake_due_at = next_due,
        retake_reason = btrim(p_reason)
    where session_id = p_session_id and student_id = p_student_id;

    select greatest(
        next_due,
        max(qss.retake_due_at),
        case
            when assignment.status in ('waiting', 'active') and not assignment.retake_mode
                then assignment.due_at
            else null
        end
    ) into session_due
    from public.quiz_session_students qss
    where qss.session_id = p_session_id;

    update public.quiz_sessions
    set status = 'active',
        is_active = true,
        retake_mode = true,
        available_at = timezone('utc', now()),
        due_at = session_due,
        ended_at = null
    where id = p_session_id;

    return query select next_allowed, next_due;
end;
$$;

revoke all on function public.grant_quiz_retake(uuid, uuid, uuid, text, timestamp with time zone)
from public, anon, authenticated;
grant execute on function public.grant_quiz_retake(uuid, uuid, uuid, text, timestamp with time zone)
to service_role;

-- -------------------------------------------------------------------------
-- Ratings, usage statistics, account controls, and immutable audit events
-- -------------------------------------------------------------------------

create or replace function public.refresh_quiz_rating_summary()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    target_quiz uuid;
begin
    target_quiz := case when tg_op = 'DELETE' then old.quiz_id else new.quiz_id end;
    update public.quizzes
    set rating_average = coalesce((
            select round(avg(rating)::numeric, 2)
            from public.quiz_ratings where quiz_id = target_quiz
        ), 0),
        rating_count = (
            select count(*)::integer
            from public.quiz_ratings where quiz_id = target_quiz
        )
    where id = target_quiz;
    if tg_op = 'DELETE' then
        return old;
    end if;
    return new;
end;
$$;

drop trigger if exists quiz_ratings_refresh_summary on public.quiz_ratings;
create trigger quiz_ratings_refresh_summary
after insert or update or delete on public.quiz_ratings
for each row execute function public.refresh_quiz_rating_summary();

create or replace function public.auto_verify_admin_quiz()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if exists (
        select 1 from public.profiles
        where id = new.teacher_id and role = 'admin'
    ) then
        new.verified_at := coalesce(new.verified_at, timezone('utc', now()));
        new.verified_by := coalesce(new.verified_by, new.teacher_id);
    end if;
    return new;
end;
$$;

drop trigger if exists quizzes_auto_verify_admin on public.quizzes;
create trigger quizzes_auto_verify_admin
before insert or update of teacher_id, verified_at, verified_by on public.quizzes
for each row execute function public.auto_verify_admin_quiz();

update public.quizzes quizzes
set verified_at = coalesce(quizzes.verified_at, timezone('utc', now())),
    verified_by = coalesce(quizzes.verified_by, quizzes.teacher_id)
where exists (
    select 1 from public.profiles
    where profiles.id = quizzes.teacher_id and profiles.role = 'admin'
);

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

-- Older drafts created personal quiz copies before assignment. Attribute those
-- existing class sessions to the original shared quiz without changing their
-- frozen session questions.
update public.quiz_sessions assignments
set source_quiz_id = copies.source_quiz_id
from public.quizzes copies
where assignments.source_quiz_id = copies.id
  and assignments.class_id is not null
  and copies.source_quiz_id is not null;

update public.quizzes originals
set usage_count = (
    select count(*)::integer
    from public.quiz_sessions assignments
    where assignments.source_quiz_id = originals.id
      and assignments.class_id is not null
      and assignments.teacher_id <> originals.teacher_id
);

drop trigger if exists quizzes_refresh_source_usage on public.quizzes;
drop trigger if exists quiz_sessions_refresh_source_usage on public.quiz_sessions;
create trigger quiz_sessions_refresh_source_usage
after insert or update of source_quiz_id, class_id, teacher_id or delete on public.quiz_sessions
for each row execute function public.refresh_source_quiz_usage();

create table if not exists public.audit_logs (
    id bigint generated always as identity primary key,
    actor_id uuid references public.profiles(id) on delete set null,
    actor_role text,
    action text not null,
    target_type text not null,
    target_id text,
    metadata jsonb not null default '{}'::jsonb,
    created_at timestamp with time zone not null default timezone('utc', now())
);

-- Restore an exact snapshot atomically. The chosen snapshot becomes current,
-- while every later snapshot is permanently removed.
create or replace function public.restore_quiz_version(
    p_quiz_id uuid,
    p_version integer,
    p_actor_id uuid
)
returns table (quiz_id uuid, restored_version integer)
language plpgsql
security definer
set search_path = public
as $$
declare
    current_quiz public.quizzes%rowtype;
    target_version public.quiz_versions%rowtype;
    actor_role text;
    owner_role text;
begin
    select * into current_quiz
    from public.quizzes
    where id = p_quiz_id
    for update;

    select role into actor_role from public.profiles where id = p_actor_id;
    if current_quiz.id is null
       or actor_role is null
       or (
            current_quiz.teacher_id is distinct from p_actor_id
            and not (actor_role = 'admin' and current_quiz.visibility = 'shared')
       ) then
        raise exception 'Quiz restore is not permitted';
    end if;

    select * into target_version
    from public.quiz_versions
    where quiz_versions.quiz_id = p_quiz_id
      and quiz_versions.version = p_version
    for update;
    if target_version.id is null then
        raise exception 'Quiz version not found';
    end if;

    select role into owner_role
    from public.profiles
    where id = current_quiz.teacher_id;

    update public.quizzes
    set topic = target_version.topic,
        grade_level = target_version.grade_level,
        visibility = case
            when current_quiz.teacher_id is distinct from p_actor_id
                then current_quiz.visibility
            else target_version.visibility
        end,
        version = p_version,
        verified_at = case
            when owner_role = 'admin' then timezone('utc', now())
            else null
        end,
        verified_by = case
            when owner_role = 'admin' then p_actor_id
            else null
        end,
        updated_at = timezone('utc', now())
    where id = p_quiz_id;

    delete from public.quiz_questions where quiz_questions.quiz_id = p_quiz_id;
    insert into public.quiz_questions (
        quiz_id, position, grade, type, question,
        choice1, choice2, choice3, choice4, choice5, choice6,
        correct_answer
    )
    select
        p_quiz_id,
        coalesce(nullif(item.question_data ->> 'position', '')::integer, item.ordinality::integer),
        target_version.grade_level,
        coalesce(nullif(item.question_data ->> 'type', ''), 'multiple_choice'),
        item.question_data ->> 'question',
        item.question_data ->> 'choice1',
        item.question_data ->> 'choice2',
        item.question_data ->> 'choice3',
        item.question_data ->> 'choice4',
        item.question_data ->> 'choice5',
        item.question_data ->> 'choice6',
        coalesce(item.question_data ->> 'correct_answer', '0')
    from jsonb_array_elements(target_version.questions)
        with ordinality as item(question_data, ordinality)
    order by item.ordinality;

    delete from public.quiz_versions
    where quiz_versions.quiz_id = p_quiz_id
      and quiz_versions.version > p_version;

    return query select p_quiz_id, p_version;
end;
$$;

revoke all on function public.restore_quiz_version(uuid, integer, uuid)
from public, anon, authenticated;
grant execute on function public.restore_quiz_version(uuid, integer, uuid)
to service_role;

-- Stable application wrapper: returns the actual restore error instead of
-- forcing every caller to display one generic failure message. The nested
-- exception block rolls back any partial work performed by the restore call.
create or replace function public.restore_quiz_version_v2(
    p_quiz_id uuid,
    p_version integer,
    p_actor_id uuid
)
returns table (
    restore_success boolean,
    restored_quiz_id uuid,
    restored_version integer,
    error_message text
)
language plpgsql
security definer
set search_path = public
as $$
declare
    restored record;
begin
    begin
        select response.quiz_id, response.restored_version
        into restored
        from public.restore_quiz_version(p_quiz_id, p_version, p_actor_id) response;

        if restored.quiz_id is null then
            raise exception 'The restore function returned no quiz';
        end if;

        return query
        select true, restored.quiz_id, restored.restored_version, null::text;
    exception when others then
        return query
        select false, null::uuid, null::integer, sqlerrm;
    end;
end;
$$;

revoke all on function public.restore_quiz_version_v2(uuid, integer, uuid)
from public, anon, authenticated;
grant execute on function public.restore_quiz_version_v2(uuid, integer, uuid)
to service_role;

-- Advance due and scheduled assignments during normal application traffic.
-- The result guard independently promotes a scheduled quiz before accepting a
-- score, so a due/start boundary is also enforced inside the database.
create or replace function public.advance_quiz_session_schedule(
    p_session_id uuid default null
)
returns table (changed_session_id uuid, transition_name text)
language plpgsql
security definer
set search_path = public
as $$
declare
    changed record;
begin
    for changed in
        update public.quiz_sessions
        set status = 'completed',
            is_active = false,
            retake_mode = false,
            ended_at = coalesce(ended_at, due_at, timezone('utc', now()))
        where status in ('waiting', 'active')
          and due_at is not null
          and due_at <= timezone('utc', now())
          and (p_session_id is null or id = p_session_id)
        returning id, class_id, topic, due_at
    loop
        insert into public.audit_logs (
            actor_role, action, target_type, target_id, metadata
        ) values (
            'system',
            'quiz.auto_ended',
            'quiz_session',
            changed.id::text,
            jsonb_build_object(
                'class_id', changed.class_id,
                'topic', changed.topic,
                'due_at', changed.due_at
            )
        );
        changed_session_id := changed.id;
        transition_name := 'ended';
        return next;
    end loop;

    for changed in
        update public.quiz_sessions
        set status = 'active',
            is_active = true,
            started_at = coalesce(started_at, available_at, timezone('utc', now()))
        where status = 'waiting'
          and available_at is not null
          and available_at <= timezone('utc', now())
          and (due_at is null or due_at > timezone('utc', now()))
          and (p_session_id is null or id = p_session_id)
        returning id, class_id, topic, available_at
    loop
        insert into public.audit_logs (
            actor_role, action, target_type, target_id, metadata
        ) values (
            'system',
            'quiz.auto_started',
            'quiz_session',
            changed.id::text,
            jsonb_build_object(
                'class_id', changed.class_id,
                'topic', changed.topic,
                'available_at', changed.available_at
            )
        );
        changed_session_id := changed.id;
        transition_name := 'started';
        return next;
    end loop;
end;
$$;

revoke all on function public.advance_quiz_session_schedule(uuid)
from public, anon, authenticated;
grant execute on function public.advance_quiz_session_schedule(uuid)
to service_role;

-- Query indexes for growing registries, reports, schedules, and moderation.
create index if not exists quiz_sessions_schedule_idx
    on public.quiz_sessions (status, available_at, due_at);
create index if not exists quiz_sessions_class_lifecycle_idx
    on public.quiz_sessions (class_id, assigned_at desc, ended_at desc);
create index if not exists quiz_session_students_student_idx
    on public.quiz_session_students (student_id, eligibility_status, session_id);
create index if not exists quiz_results_counted_session_idx
    on public.quiz_results (session_id, student_id, created_at) where is_counted;
create index if not exists quiz_reports_status_created_idx
    on public.quiz_reports (status, created_at desc);
create index if not exists quiz_bookmarks_user_created_idx
    on public.quiz_bookmarks (user_id, created_at desc);
create index if not exists quizzes_visibility_grade_idx
    on public.quizzes (visibility, grade_level, created_at desc);
create index if not exists quizzes_library_verified_idx
    on public.quizzes (visibility, verified_at desc nulls last, grade_level, created_at desc);
create index if not exists quizzes_library_priority_created_idx
    on public.quizzes (visibility, is_verified desc, created_at desc);
create index if not exists quiz_sessions_source_quiz_usage_idx
    on public.quiz_sessions (source_quiz_id) where class_id is not null;
create index if not exists profiles_role_grade_name_idx
    on public.profiles (role, grade_level, last_name, first_name);
create index if not exists profiles_email_search_idx
    on public.profiles using gin (email gin_trgm_ops);
create index if not exists profiles_first_name_search_idx
    on public.profiles using gin (first_name gin_trgm_ops);
create index if not exists profiles_last_name_search_idx
    on public.profiles using gin (last_name gin_trgm_ops);
create index if not exists class_members_class_student_idx
    on public.class_members (class_id, student_id);
create index if not exists audit_logs_created_idx
    on public.audit_logs (created_at desc);

-- -------------------------------------------------------------------------
-- RLS for new tables. The Laravel admin service key still bypasses RLS for
-- narrowly scoped moderation actions.
-- -------------------------------------------------------------------------

grant select, insert, update, delete on
    public.quiz_versions,
    public.quiz_bookmarks,
    public.quiz_ratings,
    public.quiz_reports,
    public.quiz_session_students
to authenticated, service_role;

grant select on public.audit_logs to authenticated, service_role;
grant insert on public.audit_logs to service_role;
grant usage, select on sequence public.audit_logs_id_seq to service_role;
revoke update, delete, truncate on public.audit_logs from anon, authenticated, service_role;

alter table public.quiz_versions enable row level security;
alter table public.quiz_bookmarks enable row level security;
alter table public.quiz_ratings enable row level security;
alter table public.quiz_reports enable row level security;
alter table public.quiz_session_students enable row level security;
alter table public.audit_logs enable row level security;

drop policy if exists quizzes_teacher_read on public.quizzes;
create policy quizzes_teacher_read
on public.quizzes for select to authenticated
using (
    teacher_id = auth.uid()
    or (
        visibility = 'shared'
        and exists (
            select 1 from public.profiles
            where profiles.id = auth.uid()
              and profiles.role in ('teacher', 'admin')
        )
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
          and (
              quizzes.teacher_id = auth.uid()
              or (
                  quizzes.visibility = 'shared'
                  and profiles.role in ('teacher', 'admin')
              )
          )
    )
);

drop policy if exists quiz_versions_owner_read on public.quiz_versions;
create policy quiz_versions_owner_read
on public.quiz_versions for select to authenticated
using (exists (
    select 1 from public.quizzes
    where quizzes.id = quiz_versions.quiz_id
      and quizzes.teacher_id = auth.uid()
));

drop policy if exists quiz_versions_owner_insert on public.quiz_versions;
create policy quiz_versions_owner_insert
on public.quiz_versions for insert to authenticated
with check (exists (
    select 1 from public.quizzes
    where quizzes.id = quiz_versions.quiz_id
      and quizzes.teacher_id = auth.uid()
));

drop policy if exists quiz_bookmarks_own_all on public.quiz_bookmarks;
create policy quiz_bookmarks_own_all
on public.quiz_bookmarks for all to authenticated
using (user_id = auth.uid())
with check (
    user_id = auth.uid()
    and exists (
        select 1 from public.quizzes
        where quizzes.id = quiz_bookmarks.quiz_id
          and quizzes.visibility = 'shared'
          and quizzes.teacher_id is distinct from auth.uid()
    )
);

drop policy if exists quiz_ratings_own_all on public.quiz_ratings;
create policy quiz_ratings_own_all
on public.quiz_ratings for all to authenticated
using (user_id = auth.uid())
with check (
    user_id = auth.uid()
    and exists (
        select 1 from public.quizzes
        where quizzes.id = quiz_ratings.quiz_id
          and quizzes.visibility = 'shared'
          and quizzes.teacher_id is distinct from auth.uid()
    )
);

drop policy if exists quiz_reports_own_read on public.quiz_reports;
create policy quiz_reports_own_read
on public.quiz_reports for select to authenticated
using (reporter_id = auth.uid());

drop policy if exists quiz_reports_own_insert on public.quiz_reports;
create policy quiz_reports_own_insert
on public.quiz_reports for insert to authenticated
with check (
    reporter_id = auth.uid()
    and exists (
        select 1 from public.quizzes
        where quizzes.id = quiz_reports.quiz_id
          and quizzes.visibility = 'shared'
          and quizzes.teacher_id is distinct from auth.uid()
    )
);

drop policy if exists quiz_session_students_self_read on public.quiz_session_students;
create policy quiz_session_students_self_read
on public.quiz_session_students for select to authenticated
using (student_id = auth.uid());

drop policy if exists quiz_session_students_teacher_all on public.quiz_session_students;
create policy quiz_session_students_teacher_all
on public.quiz_session_students for all to authenticated
using (exists (
    select 1 from public.quiz_sessions
    where quiz_sessions.id = quiz_session_students.session_id
      and quiz_sessions.teacher_id = auth.uid()
))
with check (exists (
    select 1 from public.quiz_sessions
    where quiz_sessions.id = quiz_session_students.session_id
      and quiz_sessions.teacher_id = auth.uid()
));

drop policy if exists audit_logs_admin_read on public.audit_logs;
create policy audit_logs_admin_read
on public.audit_logs for select to authenticated
using (exists (
    select 1 from public.profiles
    where profiles.id = auth.uid() and profiles.role = 'admin'
));

-- Recovery tables contain old scores and answer keys. Keep them outside normal
-- client visibility while they are retained for rollback verification. These
-- archives are optional because an administrator may already have removed them
-- after verifying an earlier migration or rollback.
do $$
begin
    if to_regclass('public.rollback_quiz_session_state_20260827') is not null then
        execute 'revoke all on table public.rollback_quiz_session_state_20260827 from anon, authenticated';
    end if;

    if to_regclass('public.rollback_duplicate_quiz_results_20260828') is not null then
        execute 'revoke all on table public.rollback_duplicate_quiz_results_20260828 from anon, authenticated';
    end if;
end
$$;

commit;
