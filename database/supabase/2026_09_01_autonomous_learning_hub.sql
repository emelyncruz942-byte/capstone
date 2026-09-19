-- Autonomous Learning Hub / Practice Arena
-- Run after the 2026-08-31 notification migrations.

create extension if not exists pgcrypto;

alter table public.profiles
    add column if not exists xp integer not null default 0,
    add column if not exists points integer not null default 0,
    add column if not exists level integer not null default 1,
    add column if not exists trophies integer not null default 0;

create table if not exists public.practice_sessions (
    id uuid primary key default gen_random_uuid(),
    student_id uuid not null references public.profiles(id) on delete cascade,
    grade_level integer not null check (grade_level between 1 and 6),
    mode text not null default 'adventure'
        check (mode in ('adventure', 'daily', 'review')),
    status text not null default 'active'
        check (status in ('active', 'completed')),
    questions_answered integer not null default 0 check (questions_answered >= 0),
    correct_answers integer not null default 0 check (correct_answers >= 0),
    xp_earned integer not null default 0 check (xp_earned >= 0),
    current_combo integer not null default 0 check (current_combo >= 0),
    max_combo integer not null default 0 check (max_combo >= 0),
    started_at timestamptz not null default now(),
    last_activity_at timestamptz not null default now(),
    completed_at timestamptz,
    constraint practice_sessions_correct_count
        check (correct_answers <= questions_answered)
);

create unique index if not exists practice_sessions_one_active_mode_grade_idx
    on public.practice_sessions(student_id, mode, grade_level)
    where status = 'active';

create index if not exists practice_sessions_student_activity_idx
    on public.practice_sessions(student_id, last_activity_at desc);

create table if not exists public.practice_mastery (
    id uuid primary key default gen_random_uuid(),
    student_id uuid not null references public.profiles(id) on delete cascade,
    grade_level integer not null check (grade_level between 1 and 6),
    competency_key text not null check (char_length(competency_key) between 1 and 80),
    mastery_score integer not null default 0 check (mastery_score between 0 and 100),
    difficulty integer not null default 1 check (difficulty between 1 and 5),
    attempts integer not null default 0 check (attempts >= 0),
    correct_answers integer not null default 0 check (correct_answers >= 0),
    hints_used integer not null default 0 check (hints_used >= 0),
    correct_streak integer not null default 0 check (correct_streak >= 0),
    incorrect_streak integer not null default 0 check (incorrect_streak >= 0),
    last_practiced_at timestamptz,
    next_review_at timestamptz,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    unique (student_id, grade_level, competency_key),
    constraint practice_mastery_correct_count
        check (correct_answers <= attempts)
);

create index if not exists practice_mastery_student_score_idx
    on public.practice_mastery(student_id, grade_level, mastery_score, next_review_at);

create table if not exists public.practice_questions (
    id uuid primary key default gen_random_uuid(),
    session_id uuid not null references public.practice_sessions(id) on delete cascade,
    student_id uuid not null references public.profiles(id) on delete cascade,
    competency_key text not null check (char_length(competency_key) between 1 and 80),
    difficulty integer not null check (difficulty between 1 and 5),
    sequence integer not null check (sequence > 0),
    prompt text not null check (char_length(prompt) between 1 and 1200),
    answer_type text not null check (answer_type in ('number', 'choice', 'text')),
    options jsonb not null default '[]'::jsonb
        check (jsonb_typeof(options) = 'array'),
    correct_answer text not null check (char_length(correct_answer) between 1 and 120),
    hint_steps jsonb not null default '[]'::jsonb
        check (jsonb_typeof(hint_steps) = 'array'),
    explanation text not null check (char_length(explanation) between 1 and 2000),
    hints_revealed integer not null default 0 check (hints_revealed >= 0),
    submitted_answer text,
    is_correct boolean,
    xp_awarded integer not null default 0 check (xp_awarded >= 0),
    mastery_after integer check (mastery_after between 0 and 100),
    difficulty_after integer check (difficulty_after between 1 and 5),
    combo_after integer check (combo_after >= 0),
    trophy_awarded boolean not null default false,
    response_ms integer check (response_ms is null or response_ms between 0 and 3600000),
    created_at timestamptz not null default now(),
    answered_at timestamptz,
    unique (session_id, sequence)
);

create unique index if not exists practice_questions_one_open_per_session_idx
    on public.practice_questions(session_id)
    where answered_at is null;

create index if not exists practice_questions_student_history_idx
    on public.practice_questions(student_id, answered_at desc);

create index if not exists practice_questions_competency_idx
    on public.practice_questions(student_id, competency_key, answered_at desc);

alter table public.practice_sessions enable row level security;
alter table public.practice_mastery enable row level security;
alter table public.practice_questions enable row level security;

