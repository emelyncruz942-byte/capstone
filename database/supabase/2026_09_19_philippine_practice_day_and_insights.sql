-- Use Philippine calendar days for Learning Hub daily totals and teacher insights.
-- Persisted timestamptz values remain UTC; only day boundaries and buckets change.
-- Safe to rerun after the September 13 portal update.
begin;

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
set search_path = pg_catalog, public
set timezone = 'Asia/Manila'
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
    -- p_day_started_at is retained only to keep the existing RPC signature
    -- compatible. Calendar boundaries are calculated by the trusted server.
    philippine_day_start timestamptz;
    philippine_day_end timestamptz;
begin
    philippine_day_start := date_trunc(
        'day',
        statement_timestamp() at time zone 'Asia/Manila'
    ) at time zone 'Asia/Manila';
    philippine_day_end := (
        date_trunc('day', statement_timestamp() at time zone 'Asia/Manila')
        + interval '1 day'
    ) at time zone 'Asia/Manila';

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
           and answered_at >= philippine_day_start
           and answered_at < philippine_day_end;

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
       and answered_at >= philippine_day_start
       and answered_at < philippine_day_end;

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

revoke all on function public.submit_practice_answer(uuid, uuid, text, integer, timestamptz)
    from public, anon, authenticated;
grant execute on function public.submit_practice_answer(uuid, uuid, text, integer, timestamptz)
    to service_role;

comment on function public.submit_practice_answer(uuid, uuid, text, integer, timestamptz)
    is 'Records a practice answer and reports daily progress using Asia/Manila calendar boundaries. p_day_started_at is retained for RPC compatibility.';

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

insert into public.mathverse_schema_migrations (migration_key)
values ('2026_09_19_philippine_practice_day_and_insights.sql')
on conflict (migration_key) do nothing;

notify pgrst, 'reload schema';
commit;
