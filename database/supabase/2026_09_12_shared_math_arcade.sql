-- Four server-authoritative math games with grade-level leaderboards and a
-- shared badge system. Arcade play never changes Learning Hub XP or trophies.
-- Run after 2026_09_12_platform_insights_and_durable_audit.sql.

begin;
set local search_path = pg_catalog, public;

do $$
begin
    if to_regclass('public.profiles') is null
       or to_regclass('public.number_guess_scores') is null
       or to_regclass('public.mathverse_schema_migrations') is null then
        raise exception 'Run Number Guess and the platform-insights migration first';
    end if;
end
$$;

create table if not exists public.arcade_sessions (
    id uuid primary key default gen_random_uuid(),
    student_id uuid not null references public.profiles(id) on delete cascade,
    game_key text not null check (game_key in ('mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse')),
    grade_level integer not null check (grade_level between 1 and 6),
    status text not null default 'active' check (status in ('active','finished','expired','restarted')),
    score integer not null default 0 check (score >= 0),
    total_answers integer not null default 0 check (total_answers >= 0),
    correct_answers integer not null default 0 check (correct_answers >= 0 and correct_answers <= total_answers),
    current_streak integer not null default 0 check (current_streak >= 0),
    max_streak integer not null default 0 check (max_streak >= 0),
    sequence integer not null default 1 check (sequence > 0),
    challenge jsonb not null check (jsonb_typeof(challenge) = 'object'),
    started_at timestamptz not null default now(),
    expires_at timestamptz not null,
    completed_at timestamptz,
    updated_at timestamptz not null default now(),
    constraint arcade_completion_state check (
        (status = 'active' and completed_at is null) or (status <> 'active' and completed_at is not null)
    )
);
create unique index if not exists arcade_one_active_game_idx on public.arcade_sessions (student_id, game_key) where status = 'active';
create index if not exists arcade_sessions_student_history_idx on public.arcade_sessions (student_id, started_at desc);
create index if not exists arcade_sessions_expiration_idx on public.arcade_sessions (expires_at) where status = 'active';

create table if not exists public.arcade_scores (
    student_id uuid not null references public.profiles(id) on delete cascade,
    game_key text not null check (game_key in ('mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse')),
    best_score integer not null default 0 check (best_score >= 0),
    best_streak integer not null default 0 check (best_streak >= 0),
    games_played integer not null default 0 check (games_played >= 0),
    achieved_at timestamptz,
    updated_at timestamptz not null default now(),
    primary key (student_id, game_key)
);
create index if not exists arcade_scores_leaderboard_idx
    on public.arcade_scores (game_key, best_score desc, best_streak desc, achieved_at asc, student_id asc)
    where best_score > 0;

create table if not exists public.arcade_achievements (
    id uuid primary key default gen_random_uuid(),
    student_id uuid not null references public.profiles(id) on delete cascade,
    achievement_key text not null check (char_length(achievement_key) between 1 and 80),
    game_key text check (game_key is null or game_key in ('number-guess','mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse')),
    metadata jsonb not null default '{}'::jsonb check (jsonb_typeof(metadata) = 'object'),
    unlocked_at timestamptz not null default now(),
    unique (student_id, achievement_key)
);
create index if not exists arcade_achievements_student_idx on public.arcade_achievements (student_id, unlocked_at desc);

alter table public.arcade_sessions enable row level security;
alter table public.arcade_scores enable row level security;
alter table public.arcade_achievements enable row level security;
revoke all on public.arcade_sessions from public, anon, authenticated;
revoke all on public.arcade_scores from public, anon, authenticated;
revoke all on public.arcade_achievements from public, anon, authenticated;
grant all on public.arcade_sessions to service_role;
grant all on public.arcade_scores to service_role;
grant all on public.arcade_achievements to service_role;

create or replace function public.arcade_generate_question(p_game_key text, p_grade_level integer, p_sequence integer)
returns jsonb language plpgsql volatile set search_path = pg_catalog, public
as $$
declare
    grade integer := greatest(1, least(coalesce(p_grade_level, 1), 6));
    -- Rotate through every question family before repeating one. Operands still
    -- change randomly, so a run cannot get stuck on the same obvious template.
    variant integer := mod(greatest(coalesce(p_sequence, 1), 1) - 1, 8);
    base integer := case greatest(1, least(coalesce(p_grade_level, 1), 6))
        when 1 then 20 when 2 then 50 when 3 then 100 when 4 then 200 when 5 then 500 else 1000 end;
    a integer; b integer; c integer; k integer; q integer; answer integer;
    n1 integer; n2 integer; d1 integer; d2 integer;
    comparison text; prompt text; explanation text; sequence_values text;