-- Practice answers contain protected solutions. Browser clients never receive
-- direct table access; Laravel exposes only the safe fields for the signed-in
-- student and uses the service role for the transactional functions below.
revoke all on public.practice_sessions from anon, authenticated;
revoke all on public.practice_mastery from anon, authenticated;
revoke all on public.practice_questions from anon, authenticated;

grant all on public.practice_sessions to service_role;
grant all on public.practice_mastery to service_role;
grant all on public.practice_questions to service_role;

create or replace function public.reveal_practice_hint(
    p_question_id uuid,
    p_student_id uuid
)
returns jsonb
language plpgsql
security definer
set search_path = public
as $$
declare
    question_row public.practice_questions%rowtype;
    hint_count integer;
    revealed_count integer;
begin
    select *
      into question_row
      from public.practice_questions
     where id = p_question_id
       and student_id = p_student_id
     for update;

    if not found then
        raise exception 'Practice question not found';
    end if;

    if question_row.answered_at is not null then
        raise exception 'This practice question has already been answered';
    end if;

    hint_count := jsonb_array_length(question_row.hint_steps);
    if hint_count = 0 then
        return jsonb_build_object(
            'hint', null,
            'hints_used', 0,
            'has_more', false
        );
    end if;

    revealed_count := least(question_row.hints_revealed + 1, hint_count);

    update public.practice_questions
       set hints_revealed = revealed_count
     where id = question_row.id;

    return jsonb_build_object(
        'hint', question_row.hint_steps ->> (revealed_count - 1),
        'hints_used', revealed_count,
        'has_more', revealed_count < hint_count
    );
end;
$$;

create or replace function public.submit_practice_answer(
    p_question_id uuid,
    p_student_id uuid,
    p_answer text,
    p_response_ms integer default null,
    p_day_started_at timestamptz default null
)
returns jsonb
language plpgsql
security definer
set search_path = public
as $$
declare
    question_row public.practice_questions%rowtype;
    session_row public.practice_sessions%rowtype;
    mastery_row public.practice_mastery%rowtype;
    answer_is_correct boolean := false;
    new_mastery integer;
    new_difficulty integer;
    new_correct_streak integer;
    new_incorrect_streak integer;
    new_combo integer;
    xp_gain integer;
    trophy_gain boolean;
    answered_total integer;
    correct_total integer;
    mission_correct integer;
    daily_total integer;
    review_interval interval;
