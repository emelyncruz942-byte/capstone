-- Atomic shared-quiz assignment and durable quiz-report moderation.
-- Run after 2026_08_30_assignment_usage_and_attempt_integrity.sql.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.quizzes') is null
       or to_regclass('public.quiz_questions') is null
       or to_regclass('public.quiz_sessions') is null
       or to_regclass('public.questions') is null
       or to_regclass('public.classes') is null
       or to_regclass('public.quiz_reports') is null then
        raise exception 'Required quiz tables are missing. Run the earlier MathVerse migrations first.';
    end if;
end
$$;

-- A teacher may tailor the grade of a shared quiz for a frozen assignment.
-- The assignment must match its selected classes; the function never updates
-- a class grade or the shared original. Track the frozen assignment grade
-- directly instead of comparing the class to the source quiz's original grade.
alter table public.quiz_sessions
    add column if not exists grade_level integer;

update public.quiz_sessions assignments
set grade_level = coalesce(
    (
        select classes.grade_level
        from public.classes classes
        where classes.id = assignments.class_id
    ),
    (
        select quizzes.grade_level
        from public.quizzes quizzes
        where quizzes.id = assignments.source_quiz_id
    ),
    (
        select min(questions.grade)
        from public.questions questions
        where questions.session_id = assignments.id
          and questions.grade between 1 and 6
    ),
    1
)
where assignments.grade_level is null
   or assignments.grade_level not between 1 and 6;

alter table public.quiz_sessions
    alter column grade_level set not null;

do $$
begin
    if not exists (
        select 1
        from pg_constraint
        where conname = 'quiz_sessions_grade_level_check'
          and conrelid = 'public.quiz_sessions'::regclass
    ) then
        alter table public.quiz_sessions
            add constraint quiz_sessions_grade_level_check
            check (grade_level between 1 and 6);
    end if;
end
$$;

create or replace function public.enforce_quiz_assignment_grade()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    assignment_grade integer;
    source_grade integer;
    target_grade integer;
    target_archived_at timestamp with time zone;
begin
    assignment_grade := new.grade_level;

    if assignment_grade is null and new.source_quiz_id is not null then
        select quizzes.grade_level into source_grade
        from public.quizzes quizzes
        where quizzes.id = new.source_quiz_id;
        assignment_grade := source_grade;
        new.grade_level := source_grade;
    end if;

    if new.class_id is null then
        return new;
    end if;

    select classes.grade_level, classes.archived_at
    into target_grade, target_archived_at
    from public.classes classes
    where classes.id = new.class_id;

    if target_archived_at is not null then
        raise exception 'Quizzes cannot be assigned to an archived class';
    end if;

    if assignment_grade is null
       or target_grade is null
       or assignment_grade <> target_grade then
        raise exception 'Quiz grade (%) does not match class grade (%)',
            assignment_grade, target_grade;
    end if;

    return new;
end;
$$;

drop trigger if exists quiz_sessions_grade_guard on public.quiz_sessions;
create trigger quiz_sessions_grade_guard
before insert or update of class_id, source_quiz_id, grade_level
on public.quiz_sessions
for each row execute function public.enforce_quiz_assignment_grade();

-- Keep enough context for reviewed/dismissed reports even after a quiz is
-- edited or deleted.
alter table public.quiz_reports
    add column if not exists quiz_topic text,
    add column if not exists quiz_grade_level integer,
    add column if not exists quiz_creator_id uuid,
    add column if not exists question_text text;

update public.quiz_reports reports
set quiz_topic = coalesce(reports.quiz_topic, quizzes.topic),
    quiz_grade_level = coalesce(reports.quiz_grade_level, quizzes.grade_level),
    quiz_creator_id = coalesce(reports.quiz_creator_id, quizzes.teacher_id)
from public.quizzes quizzes
where quizzes.id = reports.quiz_id;

update public.quiz_reports reports
set question_text = coalesce(reports.question_text, questions.question)
from public.quiz_questions questions
where questions.id = reports.question_id;

alter table public.quiz_reports
    alter column quiz_id drop not null,
    drop constraint if exists quiz_reports_quiz_id_fkey;

alter table public.quiz_reports
    add constraint quiz_reports_quiz_id_fkey
    foreign key (quiz_id) references public.quizzes(id) on delete set null;