begin
    if p_game_key not in ('mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse') then
        raise exception 'Unsupported arcade game';
    end if;

    if p_game_key = 'mental-arithmetic' then
        a := floor(random() * greatest(8, base - 7) + 6)::integer;
        b := floor(random() * greatest(7, base / 2) + 4)::integer;
        c := floor(random() * greatest(5, least(a + b - 2, base / 3)) + 2)::integer;
        k := floor(random() * (grade + 2) + 2)::integer;
        q := floor(random() * (grade * 4 + 7) + 3)::integer;
        case variant
            when 0 then c := least(c, a+b-1); answer := a+b-c; prompt := format('(%s + %s) − %s',a,b,c); explanation := 'Add first, then subtract.';
            when 1 then answer := a+b+c; prompt := format('%s + %s + %s',a,b,c); explanation := 'Combine a friendly pair first.';
            when 2 then
                if grade >= 2 then c := least(c,k*q-1); answer := k*q-c; prompt := format('%s × %s − %s',k,q,c); explanation := 'Multiply before subtracting.';
                else answer := a+b-c; prompt := format('%s + %s − %s',a,b,c); explanation := 'Add, then take away.'; end if;
            when 3 then answer := q+a; prompt := format('%s ÷ %s + %s',q*k,k,a); explanation := 'Use exact division before adding.';
            when 4 then answer := b+c; prompt := format('%s + □ = %s',a,a+b+c); explanation := 'Find the difference from the total.';
            when 5 then
                if grade >= 3 then answer := a+b*k; prompt := format('%s + %s × %s',a,b,k); explanation := 'Multiplication comes before addition.';
                else answer := a+b+c; prompt := format('%s + %s + %s',a,b,c); explanation := 'Combine two easy values first.'; end if;
            when 6 then answer := a*2+b; prompt := format('Double %s, then add %s',a,b); explanation := 'Double before adding.';
            else answer := a+b+c; prompt := format('□ − %s = %s',a,b+c); explanation := 'Undo subtraction with addition.';
        end case;
        return jsonb_build_object('prompt',prompt,'answer_type','number','options','[]'::jsonb,'answer',answer::text,'explanation',explanation);
    end if;

    if p_game_key = 'equation-balance' then
        if grade = 1 then variant := variant % 3; end if;
        a := floor(random() * greatest(8,base/2) + 3)::integer;
        b := floor(random() * greatest(8,base/2) + 5)::integer;
        c := floor(random() * greatest(6,base/3) + 2)::integer;
        k := floor(random() * (grade+3) + 2)::integer;
        q := floor(random() * (grade*5+8) + 3)::integer;
        case variant
            when 0 then answer := b+c; prompt := format('x + %s = %s',a,a+b+c); explanation := format('Subtract %s from both sides.',a);
            when 1 then answer := a+b; prompt := format('x − %s = %s',a,b); explanation := format('Add %s to both sides.',a);
            when 2 then answer := greatest(1,b+c-a); c := least(c,a+answer-1); prompt := format('%s + x = %s + %s',a,a+answer-c,c); explanation := 'Work out the right side, then remove the known addend.';
            when 3 then answer := q; prompt := format('%sx = %s',k,k*q); explanation := format('Divide both sides by %s.',k);
            when 4 then answer := k*q; prompt := format('x ÷ %s + %s = %s',k,a,a+q); explanation := 'Undo the addition, then multiply.';
            when 5 then answer := q; prompt := format('%sx + %s = %s',k,a,k*q+a); explanation := 'Undo the addition, then divide.';
            when 6 then answer := q; prompt := format('%s(x + %s) = %s',k,b,k*(q+b)); explanation := 'Divide first, then subtract inside the brackets.';
            else answer := a+c; prompt := format('x + %s = %s + %s',b,a+b,c); explanation := 'Reverse the same operations on both sides.';
        end case;
        return jsonb_build_object('prompt',prompt,'answer_type','number','options','[]'::jsonb,'answer',answer::text,'explanation',explanation);
    end if;

    if p_game_key = 'fraction-comparison' then
        d1 := case when grade=1 then (array[2,4])[floor(random()*2)::integer+1]
                   when grade=2 then (array[2,3,4,5,6,8])[floor(random()*6)::integer+1]
                   else floor(random()*least(10,grade+5)+2)::integer end;
        n1 := floor(random()*greatest(1,case when grade>=3 then d1+grade-1 else d1-1 end)+1)::integer;
        if variant=0 then k:=floor(random()*3+2)::integer; d2:=d1*k; n2:=n1*k;
        elsif variant in (1,2) then d2:=d1; n2:=greatest(1,n1+case when variant=1 then 1 else -1 end); if n2=n1 then n2:=n1+1; end if;
        elsif variant=3 then d2:=d1+floor(random()*4+1)::integer; n2:=greatest(1,round(n1::numeric*d2/d1)::integer); if n1*d2=n2*d1 then n2:=n2+1; end if;
        elsif variant=4 then d2:=floor(random()*least(10,grade+5)+2)::integer; n2:=n1;
        else d2:=floor(random()*least(10,grade+5)+2)::integer; n2:=floor(random()*greatest(1,case when grade>=3 then d2+grade-1 else d2-1 end)+1)::integer; if n1*d2=n2*d1 and variant<>7 then n2:=n2+1; end if;
        end if;
        comparison := case when n1*d2<n2*d1 then '<' when n1*d2>n2*d1 then '>' else '=' end;
        prompt := format('%s/%s  __  %s/%s',n1,d1,n2,d2);
        explanation := format('Cross-products are %s and %s.',n1*d2,n2*d1);
        return jsonb_build_object('prompt',prompt,'answer_type','choice','options',jsonb_build_array('<','=','>'),'answer',comparison,'explanation',explanation);
    end if;

    a:=floor(random()*(grade*7+8)+4)::integer; b:=floor(random()*(grade*3+3)+2)::integer;
    c:=floor(random()*(grade*2+3)+1)::integer; k:=floor(random()*2+2)::integer;
    case variant
        when 0 then sequence_values:=format('%s, %s, %s, %s, %s, %s',a,a+b,a+2*b,a+3*b,a+4*b,a+5*b); answer:=a+6*b; explanation:=format('Add %s each time.',b);
        when 1 then sequence_values:=format('%s, %s, %s, %s, %s, %s',a,a+b,a+b+c,a+2*b+c,a+2*b+2*c,a+3*b+2*c); answer:=a+3*b+3*c; explanation:=format('The increases alternate +%s and +%s.',b,c);
        when 2 then
            if grade>=3 then a:=floor(random()*5+2)::integer; sequence_values:=format('%s, %s, %s, %s, %s, %s',a,a*k,a*power(k,2)::integer,a*power(k,3)::integer,a*power(k,4)::integer,a*power(k,5)::integer); answer:=a*power(k,6)::integer; explanation:=format('Multiply by %s each time.',k);
            else sequence_values:=format('%s, %s, %s, %s, %s, %s',a,a+b,a+2*b,a+3*b,a+4*b,a+5*b); answer:=a+6*b; explanation:=format('Add %s each time.',b); end if;
        when 3 then sequence_values:=format('%s, %s, %s, %s, %s, %s',a+1,a+4,a+9,a+16,a+25,a+36); answer:=a+49; explanation:='The changing part follows square numbers.';
        when 4 then sequence_values:=format('%s, %s, %s, %s, %s, %s',a,a+2,a+5,a+9,a+14,a+20); answer:=a+27; explanation:='The increases grow by one each time.';
        when 5 then sequence_values:=format('%s, %s, %s, %s, %s, %s',a,b,a+c,b+c,a+2*c,b+2*c); answer:=a+3*c; explanation:='Odd and even positions form two interleaved sequences.';
        when 6 then sequence_values:=format('%s, %s, %s, %s, %s, %s',a,2*a+c,4*a+3*c,8*a+7*c,16*a+15*c,32*a+31*c); answer:=64*a+63*c; explanation:=format('Double each term, then add %s.',c);
        else sequence_values:=format('%s, %s, %s, %s, %s, %s',a,b,a+b,a+2*b,2*a+3*b,3*a+5*b); answer:=5*a+8*b; explanation:='Each term is the sum of the previous two.';
    end case;
    return jsonb_build_object('prompt',sequence_values||',  ?','answer_type','number','options','[]'::jsonb,'answer',answer::text,'explanation',explanation);
