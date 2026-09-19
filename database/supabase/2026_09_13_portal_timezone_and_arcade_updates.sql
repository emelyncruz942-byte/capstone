-- Keep persisted timestamps in UTC. Only reporting calendar boundaries change.
-- Retire Fraction Photon without deleting sessions, scores, or earned badges.
begin;

create or replace function public.teacher_learning_hub_analytics(
    p_teacher_id uuid, p_class_id uuid default null, p_days integer default 30
) returns jsonb
language plpgsql stable security definer set search_path = pg_catalog, public
as $$
declare
    safe_days integer := greatest(7, least(coalesce(p_days, 30), 180));
    -- Include complete Philippine calendar days, including today.
    period_start timestamptz := (date_trunc('day', now() at time zone 'Asia/Manila')
        - make_interval(days => greatest(7, least(coalesce(p_days, 30), 180)) - 1)) at time zone 'Asia/Manila';
    result jsonb;
begin
    if not exists (select 1 from public.profiles where id = p_teacher_id and role = 'teacher' and suspended_at is null) then
        raise exception 'Teacher profile unavailable';
    end if;
    if p_class_id is not null and not exists (
        select 1 from public.classes where id = p_class_id and teacher_id = p_teacher_id and archived_at is null
    ) then raise exception 'Class is unavailable'; end if;

    with selected_classes as (
        select id, class_name, grade_level from public.classes
         where teacher_id = p_teacher_id and archived_at is null
           and (p_class_id is null or id = p_class_id)
    ),
    available_classes as (
        select id, class_name, grade_level from public.classes
         where teacher_id = p_teacher_id and archived_at is null
    ),
    selected_students as (
        select distinct p.id, p.first_name, p.last_name, p.grade_level
        from selected_classes c join public.class_members m on m.class_id = c.id
        join public.profiles p on p.id = m.student_id
        where p.role = 'student' and p.suspended_at is null
    ),
    period_questions as (
        select q.* from public.practice_questions q
        join selected_students s on s.id = q.student_id
        where q.answered_at is not null and q.answered_at >= period_start
    ),
    selected_mastery as (
        select m.* from public.practice_mastery m join selected_students s on s.id = m.student_id
    ),
    topic_points as (
        select q.student_id, q.competency_key, q.mastery_after,
            row_number() over (partition by q.student_id, q.competency_key order by q.answered_at, q.id) as first_position,
            row_number() over (partition by q.student_id, q.competency_key order by q.answered_at desc, q.id desc) as last_position
        from period_questions q where q.mastery_after is not null
    ),
    topic_improvements as (
        select student_id, competency_key,
            (max(mastery_after) filter (where last_position = 1) -
             max(mastery_after) filter (where first_position = 1))::numeric as improvement
        from topic_points group by student_id, competency_key
    ),
    student_activity as (
        select student_id, count(*)::integer answers,
            count(*) filter (where is_correct)::integer correct,
            coalesce(sum(hints_revealed), 0)::integer hints,
            count(*) filter (where hints_revealed > 0)::integer hinted_answers,
            max(answered_at) last_practiced_at
        from period_questions group by student_id
    ),
    student_mastery as (
        select student_id, round(avg(mastery_score)::numeric, 1) mastery,
            count(*) filter (where mastery_score >= 90)::integer mastered_topics,
            count(*)::integer practised_topics
        from selected_mastery group by student_id
    ),
    student_improvement as (
        select student_id, round(avg(improvement)::numeric, 1) improvement
        from topic_improvements group by student_id
    )
    select jsonb_build_object(
        'period_days', safe_days,
        'timezone', 'Asia/Manila',
        'generated_at', now(),
        'summary', jsonb_build_object(
            'students', (select count(*) from selected_students),
            'active_students', (select count(distinct student_id) from period_questions),
            'answers', (select count(*) from period_questions),
            'accuracy', coalesce((select round((100.0 * count(*) filter (where is_correct) / nullif(count(*), 0))::numeric, 1) from period_questions), 0),
            'average_mastery', coalesce((select round(avg(mastery_score)::numeric, 1) from selected_mastery), 0),
            'hints_used', coalesce((select sum(hints_revealed) from period_questions), 0),
            'hint_usage_rate', coalesce((select round((100.0 * count(*) filter (where hints_revealed > 0) / nullif(count(*), 0))::numeric, 1) from period_questions), 0),
            'improvement', coalesce((select round(avg(improvement)::numeric, 1) from topic_improvements), 0)
        ),
        'available_classes', coalesce((select jsonb_agg(jsonb_build_object('id', c.id, 'name', c.class_name, 'grade_level', c.grade_level) order by c.class_name) from available_classes c), '[]'::jsonb),
        'classes', coalesce((select jsonb_agg(to_jsonb(x) order by x.class_name) from (
            select c.id, c.class_name, c.grade_level,
                count(distinct m.student_id)::integer students,
                count(distinct m.student_id) filter (where a.answers > 0)::integer active_students,
                coalesce(sum(a.answers), 0)::integer answers,
                coalesce(round((100.0 * sum(a.correct) / nullif(sum(a.answers), 0))::numeric, 1), 0) accuracy,
                coalesce(round(avg(sm.mastery)::numeric, 1), 0) mastery,
                coalesce(sum(a.hints), 0)::integer hints_used,
                coalesce(round(avg(si.improvement)::numeric, 1), 0) improvement
            from selected_classes c left join public.class_members m on m.class_id = c.id
            left join student_activity a on a.student_id = m.student_id
            left join student_mastery sm on sm.student_id = m.student_id
            left join student_improvement si on si.student_id = m.student_id
            group by c.id, c.class_name, c.grade_level
        ) x), '[]'::jsonb),
        'students', coalesce((select jsonb_agg(to_jsonb(x) order by x.last_practiced_at desc nulls last, x.name) from (
            select s.id, btrim(concat_ws(' ', s.first_name, s.last_name)) name, s.grade_level,
                coalesce(a.answers, 0) answers,
                coalesce(round((100.0 * a.correct / nullif(a.answers, 0))::numeric, 1), 0) accuracy,
                coalesce(a.hints, 0) hints_used,
                coalesce(round((100.0 * a.hinted_answers / nullif(a.answers, 0))::numeric, 1), 0) hint_usage_rate,
                coalesce(sm.mastery, 0) mastery, coalesce(sm.mastered_topics, 0) mastered_topics,
                coalesce(sm.practised_topics, 0) practised_topics,
                coalesce(si.improvement, 0) improvement, a.last_practiced_at,
                coalesce((select string_agg(c.class_name, ', ' order by c.class_name)
                    from selected_classes c join public.class_members cm on cm.class_id = c.id
                    where cm.student_id = s.id), '') classes
            from selected_students s left join student_activity a on a.student_id = s.id
            left join student_mastery sm on sm.student_id = s.id
            left join student_improvement si on si.student_id = s.id
            order by a.last_practiced_at desc nulls last, name limit 200
        ) x), '[]'::jsonb),
        'weak_topics', coalesce((select jsonb_agg(to_jsonb(x) order by x.mastery, x.attempts desc) from (
            select competency_key, grade_level, count(distinct student_id)::integer students,
                round(avg(mastery_score)::numeric, 1) mastery, sum(attempts)::integer attempts,
                coalesce(round((100.0 * sum(correct_answers) / nullif(sum(attempts), 0))::numeric, 1), 0) accuracy,
                sum(hints_used)::integer hints_used
            from selected_mastery where attempts > 0 and mastery_score < 90
            group by competency_key, grade_level order by avg(mastery_score), sum(attempts) desc limit 12
        ) x), '[]'::jsonb),
        'daily_activity', coalesce((select jsonb_agg(to_jsonb(x) order by x.activity_date) from (
            select (answered_at at time zone 'Asia/Manila')::date activity_date,
                count(*)::integer answers, count(*) filter (where is_correct)::integer correct,
                coalesce(sum(hints_revealed), 0)::integer hints_used
            from period_questions group by (answered_at at time zone 'Asia/Manila')::date
        ) x), '[]'::jsonb),
        'recent_activity', coalesce((select jsonb_agg(to_jsonb(x) order by x.answered_at desc) from (
            select q.id, btrim(concat_ws(' ', p.first_name, p.last_name)) student_name,
                p.grade_level, q.competency_key, q.is_correct, q.hints_revealed hints_used,
                q.mastery_after, q.answered_at
            from period_questions q join public.profiles p on p.id = q.student_id
            order by q.answered_at desc limit 20
        ) x), '[]'::jsonb)
    ) into result;

    return result;
