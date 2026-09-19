-- Server-authoritative Number Guess game and leaderboard.
-- Run after 2026_09_05_security_hardening.sql.

begin;

create extension if not exists pgcrypto;

create table if not exists public.number_guess_sessions (
    id uuid primary key default gen_random_uuid(),
    student_id uuid not null references public.profiles(id) on delete cascade,
    status text not null default 'active'
        check (status in ('active', 'finished', 'expired', 'restarted')),
    score integer not null default 0 check (score >= 0),
    total_guesses integer not null default 0 check (total_guesses >= 0),
    range_max integer not null default 100
        check (range_max between 100 and 2000000000),
    target_number integer not null check (target_number >= 1),
    started_at timestamptz not null default now(),
    expires_at timestamptz not null default (now() + interval '60 seconds'),
    last_guess_at timestamptz,
    completed_at timestamptz,
    updated_at timestamptz not null default now(),
    constraint number_guess_target_in_range check (target_number <= range_max),
    constraint number_guess_completion_state check (
        (status = 'active' and completed_at is null)
        or (status <> 'active' and completed_at is not null)
    )
);

create unique index if not exists number_guess_one_active_game_idx
    on public.number_guess_sessions (student_id)
    where status = 'active';

create index if not exists number_guess_student_history_idx
    on public.number_guess_sessions (student_id, started_at desc);

create index if not exists number_guess_expiration_idx
    on public.number_guess_sessions (expires_at)
    where status = 'active';

create table if not exists public.number_guess_scores (
    student_id uuid primary key references public.profiles(id) on delete cascade,
    best_score integer not null default 0 check (best_score >= 0),
    best_guesses integer check (best_guesses is null or best_guesses > 0),
    games_played integer not null default 0 check (games_played >= 0),
    achieved_at timestamptz,
    updated_at timestamptz not null default now(),
    constraint number_guess_best_score_details check (
        (best_score = 0 and best_guesses is null and achieved_at is null)
        or (best_score > 0 and best_guesses is not null and achieved_at is not null)
    )
);

create index if not exists number_guess_leaderboard_idx
    on public.number_guess_scores (
        best_score desc,
        best_guesses asc,
        achieved_at asc,
        student_id asc
    )
    where best_score > 0;

alter table public.number_guess_sessions enable row level security;
alter table public.number_guess_scores enable row level security;

-- The target and authoritative timer must never be readable or writable from
-- a browser Supabase client. Laravel calls the functions below with its
-- private service-role credential and returns only allowlisted fields.
revoke all on public.number_guess_sessions from public, anon, authenticated;
revoke all on public.number_guess_scores from public, anon, authenticated;
grant all on public.number_guess_sessions to service_role;
grant all on public.number_guess_scores to service_role;