do $$
begin
    if not exists (
        select 1
        from pg_constraint
        where conname = 'quiz_reports_quiz_creator_id_fkey'
          and conrelid = 'public.quiz_reports'::regclass
    ) then
        alter table public.quiz_reports
            add constraint quiz_reports_quiz_creator_id_fkey
            foreign key (quiz_creator_id) references public.profiles(id)
            on delete set null;
    end if;
end
$$;

create or replace function public.snapshot_quiz_report_context()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    source_quiz record;
    source_question text;
begin
    if new.quiz_id is not null then
        select quizzes.topic, quizzes.grade_level, quizzes.teacher_id
        into source_quiz
        from public.quizzes quizzes
        where quizzes.id = new.quiz_id;

        new.quiz_topic := coalesce(new.quiz_topic, source_quiz.topic);
        new.quiz_grade_level := coalesce(
            new.quiz_grade_level,
            source_quiz.grade_level
        );
        new.quiz_creator_id := coalesce(
            new.quiz_creator_id,
            source_quiz.teacher_id
        );
    end if;

    if new.question_id is not null then
        select quiz_questions.question into source_question
        from public.quiz_questions quiz_questions
        where quiz_questions.id = new.question_id;
        new.question_text := coalesce(new.question_text, source_question);
    end if;

    return new;
end;
$$;

drop trigger if exists quiz_reports_snapshot_context on public.quiz_reports;
create trigger quiz_reports_snapshot_context
before insert or update of quiz_id, question_id on public.quiz_reports
for each row execute function public.snapshot_quiz_report_context();

create index if not exists quiz_reports_status_reviewed_idx
    on public.quiz_reports (status, reviewed_at desc, created_at desc);

-- Create every selected class assignment and its frozen questions in one
-- database transaction. Any failure rolls the entire operation back.
create or replace function public.assign_shared_quiz_to_classes(
    p_teacher_id uuid,
    p_source_quiz_id uuid,
    p_class_ids uuid[],
    p_topic text,
    p_grade_level integer,
    p_time_limit integer,
    p_available_at timestamp with time zone,
    p_due_at timestamp with time zone,
    p_questions jsonb
)
returns table (session_id uuid, class_id uuid)
language plpgsql
security definer
set search_path = public
as $$
declare
    selected_count integer;
    matching_count integer;
    target_class_id uuid;
    conflicting_class text;
    generated_room_code text;
    room_attempt integer;
    new_session_id uuid;
    starts_now boolean;
    question_record record;
    question_data jsonb;