end;
$$;

create or replace function public.arcade_public_session(p_session public.arcade_sessions)
returns jsonb language sql volatile set search_path = pg_catalog, public
as $$
select jsonb_build_object(
    'id',p_session.id,'game_key',p_session.game_key,'status',p_session.status,
    'score',p_session.score,'answers',p_session.total_answers,'correct_answers',p_session.correct_answers,
    'streak',p_session.current_streak,'best_streak',p_session.max_streak,'sequence',p_session.sequence,
    'challenge',p_session.challenge-'answer'-'explanation','started_at',p_session.started_at,
    'ends_at',p_session.expires_at,
    'remaining_ms',greatest(0,floor(extract(epoch from (p_session.expires_at-clock_timestamp()))*1000)::bigint)
);
$$;

create or replace function public.arcade_record_score(
    p_student_id uuid,p_game_key text,p_score integer,p_streak integer,p_increment_game boolean
) returns public.arcade_scores language plpgsql volatile set search_path = pg_catalog, public
as $$
declare result public.arcade_scores%rowtype;
begin
    insert into public.arcade_scores (student_id,game_key,best_score,best_streak,games_played,achieved_at,updated_at)
    values (p_student_id,p_game_key,greatest(0,p_score),greatest(0,p_streak),case when p_increment_game then 1 else 0 end,case when p_score>0 then now() else null end,now())
    on conflict (student_id,game_key) do update set
        best_score=greatest(public.arcade_scores.best_score,excluded.best_score),
        best_streak=case when excluded.best_score>public.arcade_scores.best_score then excluded.best_streak
                         when excluded.best_score=public.arcade_scores.best_score then greatest(public.arcade_scores.best_streak,excluded.best_streak)
                         else public.arcade_scores.best_streak end,
        games_played=public.arcade_scores.games_played+excluded.games_played,
        achieved_at=case when excluded.best_score>public.arcade_scores.best_score
            or (excluded.best_score=public.arcade_scores.best_score and excluded.best_streak>public.arcade_scores.best_streak)
            then excluded.achieved_at else public.arcade_scores.achieved_at end,
        updated_at=now()
    returning * into result;
    return result;