create or replace function public.number_guess_dashboard(
    p_student_id uuid,
    p_limit integer default 10
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    game_now timestamptz := clock_timestamp();
    session_row public.number_guess_sessions%rowtype;
    score_row public.number_guess_scores%rowtype;
    leaderboard_rows jsonb := '[]'::jsonb;
    personal_rank bigint;
    student_grade integer;
    safe_limit integer := greatest(3, least(coalesce(p_limit, 10), 50));
begin
    select grade_level
      into student_grade
      from public.profiles
     where id = p_student_id
       and role = 'student'
       and suspended_at is null;

    if not found or student_grade not between 1 and 6 then
        raise exception 'Student profile unavailable';
    end if;

    update public.number_guess_sessions
       set status = 'expired', completed_at = game_now, updated_at = game_now
     where student_id = p_student_id
       and status = 'active'
       and expires_at <= game_now;

    select *
      into session_row
      from public.number_guess_sessions
     where student_id = p_student_id
       and status = 'active'
     order by started_at desc
     limit 1;

    select *
      into score_row
      from public.number_guess_scores
     where student_id = p_student_id;

    with ranked as (
        select
            scores.student_id,
            scores.best_score,
            scores.best_guesses,
            scores.games_played,
            scores.achieved_at,
            profiles.grade_level,
            profiles.first_name,
            profiles.last_name,
            profiles.leaderboard_alias,
            profiles.show_on_leaderboard,
            row_number() over (
                order by scores.best_score desc,
                         scores.best_guesses asc,
                         scores.achieved_at asc,
                         scores.student_id asc
            ) as position
        from public.number_guess_scores as scores
        join public.profiles as profiles on profiles.id = scores.student_id
        where scores.best_score > 0
          and profiles.role = 'student'
          and profiles.grade_level = student_grade
          and profiles.suspended_at is null
    )
    select
        coalesce(
            jsonb_agg(
                jsonb_build_object(
                    'rank', ranked.position,
                    'student_id', ranked.student_id,
                    'display_name',
                        case
                            when coalesce(ranked.show_on_leaderboard, true) = false
                                 and ranked.student_id <> p_student_id
                                then 'Anonymous Student'
                            else coalesce(
                                nullif(btrim(ranked.leaderboard_alias), ''),
                                btrim(concat_ws(
                                    ' ',
                                    coalesce(nullif(btrim(ranked.first_name), ''), 'Student'),
                                    case
                                        when nullif(btrim(ranked.last_name), '') is null then null
                                        else left(btrim(ranked.last_name), 1) || '.'
                                    end
                                )),
                                'Student'
                            )
                        end,
                    'grade_level', ranked.grade_level,
                    'best_score', ranked.best_score,
                    'best_guesses', ranked.best_guesses,
                    'games_played', ranked.games_played,
                    'is_current', ranked.student_id = p_student_id
                )
                order by ranked.position
            ) filter (
                where ranked.position <= safe_limit
                   or ranked.student_id = p_student_id
            ),
            '[]'::jsonb
        ),
        max(ranked.position) filter (where ranked.student_id = p_student_id)
      into leaderboard_rows, personal_rank
      from ranked;

    return jsonb_build_object(
        'server_now', game_now,
        'grade_level', student_grade,
        'session', case
            when session_row.id is null then null
            else jsonb_build_object(
                'id', session_row.id,
                'status', session_row.status,
                'score', session_row.score,
                'guesses', session_row.total_guesses,
                'range_min', 1,
                'range_max', session_row.range_max,
                'started_at', session_row.started_at,
                'ends_at', session_row.expires_at,
                'remaining_ms', greatest(
                    0,
                    floor(extract(epoch from (session_row.expires_at - game_now)) * 1000)::bigint
                )
            )
        end,
        'personal', jsonb_build_object(
            'best_score', coalesce(score_row.best_score, 0),
            'best_guesses', score_row.best_guesses,
            'games_played', coalesce(score_row.games_played, 0),
            'rank', personal_rank
        ),
        'leaderboard', leaderboard_rows
    );
end;
$$;

create or replace function public.start_number_guess_game(p_student_id uuid)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    game_now timestamptz;
    session_row public.number_guess_sessions%rowtype;
    score_row public.number_guess_scores%rowtype;
begin
    -- Serialize starts across tabs so the partial unique index never turns a
    -- legitimate double click into a database error.
    perform pg_advisory_xact_lock(hashtextextended(p_student_id::text, 0));
    game_now := clock_timestamp();

    if not exists (
        select 1
          from public.profiles
         where id = p_student_id
           and role = 'student'
           and suspended_at is null
    ) then
        raise exception 'Student profile unavailable';
    end if;

    update public.number_guess_sessions
       set status = 'restarted', completed_at = game_now, updated_at = game_now
     where student_id = p_student_id
       and status = 'active';

    -- Scores and game counts live in number_guess_scores, so old secret-number
    -- rows do not need to accumulate indefinitely.
    delete from public.number_guess_sessions
     where student_id = p_student_id
       and status <> 'active';

    insert into public.number_guess_scores (student_id, games_played, updated_at)
    values (p_student_id, 1, game_now)
    on conflict (student_id) do update
       set games_played = public.number_guess_scores.games_played + 1,
           updated_at = excluded.updated_at;

    insert into public.number_guess_sessions (
        student_id,
        target_number,
        started_at,
        expires_at,
        updated_at
    ) values (
        p_student_id,
        floor(random() * 100)::integer + 1,
        game_now,
        game_now + interval '60 seconds',
        game_now
    )
    returning * into session_row;

    select * into score_row
      from public.number_guess_scores
     where student_id = p_student_id;

    return jsonb_build_object(
        'server_now', game_now,
        'session', jsonb_build_object(
            'id', session_row.id,
            'status', session_row.status,
            'score', session_row.score,
            'guesses', session_row.total_guesses,
            'range_min', 1,
            'range_max', session_row.range_max,
            'started_at', session_row.started_at,
            'ends_at', session_row.expires_at,
            'remaining_ms', 60000
        ),
        'personal', jsonb_build_object(
            'best_score', score_row.best_score,
            'best_guesses', score_row.best_guesses,
            'games_played', score_row.games_played,
            'new_best', false
        )
    );
end;
$$;

create or replace function public.submit_number_guess(
    p_session_id uuid,
    p_student_id uuid,
    p_guess integer
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    game_now timestamptz;
    session_row public.number_guess_sessions%rowtype;
    score_row public.number_guess_scores%rowtype;
    next_score integer;
    next_guesses integer;
    next_range integer;
    next_target integer;
    next_expiration timestamptz;
    direction text;
    achieved_new_best boolean := false;
begin
    select *
      into session_row
      from public.number_guess_sessions
     where id = p_session_id
       and student_id = p_student_id
     for update;

    if not found then
        raise exception 'Game session not found';
    end if;

    select *
      into score_row
      from public.number_guess_scores
     where student_id = p_student_id
     for update;

    -- A queued request must be judged against the clock after it acquires the
    -- session locks, not against the earlier transaction start time.
    game_now := clock_timestamp();

    if session_row.status <> 'active' then
        return jsonb_build_object(
            'server_now', game_now,
            'session', jsonb_build_object(
                'id', session_row.id,
                'status', session_row.status,
                'score', session_row.score,
                'guesses', session_row.total_guesses,
                'range_min', 1,
                'range_max', session_row.range_max,
                'ends_at', session_row.expires_at,
                'remaining_ms', 0
            ),
            'personal', jsonb_build_object(
                'best_score', coalesce(score_row.best_score, 0),
                'best_guesses', score_row.best_guesses,
                'games_played', coalesce(score_row.games_played, 0),
                'new_best', false
            ),
            'outcome', jsonb_build_object(
                'direction', session_row.status,
                'correct', false,
                'finished', true,
                'correct_number', session_row.target_number
            )
        );
    end if;

    if session_row.expires_at <= game_now then
        update public.number_guess_sessions
           set status = 'expired', completed_at = game_now, updated_at = game_now
         where id = session_row.id;

        return jsonb_build_object(
            'server_now', game_now,
            'session', jsonb_build_object(
                'id', session_row.id,
                'status', 'expired',
                'score', session_row.score,
                'guesses', session_row.total_guesses,
                'range_min', 1,
                'range_max', session_row.range_max,
                'ends_at', session_row.expires_at,
                'remaining_ms', 0
            ),
            'personal', jsonb_build_object(
                'best_score', coalesce(score_row.best_score, 0),
                'best_guesses', score_row.best_guesses,
                'games_played', coalesce(score_row.games_played, 0),
                'new_best', false
            ),
            'outcome', jsonb_build_object(
                'direction', 'expired',
                'correct', false,
                'finished', true,
                'correct_number', session_row.target_number
            )
        );
    end if;

    if p_guess is null or p_guess < 1 or p_guess > session_row.range_max then
        raise exception 'Enter a number from 1 to %', session_row.range_max;
    end if;

    next_guesses := session_row.total_guesses + 1;

    if p_guess = session_row.target_number then
        direction := 'correct';
        next_score := session_row.score + 1;
        next_range := least(2000000000, session_row.range_max + 50);
        next_target := floor(random() * next_range)::integer + 1;
        next_expiration := session_row.expires_at + interval '15 seconds';

        update public.number_guess_sessions
           set score = next_score,
               total_guesses = next_guesses,
               range_max = next_range,
               target_number = next_target,
               expires_at = next_expiration,
               last_guess_at = game_now,
               updated_at = game_now
         where id = session_row.id;

        if score_row.student_id is null then
            insert into public.number_guess_scores (
                student_id,
                best_score,
                best_guesses,
                games_played,
                achieved_at,
                updated_at
            ) values (
                p_student_id,
                next_score,
                next_guesses,
                1,
                game_now,
                game_now
            )
            returning * into score_row;
            achieved_new_best := true;
        elsif next_score > score_row.best_score
              or (
                  next_score = score_row.best_score
                  and (score_row.best_guesses is null or next_guesses < score_row.best_guesses)
              ) then
            update public.number_guess_scores
               set best_score = next_score,
                   best_guesses = next_guesses,
                   achieved_at = game_now,
                   updated_at = game_now
             where student_id = p_student_id
             returning * into score_row;
            achieved_new_best := true;
        end if;
    else
        direction := case
            when p_guess < session_row.target_number then 'low'
            else 'high'
        end;
        next_score := session_row.score;
        next_range := session_row.range_max;
        next_expiration := session_row.expires_at;

        update public.number_guess_sessions
           set total_guesses = next_guesses,
               last_guess_at = game_now,
               updated_at = game_now
         where id = session_row.id;
    end if;

    return jsonb_build_object(
        'server_now', game_now,
        'session', jsonb_build_object(
            'id', session_row.id,
            'status', 'active',
            'score', next_score,
            'guesses', next_guesses,
            'range_min', 1,
            'range_max', next_range,
            'started_at', session_row.started_at,
            'ends_at', next_expiration,
            'remaining_ms', greatest(
                0,
                floor(extract(epoch from (next_expiration - game_now)) * 1000)::bigint
            )
        ),
        'personal', jsonb_build_object(
            'best_score', coalesce(score_row.best_score, 0),
            'best_guesses', score_row.best_guesses,
            'games_played', coalesce(score_row.games_played, 0),
            'new_best', achieved_new_best
        ),
        'outcome', jsonb_build_object(
            'direction', direction,
            'correct', direction = 'correct',
            'finished', false
        )
    );
end;
$$;

create or replace function public.finish_number_guess_game(
    p_session_id uuid,
    p_student_id uuid
)
returns jsonb
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
    game_now timestamptz;
    session_row public.number_guess_sessions%rowtype;
    score_row public.number_guess_scores%rowtype;
    final_status text;
begin
    select *
      into session_row
      from public.number_guess_sessions
     where id = p_session_id
       and student_id = p_student_id
     for update;

    if not found then
        raise exception 'Game session not found';
    end if;

    game_now := clock_timestamp();

    final_status := session_row.status;
    if session_row.status = 'active' then
        final_status := case
            when session_row.expires_at <= game_now then 'expired'
            else 'finished'
        end;

        update public.number_guess_sessions
           set status = final_status, completed_at = game_now, updated_at = game_now
         where id = session_row.id;
    end if;

    select * into score_row
      from public.number_guess_scores
     where student_id = p_student_id;

    return jsonb_build_object(
        'server_now', game_now,
        'session', jsonb_build_object(
            'id', session_row.id,
            'status', final_status,
            'score', session_row.score,
            'guesses', session_row.total_guesses,
            'range_min', 1,
            'range_max', session_row.range_max,
            'started_at', session_row.started_at,
            'ends_at', session_row.expires_at,
            'remaining_ms', 0
        ),
        'personal', jsonb_build_object(
            'best_score', coalesce(score_row.best_score, 0),
            'best_guesses', score_row.best_guesses,
            'games_played', coalesce(score_row.games_played, 0),
            'new_best', false
        ),
        'outcome', jsonb_build_object(
            'direction', final_status,
            'correct', false,
            'finished', true,
            'correct_number', session_row.target_number
        )
    );
end;
$$;

revoke all on function public.number_guess_dashboard(uuid, integer) from public, anon, authenticated;
revoke all on function public.start_number_guess_game(uuid) from public, anon, authenticated;
revoke all on function public.submit_number_guess(uuid, uuid, integer) from public, anon, authenticated;
revoke all on function public.finish_number_guess_game(uuid, uuid) from public, anon, authenticated;

grant execute on function public.number_guess_dashboard(uuid, integer) to service_role;
grant execute on function public.start_number_guess_game(uuid) to service_role;
grant execute on function public.submit_number_guess(uuid, uuid, integer) to service_role;
grant execute on function public.finish_number_guess_game(uuid, uuid) to service_role;

commit;