begin
    if p_teacher_id is null or p_source_quiz_id is null then
        raise exception 'The teacher and shared quiz are required';
    end if;

    if not exists (
        select 1
        from public.quizzes quizzes
        where quizzes.id = p_source_quiz_id
          and quizzes.visibility = 'shared'
          and quizzes.teacher_id <> p_teacher_id
    ) then
        raise exception 'The shared quiz is no longer available';
    end if;

    selected_count := coalesce(cardinality(p_class_ids), 0);
    if selected_count < 1 or selected_count > 100 then
        raise exception 'Select between 1 and 100 classes';
    end if;

    if p_grade_level not between 1 and 6 then
        raise exception 'The assignment grade must be between 1 and 6';
    end if;
    if p_time_limit not between 5 and 300 then
        raise exception 'The time limit must be between 5 and 300 seconds';
    end if;
    if nullif(btrim(p_topic), '') is null then
        raise exception 'The assignment topic is required';
    end if;
    if p_due_at is not null
       and p_due_at <= coalesce(p_available_at, timezone('utc', now())) then
        raise exception 'The due date must be later than the start date';
    end if;
    if p_due_at is not null and p_due_at <= timezone('utc', now()) then
        raise exception 'The due date must be in the future';
    end if;
    if p_questions is null
       or jsonb_typeof(p_questions) <> 'array'
       or jsonb_array_length(p_questions) < 1 then
        raise exception 'At least one quiz question is required';
    end if;

    -- Lock the selected class records while assignments are created. The
    -- function reads and validates these rows but never updates their grades.
    perform classes.id
    from public.classes classes
    where classes.id = any(p_class_ids)
      and classes.teacher_id = p_teacher_id
    for update;

    select count(distinct classes.id)::integer
    into matching_count
    from public.classes classes
    where classes.id = any(p_class_ids)
      and classes.teacher_id = p_teacher_id
      and classes.archived_at is null
      and classes.grade_level = p_grade_level;

    if matching_count <> selected_count then
        raise exception 'One or more selected classes are unavailable or have a different grade level';
    end if;

    select classes.class_name into conflicting_class
    from public.quiz_sessions assignments
    join public.classes classes on classes.id = assignments.class_id
    where assignments.class_id = any(p_class_ids)
      and assignments.source_quiz_id = p_source_quiz_id
      and assignments.status in ('waiting', 'active')
    order by classes.class_name
    limit 1;

    if conflicting_class is not null then
        raise exception 'This shared quiz is already assigned or active in %',
            conflicting_class;
    end if;

    starts_now := p_available_at is not null
        and p_available_at <= timezone('utc', now());

    foreach target_class_id in array p_class_ids loop
        room_attempt := 0;
        loop
            room_attempt := room_attempt + 1;
            generated_room_code := lpad(
                floor(random() * 10000)::integer::text,
                4,
                '0'
            );
            exit when not exists (
                select 1
                from public.quiz_sessions open_sessions
                where open_sessions.room_code = generated_room_code
                  and open_sessions.is_active
            );
            if room_attempt >= 100 then
                raise exception 'An available VR quiz code could not be generated';
            end if;
        end loop;

        insert into public.quiz_sessions (
            teacher_id,
            source_quiz_id,
            topic,
            room_code,
            class_id,
            grade_level,
            max_members,
            time_limit,
            assigned_at,
            available_at,
            due_at,
            started_at,
            is_active,
            status
        ) values (
            p_teacher_id,
            p_source_quiz_id,
            btrim(p_topic),
            generated_room_code,
            target_class_id,
            p_grade_level,
            60,
            p_time_limit,
            timezone('utc', now()),
            p_available_at,
            p_due_at,
            case when starts_now then p_available_at else null end,
            true,
            case when starts_now then 'active' else 'waiting' end
        )
        returning id into new_session_id;

        for question_record in
            select item.value as data, item.ordinality
            from jsonb_array_elements(p_questions)
                with ordinality as item(value, ordinality)
            order by item.ordinality
        loop
            question_data := question_record.data;
            if nullif(btrim(question_data->>'question'), '') is null
               or nullif(btrim(question_data->>'choice1'), '') is null
               or nullif(btrim(question_data->>'choice2'), '') is null
               or nullif(btrim(question_data->>'choice3'), '') is null
               or nullif(btrim(question_data->>'choice4'), '') is null
               or coalesce(question_data->>'correct_answer', '')
                    not in ('0', '1', '2', '3') then
                raise exception 'Question % is incomplete', question_record.ordinality;
            end if;

            insert into public.questions (
                session_id,
                grade,
                type,
                question,
                choice1,
                choice2,
                choice3,
                choice4,
                choice5,
                choice6,
                correct_answer
            ) values (
                new_session_id,
                p_grade_level,
                coalesce(nullif(question_data->>'type', ''), 'multiple_choice'),
                btrim(question_data->>'question'),
                btrim(question_data->>'choice1'),
                btrim(question_data->>'choice2'),
                btrim(question_data->>'choice3'),
                btrim(question_data->>'choice4'),
                coalesce(question_data->>'choice5', ''),
                coalesce(question_data->>'choice6', ''),
                question_data->>'correct_answer'
            );
        end loop;

        session_id := new_session_id;
        class_id := target_class_id;
        return next;
    end loop;

    update public.quizzes source_quiz
    set usage_count = (
        select count(*)::integer
        from public.quiz_sessions assignments
        where assignments.source_quiz_id = p_source_quiz_id
          and assignments.class_id is not null
          and assignments.teacher_id <> source_quiz.teacher_id
    )
    where source_quiz.id = p_source_quiz_id;
end;
$$;

revoke all on function public.assign_shared_quiz_to_classes(
    uuid, uuid, uuid[], text, integer, integer,
    timestamp with time zone, timestamp with time zone, jsonb
) from public, anon, authenticated;
grant execute on function public.assign_shared_quiz_to_classes(
    uuid, uuid, uuid[], text, integer, integer,
    timestamp with time zone, timestamp with time zone, jsonb
) to service_role;

notify pgrst, 'reload schema';

commit;