end;
$$;

create or replace function public.arcade_personal_score(p_student_id uuid,p_game_key text,p_new_best boolean default false)
returns jsonb language sql volatile set search_path = pg_catalog, public
as $$
with student_grade as (select grade_level from public.profiles where id=p_student_id),
ranked as (
    select scores.student_id,row_number() over (order by scores.best_score desc,scores.best_streak desc,scores.achieved_at asc nulls last,scores.student_id) position
    from public.arcade_scores scores join public.profiles profiles on profiles.id=scores.student_id cross join student_grade
    where scores.game_key=p_game_key and scores.best_score>0 and profiles.role='student'
      and profiles.suspended_at is null and profiles.grade_level=student_grade.grade_level
)
select jsonb_build_object('best_score',coalesce(scores.best_score,0),'best_streak',coalesce(scores.best_streak,0),
    'games_played',coalesce(scores.games_played,0),'rank',ranked.position,'new_best',coalesce(p_new_best,false))
from (select 1) seed left join public.arcade_scores scores on scores.student_id=p_student_id and scores.game_key=p_game_key
left join ranked on ranked.student_id=p_student_id;
$$;

create or replace function public.arcade_leaderboard(p_student_id uuid,p_game_key text,p_limit integer default 10)
returns jsonb language sql volatile set search_path = pg_catalog, public
as $$
with student_grade as (select grade_level from public.profiles where id=p_student_id),
ranked as (
    select scores.*,profiles.grade_level,profiles.first_name,profiles.last_name,
           profiles.leaderboard_alias,profiles.show_on_leaderboard,
           row_number() over (order by scores.best_score desc,scores.best_streak desc,scores.achieved_at asc nulls last,scores.student_id) position
    from public.arcade_scores scores join public.profiles profiles on profiles.id=scores.student_id cross join student_grade
    where scores.game_key=p_game_key and scores.best_score>0 and profiles.role='student'
      and profiles.suspended_at is null and profiles.grade_level=student_grade.grade_level
)
select coalesce(jsonb_agg(jsonb_build_object(
    'rank',position,'display_name',case when coalesce(show_on_leaderboard,true)=false and student_id<>p_student_id then 'Anonymous Student'
        else coalesce(nullif(btrim(leaderboard_alias),''),nullif(btrim(concat_ws(' ',coalesce(nullif(btrim(first_name),''),'Student'),case when nullif(btrim(last_name),'') is null then null else left(btrim(last_name),1)||'.' end)),''),'Student') end,
    'grade_level',grade_level,'best_score',best_score,'best_streak',best_streak,'games_played',games_played,'is_current',student_id=p_student_id
) order by position),'[]'::jsonb)
from ranked where position<=greatest(3,least(coalesce(p_limit,10),50)) or student_id=p_student_id;
$$;