begin
    if p_answer is null or btrim(p_answer) = '' then
        raise exception 'An answer is required';
    end if;

    select *
      into question_row
      from public.practice_questions
     where id = p_question_id
       and student_id = p_student_id
     for update;

    if not found then
        raise exception 'Practice question not found';
    end if;

    select *
      into session_row
      from public.practice_sessions
     where id = question_row.session_id
       and student_id = p_student_id
     for update;

    if not found or session_row.status <> 'active' then
        raise exception 'Practice session is unavailable';
    end if;

    if question_row.answered_at is not null then
        select count(*) filter (where recent.is_correct)
          into mission_correct
          from (
              select is_correct
                from public.practice_questions
               where session_id = session_row.id
                 and answered_at is not null
               order by answered_at desc
               limit 5
          ) as recent;

        select count(*)
          into daily_total
          from public.practice_questions
         where student_id = p_student_id
           and answered_at >= coalesce(p_day_started_at, date_trunc('day', now()));

        return jsonb_build_object(
            'already_answered', true,
            'correct', question_row.is_correct,
            'correct_answer', question_row.correct_answer,
            'explanation', question_row.explanation,
            'xp_awarded', question_row.xp_awarded,
            'mastery', question_row.mastery_after,
            'difficulty', question_row.difficulty_after,
            'combo', question_row.combo_after,
            'trophy_awarded', question_row.trophy_awarded,
            'mission_complete', mod(session_row.questions_answered, 5) = 0,
            'mission_correct', mission_correct,
            'questions_answered', session_row.questions_answered,
            'correct_answers', session_row.correct_answers,
            'session_xp', session_row.xp_earned,
            'daily_answered', daily_total,
            'daily_goal', 10
        );
    end if;

    if question_row.answer_type = 'number' then
        begin
            answer_is_correct := abs(
                replace(btrim(p_answer), ',', '')::numeric
                - replace(btrim(question_row.correct_answer), ',', '')::numeric
            ) < 0.000001;
        exception when invalid_text_representation then
            answer_is_correct := false;
        end;
    else
        answer_is_correct := lower(btrim(p_answer)) = lower(btrim(question_row.correct_answer));
    end if;

    insert into public.practice_mastery (
        student_id,
        grade_level,
        competency_key
    ) values (
        p_student_id,
        session_row.grade_level,
        question_row.competency_key
    )
    on conflict (student_id, grade_level, competency_key) do nothing;

    select *
      into mastery_row
      from public.practice_mastery
     where student_id = p_student_id
       and grade_level = session_row.grade_level
       and competency_key = question_row.competency_key
     for update;

    if answer_is_correct then
        new_correct_streak := mastery_row.correct_streak + 1;
        new_incorrect_streak := 0;
        new_mastery := least(
            100,
            mastery_row.mastery_score
                + greatest(3, 8 - least(question_row.hints_revealed * 2, 6))
                + greatest(0, question_row.difficulty - 1)
        );
        new_difficulty := mastery_row.difficulty;
        if new_correct_streak >= 3 then
            new_difficulty := least(5, mastery_row.difficulty + 1);
            new_correct_streak := 0;
        end if;
    else
        new_correct_streak := 0;
        new_incorrect_streak := mastery_row.incorrect_streak + 1;
        new_mastery := greatest(0, mastery_row.mastery_score - 3);
        new_difficulty := mastery_row.difficulty;
        if new_incorrect_streak >= 2 then
            new_difficulty := greatest(1, mastery_row.difficulty - 1);
            new_incorrect_streak := 0;
        end if;
    end if;

    review_interval := case
        when new_mastery >= 90 then interval '7 days'
        when new_mastery >= 70 then interval '3 days'
        when new_mastery >= 40 then interval '1 day'
        else interval '12 hours'
    end;

    new_combo := case
        when answer_is_correct then session_row.current_combo + 1
        else 0
    end;

    xp_gain := case
        when answer_is_correct then greatest(
            8,
            10 + (question_row.difficulty * 2)
                + (least(new_combo, 5) * 2)
                - least(question_row.hints_revealed * 2, 6)
        )
        else 2
    end;

    answered_total := session_row.questions_answered + 1;
    correct_total := session_row.correct_answers + case when answer_is_correct then 1 else 0 end;
    trophy_gain := answer_is_correct and mod(correct_total, 10) = 0;

    update public.practice_mastery
       set mastery_score = new_mastery,
           difficulty = new_difficulty,
           attempts = attempts + 1,
           correct_answers = correct_answers + case when answer_is_correct then 1 else 0 end,
           hints_used = hints_used + question_row.hints_revealed,
           correct_streak = new_correct_streak,
           incorrect_streak = new_incorrect_streak,
           last_practiced_at = now(),
           next_review_at = now() + review_interval,
           updated_at = now()
     where id = mastery_row.id;

    update public.practice_sessions
       set questions_answered = answered_total,
           correct_answers = correct_total,
           xp_earned = xp_earned + xp_gain,
           current_combo = new_combo,
           max_combo = greatest(max_combo, new_combo),
           last_activity_at = now()
     where id = session_row.id;

    update public.profiles
       set xp = coalesce(xp, 0) + xp_gain,
           points = coalesce(points, 0) + xp_gain,
           trophies = coalesce(trophies, 0) + case when trophy_gain then 1 else 0 end,
           level = greatest(
               coalesce(level, 1),
               floor((coalesce(xp, 0) + xp_gain) / 250.0)::integer + 1
           )
     where id = p_student_id;

    update public.practice_questions
       set submitted_answer = left(btrim(p_answer), 120),
           is_correct = answer_is_correct,
           xp_awarded = xp_gain,
           mastery_after = new_mastery,
           difficulty_after = new_difficulty,
           combo_after = new_combo,
           trophy_awarded = trophy_gain,
           response_ms = case
               when p_response_ms between 0 and 3600000 then p_response_ms
               else null
           end,
           answered_at = now()
     where id = question_row.id;

    select count(*) filter (where recent.is_correct)
      into mission_correct
      from (
          select is_correct
            from public.practice_questions
           where session_id = session_row.id
             and answered_at is not null
           order by answered_at desc
           limit 5
      ) as recent;

    select count(*)
      into daily_total
      from public.practice_questions
     where student_id = p_student_id
       and answered_at >= coalesce(p_day_started_at, date_trunc('day', now()));

    return jsonb_build_object(
        'already_answered', false,
        'correct', answer_is_correct,
        'correct_answer', question_row.correct_answer,
        'explanation', question_row.explanation,
        'xp_awarded', xp_gain,
        'mastery', new_mastery,
        'difficulty', new_difficulty,
        'combo', new_combo,
        'trophy_awarded', trophy_gain,
        'mission_complete', mod(answered_total, 5) = 0,
        'mission_correct', mission_correct,
        'questions_answered', answered_total,
        'correct_answers', correct_total,
        'session_xp', session_row.xp_earned + xp_gain,
        'daily_answered', daily_total,
        'daily_goal', 10
    );
end;
$$;

revoke all on function public.reveal_practice_hint(uuid, uuid) from public, anon, authenticated;
revoke all on function public.submit_practice_answer(uuid, uuid, text, integer, timestamptz) from public, anon, authenticated;
grant execute on function public.reveal_practice_hint(uuid, uuid) to service_role;
grant execute on function public.submit_practice_answer(uuid, uuid, text, integer, timestamptz) to service_role;