end;
$$;
revoke all on function public.teacher_learning_hub_analytics(uuid, uuid, integer) from public, anon, authenticated;
grant execute on function public.teacher_learning_hub_analytics(uuid, uuid, integer) to service_role;

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
    prompt text; explanation text; sequence_values text;
begin
    if p_game_key not in ('mental-arithmetic','equation-balance','pattern-pulse') then
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
-- Internal helpers are called by owner-executed arcade RPCs, not exposed directly.
revoke all on function public.arcade_generate_question(text, integer, integer) from public, anon, authenticated, service_role;

create or replace function public.arcade_refresh_achievements(p_student_id uuid)
returns jsonb language plpgsql volatile set search_path = pg_catalog, public
as $$
declare number_score integer:=0; number_runs integer:=0; arcade_runs integer:=0; max_score integer:=0; max_streak integer:=0; games_scored integer:=0;
begin
    select coalesce(best_score,0),coalesce(games_played,0) into number_score,number_runs from public.number_guess_scores where student_id=p_student_id;
    if not found then number_score:=0; number_runs:=0; end if;
    select coalesce(sum(games_played),0),coalesce(max(best_score),0),coalesce(max(best_streak),0),count(*) filter(where best_score>0)
      into arcade_runs,max_score,max_streak,games_scored from public.arcade_scores where student_id=p_student_id
        and game_key in ('mental-arithmetic','equation-balance','pattern-pulse');
    max_score:=greatest(max_score,number_score); games_scored:=games_scored+case when number_score>0 then 1 else 0 end;
    insert into public.arcade_achievements(student_id,achievement_key,metadata)
    select p_student_id,b.key,b.metadata from (values
        ('first-launch',jsonb_build_object('runs',number_runs+arcade_runs),number_runs+arcade_runs>=1),
        ('score-five',jsonb_build_object('score',max_score),max_score>=5),
        ('score-ten',jsonb_build_object('score',max_score),max_score>=10),
        ('streak-five',jsonb_build_object('streak',max_streak),max_streak>=5),
        ('arcade-explorer',jsonb_build_object('games',games_scored),games_scored>=3),
        ('arcade-master',jsonb_build_object('games',games_scored),games_scored>=4),
        ('arcade-veteran',jsonb_build_object('runs',number_runs+arcade_runs),number_runs+arcade_runs>=25),
        ('number-navigator',jsonb_build_object('score',number_score),number_score>=5),
        ('mental-meteor',jsonb_build_object('score',coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='mental-arithmetic'),0)),coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='mental-arithmetic'),0)>=10),
        ('equation-engineer',jsonb_build_object('score',coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='equation-balance'),0)),coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='equation-balance'),0)>=10),
        ('pattern-pilot',jsonb_build_object('score',coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='pattern-pulse'),0)),coalesce((select best_score from public.arcade_scores where student_id=p_student_id and game_key='pattern-pulse'),0)>=10)
    ) b(key,metadata,unlocked) where b.unlocked on conflict(student_id,achievement_key) do nothing;
    return coalesce((select jsonb_agg(jsonb_build_object('key',achievement_key,'game_key',game_key,'unlocked_at',unlocked_at) order by unlocked_at)
        from public.arcade_achievements where student_id=p_student_id),'[]'::jsonb);
end;
$$;
revoke all on function public.arcade_refresh_achievements(uuid) from public, anon, authenticated, service_role;

insert into public.mathverse_schema_migrations (migration_key)
values ('2026_09_13_portal_timezone_and_arcade_updates.sql')
on conflict (migration_key) do nothing;

notify pgrst, 'reload schema';
commit;