create or replace function public.arcade_refresh_achievements(p_student_id uuid)
returns jsonb language plpgsql volatile set search_path = pg_catalog, public
as $$
declare number_score integer:=0; number_runs integer:=0; arcade_runs integer:=0; max_score integer:=0; max_streak integer:=0; games_scored integer:=0;
begin
    select coalesce(best_score,0),coalesce(games_played,0) into number_score,number_runs from public.number_guess_scores where student_id=p_student_id;
    if not found then number_score:=0; number_runs:=0; end if;
    select coalesce(sum(games_played),0),coalesce(max(best_score),0),coalesce(max(best_streak),0),count(*) filter(where best_score>0)
      into arcade_runs,max_score,max_streak,games_scored from public.arcade_scores where student_id=p_student_id;
    max_score:=greatest(max_score,number_score); games_scored:=games_scored+case when number_score>0 then 1 else 0 end;
    insert into public.arcade_achievements(student_id,achievement_key,metadata)
    select p_student_id,b.key,b.metadata from (values
        ('first-launch',jsonb_build_object('runs',number_runs+arcade_runs),number_runs+arcade_runs>=1),
        ('score-five',jsonb_build_object('score',max_score),max_score>=5),
        ('score-ten',jsonb_build_object('score',max_score),max_score>=10),
        ('streak-five',jsonb_build_object('streak',max_streak),max_streak>=5),
        ('arcade-explorer',jsonb_build_object('games',games_scored),games_scored>=3),
        ('arcade-master',jsonb_build_object('games',games_scored),games_scored>=5),
        ('arcade-veteran',jsonb_build_object('runs',number_runs+arcade_runs),number_runs+arcade_runs>=25),
        ('number-navigator',jsonb_build_object('score',number_score),number_score>=5),
        ('mental-meteor',jsonb_build_object('score',coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='mental-arithmetic'),0)),coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='mental-arithmetic'),0)>=10),
        ('equation-engineer',jsonb_build_object('score',coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='equation-balance'),0)),coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='equation-balance'),0)>=10),
        ('fraction-photon',jsonb_build_object('score',coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='fraction-comparison'),0)),coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='fraction-comparison'),0)>=10),
        ('pattern-pilot',jsonb_build_object('score',coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='pattern-pulse'),0)),coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='pattern-pulse'),0)>=10)
    ) b(key,metadata,unlocked) where b.unlocked on conflict(student_id,achievement_key) do nothing;
    return coalesce((select jsonb_agg(jsonb_build_object('key',achievement_key,'game_key',game_key,'unlocked_at',unlocked_at) order by unlocked_at)
        from public.arcade_achievements where student_id=p_student_id),'[]'::jsonb);
end;
$$;

create or replace function public.arcade_hub_dashboard(p_student_id uuid)
returns jsonb language plpgsql volatile security definer set search_path = pg_catalog, public
as $$
declare student_grade integer; scores jsonb; achievements jsonb;
begin
    select grade_level into student_grade from public.profiles where id=p_student_id and role='student' and suspended_at is null;
    if not found or student_grade not between 1 and 6 then raise exception 'Student profile unavailable'; end if;
    achievements:=public.arcade_refresh_achievements(p_student_id);
    with combined as (
        select 'number-guess'::text game_key,best_score,0::integer best_streak,games_played from public.number_guess_scores where student_id=p_student_id
        union all select game_key,best_score,best_streak,games_played from public.arcade_scores where student_id=p_student_id
    ) select coalesce(jsonb_agg(to_jsonb(combined) order by game_key),'[]'::jsonb) into scores from combined;
    return jsonb_build_object('grade_level',student_grade,'scores',scores,'achievements',achievements);
end;
$$;

create or replace function public.arcade_game_dashboard(p_student_id uuid,p_game_key text,p_limit integer default 10)
returns jsonb language plpgsql volatile security definer set search_path = pg_catalog, public
as $$
declare game_now timestamptz:=clock_timestamp(); student_grade integer; session_row public.arcade_sessions%rowtype; has_active boolean:=false;
begin
    if p_game_key not in ('mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse') then raise exception 'Unsupported arcade game'; end if;
    select grade_level into student_grade from public.profiles where id=p_student_id and role='student' and suspended_at is null;
    if not found or student_grade not between 1 and 6 then raise exception 'Student profile unavailable'; end if;
    select * into session_row from public.arcade_sessions where student_id=p_student_id and game_key=p_game_key and status='active' for update;
    if found and session_row.expires_at<=game_now then
        update public.arcade_sessions set status='expired',completed_at=game_now,updated_at=game_now where id=session_row.id returning * into session_row;
        perform public.arcade_record_score(p_student_id,p_game_key,session_row.score,session_row.max_streak,true);
        perform public.arcade_refresh_achievements(p_student_id);
    elsif found then has_active:=true;
    end if;
    return jsonb_build_object('grade_level',student_grade,'session',case when has_active then public.arcade_public_session(session_row) else null end,
        'personal',public.arcade_personal_score(p_student_id,p_game_key,false),'leaderboard',public.arcade_leaderboard(p_student_id,p_game_key,p_limit));
end;
$$;

create or replace function public.start_arcade_game(p_student_id uuid,p_game_key text)
returns jsonb language plpgsql volatile security definer set search_path = pg_catalog, public
as $$
declare game_now timestamptz:=clock_timestamp(); student_grade integer; old_session public.arcade_sessions%rowtype; session_row public.arcade_sessions%rowtype;
begin
    if p_game_key not in ('mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse') then raise exception 'Unsupported arcade game'; end if;
    select grade_level into student_grade from public.profiles where id=p_student_id and role='student' and suspended_at is null;
    if not found or student_grade not between 1 and 6 then raise exception 'Student profile unavailable'; end if;
    select * into old_session from public.arcade_sessions where student_id=p_student_id and game_key=p_game_key and status='active' for update;
    if found then
        update public.arcade_sessions set status=case when expires_at<=game_now then 'expired' else 'restarted' end,completed_at=game_now,updated_at=game_now where id=old_session.id returning * into old_session;
        perform public.arcade_record_score(p_student_id,p_game_key,old_session.score,old_session.max_streak,true);
    end if;
    insert into public.arcade_sessions(student_id,game_key,grade_level,challenge,started_at,expires_at,updated_at)
    values(p_student_id,p_game_key,student_grade,public.arcade_generate_question(p_game_key,student_grade,1),game_now,game_now+interval '60 seconds',game_now)
    returning * into session_row;
    perform public.arcade_refresh_achievements(p_student_id);
    return jsonb_build_object('session',public.arcade_public_session(session_row),'personal',public.arcade_personal_score(p_student_id,p_game_key,false),'outcome',jsonb_build_object('correct',false,'finished',false,'direction','started'));
end;
$$;

create or replace function public.submit_arcade_answer(p_session_id uuid,p_student_id uuid,p_game_key text,p_sequence integer,p_answer text)
returns jsonb language plpgsql volatile security definer set search_path = pg_catalog, public
as $$
declare
    game_now timestamptz:=clock_timestamp(); session_row public.arcade_sessions%rowtype;
    answered_challenge jsonb; expected text; submitted text:=btrim(coalesce(p_answer,''));
    is_correct boolean; previous_best integer:=0; new_streak integer;
begin
    if p_game_key not in ('mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse') then raise exception 'Unsupported arcade game'; end if;
    if submitted='' or char_length(submitted)>40 then raise exception 'Enter a valid answer'; end if;
    select * into session_row from public.arcade_sessions where id=p_session_id and student_id=p_student_id and game_key=p_game_key for update;
    if not found then raise exception 'Arcade session not found'; end if;
    if session_row.status<>'active' then raise exception 'Arcade session has ended'; end if;
    if session_row.expires_at<=game_now then
        update public.arcade_sessions set status='expired',completed_at=game_now,updated_at=game_now where id=session_row.id returning * into session_row;
        perform public.arcade_record_score(p_student_id,session_row.game_key,session_row.score,session_row.max_streak,true);
        perform public.arcade_refresh_achievements(p_student_id);
        return jsonb_build_object('session',public.arcade_public_session(session_row),'personal',public.arcade_personal_score(p_student_id,session_row.game_key,false),'outcome',jsonb_build_object('correct',false,'finished',true,'direction','expired'));
    end if;
    -- Bind the answer to the exact server-owned question shown in the browser.
    -- This makes retried or concurrent requests harmless instead of applying a
    -- previous answer to the next challenge and changing leaderboard scores.
    if p_sequence is null or p_sequence<>session_row.sequence then
        return jsonb_build_object('session',public.arcade_public_session(session_row),
            'personal',public.arcade_personal_score(p_student_id,session_row.game_key,false),
            'outcome',jsonb_build_object('correct',false,'finished',false,'direction','stale'));
    end if;
    answered_challenge:=session_row.challenge; expected:=answered_challenge->>'answer';
    if answered_challenge->>'answer_type'='number' then
        if submitted~'^-?[0-9]+$' then is_correct:=submitted::numeric=expected::numeric;
        else is_correct:=false; end if;
    else is_correct:=submitted=expected; end if;
    select coalesce(best_score,0) into previous_best from public.arcade_scores where student_id=p_student_id and game_key=session_row.game_key;
    if not found then previous_best:=0; end if;
    new_streak:=case when is_correct then session_row.current_streak+1 else 0 end;
    update public.arcade_sessions set score=score+case when is_correct then 1 else 0 end,
        total_answers=total_answers+1,correct_answers=correct_answers+case when is_correct then 1 else 0 end,
        current_streak=new_streak,max_streak=greatest(max_streak,new_streak),sequence=sequence+1,
        challenge=public.arcade_generate_question(game_key,grade_level,sequence+1),updated_at=game_now
    where id=session_row.id returning * into session_row;
    perform public.arcade_record_score(p_student_id,session_row.game_key,session_row.score,session_row.max_streak,false);
    perform public.arcade_refresh_achievements(p_student_id);
    return jsonb_build_object('session',public.arcade_public_session(session_row),
        'personal',public.arcade_personal_score(p_student_id,session_row.game_key,session_row.score>previous_best),
        'outcome',jsonb_build_object('correct',is_correct,'finished',false,'direction',case when is_correct then 'correct' else 'incorrect' end,'correct_answer',expected,'explanation',answered_challenge->>'explanation'));
end;
$$;

-- Number Guess predates the shared arcade. Keep its original implementation as
-- a private helper while exposing a versioned entry point that neutralizes
-- replayed guesses after a response is lost or another tab advances the run.
create or replace function public.submit_number_guess(
    p_session_id uuid,
    p_student_id uuid,
    p_expected_guesses integer,
    p_guess integer
)
returns jsonb language plpgsql volatile security definer set search_path = pg_catalog, public
as $$
declare
    session_row public.number_guess_sessions%rowtype;
    score_row public.number_guess_scores%rowtype;
    game_now timestamptz;
begin
    select * into session_row
      from public.number_guess_sessions
     where id=p_session_id and student_id=p_student_id
     for update;
    if not found then raise exception 'Game session not found'; end if;
    game_now:=clock_timestamp();

    if session_row.status='active'
       and session_row.expires_at>game_now
       and (p_expected_guesses is null or p_expected_guesses<>session_row.total_guesses) then
        select * into score_row from public.number_guess_scores where student_id=p_student_id;
        return jsonb_build_object(
            'server_now',game_now,
            'session',jsonb_build_object(
                'id',session_row.id,'status',session_row.status,'score',session_row.score,
                'guesses',session_row.total_guesses,'range_min',1,'range_max',session_row.range_max,
                'started_at',session_row.started_at,'ends_at',session_row.expires_at,
                'remaining_ms',greatest(0,floor(extract(epoch from (session_row.expires_at-game_now))*1000)::bigint)
            ),
            'personal',jsonb_build_object(
                'best_score',coalesce(score_row.best_score,0),'best_guesses',score_row.best_guesses,
                'games_played',coalesce(score_row.games_played,0),'new_best',false
            ),
            'outcome',jsonb_build_object('direction','stale','correct',false,'finished',false)
        );
    end if;

    return public.submit_number_guess(p_session_id,p_student_id,p_guess);
end;
$$;

create or replace function public.finish_arcade_game(p_session_id uuid,p_student_id uuid,p_game_key text)
returns jsonb language plpgsql volatile security definer set search_path = pg_catalog, public
as $$
declare game_now timestamptz:=clock_timestamp(); session_row public.arcade_sessions%rowtype;
begin
    if p_game_key not in ('mental-arithmetic','equation-balance','fraction-comparison','pattern-pulse') then raise exception 'Unsupported arcade game'; end if;
    select * into session_row from public.arcade_sessions where id=p_session_id and student_id=p_student_id and game_key=p_game_key for update;
    if not found then raise exception 'Arcade session not found'; end if;
    if session_row.status='active' then
        update public.arcade_sessions set status=case when expires_at<=game_now then 'expired' else 'finished' end,completed_at=game_now,updated_at=game_now where id=session_row.id returning * into session_row;
        perform public.arcade_record_score(p_student_id,session_row.game_key,session_row.score,session_row.max_streak,true);
        perform public.arcade_refresh_achievements(p_student_id);
    end if;
    return jsonb_build_object('session',public.arcade_public_session(session_row),'personal',public.arcade_personal_score(p_student_id,session_row.game_key,false),
        'outcome',jsonb_build_object('correct',false,'finished',true,'direction',case when session_row.status='expired' then 'expired' else 'finished' end));
end;
$$;

revoke all on function public.arcade_generate_question(text,integer,integer) from public,anon,authenticated,service_role;
revoke all on function public.arcade_public_session(public.arcade_sessions) from public,anon,authenticated,service_role;
revoke all on function public.arcade_record_score(uuid,text,integer,integer,boolean) from public,anon,authenticated,service_role;
revoke all on function public.arcade_personal_score(uuid,text,boolean) from public,anon,authenticated,service_role;
revoke all on function public.arcade_leaderboard(uuid,text,integer) from public,anon,authenticated,service_role;
revoke all on function public.arcade_refresh_achievements(uuid) from public,anon,authenticated,service_role;
revoke all on function public.arcade_hub_dashboard(uuid) from public,anon,authenticated;
revoke all on function public.arcade_game_dashboard(uuid,text,integer) from public,anon,authenticated;
revoke all on function public.start_arcade_game(uuid,text) from public,anon,authenticated;
revoke all on function public.submit_arcade_answer(uuid,uuid,text,integer,text) from public,anon,authenticated;
revoke all on function public.finish_arcade_game(uuid,uuid,text) from public,anon,authenticated;
revoke all on function public.submit_number_guess(uuid,uuid,integer) from public,anon,authenticated,service_role;
revoke all on function public.submit_number_guess(uuid,uuid,integer,integer) from public,anon,authenticated,service_role;
grant execute on function public.arcade_hub_dashboard(uuid) to service_role;
grant execute on function public.arcade_game_dashboard(uuid,text,integer) to service_role;
grant execute on function public.start_arcade_game(uuid,text) to service_role;
grant execute on function public.submit_arcade_answer(uuid,uuid,text,integer,text) to service_role;
grant execute on function public.finish_arcade_game(uuid,uuid,text) to service_role;
grant execute on function public.submit_number_guess(uuid,uuid,integer,integer) to service_role;

insert into public.mathverse_schema_migrations(migration_key) values('2026_09_12_shared_math_arcade.sql') on conflict(migration_key) do nothing;
notify pgrst, 'reload schema';
commit;
