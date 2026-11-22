-- Complete authorized Learning Hub history, through the current instant.
-- Existing server-only permissions remain; no scores or activity records are changed.

CREATE OR REPLACE FUNCTION public.teacher_learning_hub_analytics(p_teacher_id uuid, p_class_id uuid DEFAULT NULL::uuid, p_days integer DEFAULT 0)
 RETURNS jsonb
 LANGUAGE plpgsql
 STABLE SECURITY DEFINER
 SET search_path TO 'pg_catalog', 'public'
AS $function$
declare
    safe_days integer := case when coalesce(p_days, 0) = 0 then 0 else greatest(7, least(p_days, 180)) end;
    period_end timestamptz := statement_timestamp();
    period_start timestamptz;
    result jsonb;
begin
    if not exists (select 1 from public.profiles where id = p_teacher_id and role = 'teacher' and suspended_at is null) then
        raise exception 'Teacher profile unavailable';
    end if;
    if p_class_id is not null and not exists (
        select 1 from public.classes where id = p_class_id and teacher_id = p_teacher_id and deleted_at is null
    ) then raise exception 'Class is unavailable'; end if;

    if safe_days = 0 then
        select (date_trunc('day', min(q.answered_at) at time zone 'Asia/Manila')) at time zone 'Asia/Manila'
          into period_start
          from public.practice_questions q
         where q.answered_at <= period_end and exists (
             select 1 from public.class_members m
             join public.classes c on c.id = m.class_id
             join public.profiles s on s.id = m.student_id
             where m.student_id = q.student_id and c.teacher_id = p_teacher_id
               and c.deleted_at is null and (p_class_id is null or c.id = p_class_id)
               and s.role = 'student' and s.suspended_at is null
         );
    else
        period_start := (date_trunc('day', period_end at time zone 'Asia/Manila')
            - make_interval(days => safe_days - 1)) at time zone 'Asia/Manila';
    end if;
    period_start := coalesce(period_start, date_trunc('day', period_end at time zone 'Asia/Manila') at time zone 'Asia/Manila');

    with selected_classes as (
        select id, class_name, grade_level from public.classes
         where teacher_id = p_teacher_id and deleted_at is null
           and (p_class_id is null or id = p_class_id)
    ),
    available_classes as (
        select id, class_name, grade_level from public.classes
         where teacher_id = p_teacher_id and deleted_at is null
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
        where q.answered_at is not null and q.answered_at >= period_start and q.answered_at <= period_end
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
        'period_start', period_start,
        'period_end', period_end,
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
            order by a.last_practiced_at desc nulls last, name
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
$function$;

CREATE OR REPLACE FUNCTION public.teacher_learning_hub_student_analytics(p_teacher_id uuid, p_student_id uuid, p_class_id uuid DEFAULT NULL::uuid, p_days integer DEFAULT 0)
 RETURNS jsonb
 LANGUAGE plpgsql
 STABLE SECURITY DEFINER
 SET search_path TO 'pg_catalog', 'public'
 SET "TimeZone" TO 'Asia/Manila'
AS $function$
declare
    safe_days integer := case when coalesce(p_days, 0) = 0 then 0 else greatest(7, least(p_days, 180)) end;
    period_end timestamptz := statement_timestamp();
    period_start timestamptz;
    result jsonb;
begin
    if not exists (
        select 1
          from public.profiles
         where id = p_teacher_id
           and role = 'teacher'
           and suspended_at is null
    ) then
        raise exception 'Teacher profile unavailable';
    end if;

    if p_class_id is not null and not exists (
        select 1
          from public.classes
         where id = p_class_id
           and teacher_id = p_teacher_id
           and deleted_at is null
    ) then
        raise exception 'Class is unavailable';
    end if;

    -- Authorization is enforced in the database so a guessed student UUID
    -- cannot expose a learner outside this teacher's active classes.
    if not exists (
        select 1
          from public.profiles student
          join public.class_members membership on membership.student_id = student.id
          join public.classes class on class.id = membership.class_id
         where student.id = p_student_id
           and student.role = 'student'
           and student.suspended_at is null
           and class.teacher_id = p_teacher_id
           and class.deleted_at is null
           and (p_class_id is null or class.id = p_class_id)
    ) then
        raise exception 'Student is unavailable';
    end if;

    if safe_days = 0 then
        select (date_trunc('day', min(q.answered_at) at time zone 'Asia/Manila')) at time zone 'Asia/Manila'
          into period_start from public.practice_questions q
         where q.student_id = p_student_id and q.answered_at <= period_end;
    else
        period_start := (date_trunc('day', period_end at time zone 'Asia/Manila')
            - make_interval(days => safe_days - 1)) at time zone 'Asia/Manila';
    end if;
    period_start := coalesce(period_start, date_trunc('day', period_end at time zone 'Asia/Manila') at time zone 'Asia/Manila');

    with student_profile as (
        select id, first_name, last_name, grade_level
          from public.profiles
         where id = p_student_id
           and role = 'student'
           and suspended_at is null
    ),
    student_classes as (
        select distinct class.id, class.class_name, class.grade_level
          from public.classes class
          join public.class_members membership on membership.class_id = class.id
         where membership.student_id = p_student_id
           and class.teacher_id = p_teacher_id
           and class.deleted_at is null
           and (p_class_id is null or class.id = p_class_id)
    ),
    period_questions as (
        select question.*
          from public.practice_questions question
         where question.student_id = p_student_id
           and question.answered_at is not null
           and question.answered_at >= period_start
           and question.answered_at <= period_end
    ),
    student_mastery as (
        select mastery.*
          from public.practice_mastery mastery
         where mastery.student_id = p_student_id
    ),
    topic_points as (
        select
            question.competency_key,
            question.mastery_after,
            row_number() over (
                partition by question.competency_key
                order by question.answered_at, question.id
            ) as first_position,
            row_number() over (
                partition by question.competency_key
                order by question.answered_at desc, question.id desc
            ) as last_position
          from period_questions question
         where question.mastery_after is not null
    ),
    topic_improvements as (
        select
            competency_key,
            (
                max(mastery_after) filter (where last_position = 1)
                - max(mastery_after) filter (where first_position = 1)
            )::numeric as improvement
          from topic_points
         group by competency_key
    ),
    period_topic_activity as (
        select
            competency_key,
            count(*)::integer as answers,
            count(*) filter (where is_correct)::integer as correct,
            coalesce(sum(hints_revealed), 0)::integer as hints,
            count(*) filter (where hints_revealed > 0)::integer as hinted_answers,
            max(answered_at) as last_practiced_at
          from period_questions
         group by competency_key
    )
    select jsonb_build_object(
        'period_days', safe_days,
        'period_start', period_start,
        'period_end', period_end,
        'timezone', 'Asia/Manila',
        'generated_at', statement_timestamp(),
        'student', coalesce((
            select jsonb_build_object(
                'id', profile.id,
                'name', btrim(concat_ws(' ', profile.first_name, profile.last_name)),
                'grade_level', profile.grade_level
            )
              from student_profile profile
        ), '{}'::jsonb),
        'classes', coalesce((
            select jsonb_agg(
                jsonb_build_object(
                    'id', class.id,
                    'name', class.class_name,
                    'grade_level', class.grade_level
                )
                order by class.class_name
            )
              from student_classes class
        ), '[]'::jsonb),
        'summary', jsonb_build_object(
            'answers', (select count(*)::integer from period_questions),
            'correct', (select count(*) filter (where is_correct)::integer from period_questions),
            'accuracy', coalesce((
                select round(
                    (100.0 * count(*) filter (where is_correct) / nullif(count(*), 0))::numeric,
                    1
                )
                  from period_questions
            ), 0),
            'hints_used', coalesce((select sum(hints_revealed) from period_questions), 0),
            'hint_usage_rate', coalesce((
                select round(
                    (100.0 * count(*) filter (where hints_revealed > 0) / nullif(count(*), 0))::numeric,
                    1
                )
                  from period_questions
            ), 0),
            'average_mastery', coalesce((
                select round(avg(mastery_score)::numeric, 1)
                  from student_mastery
                 where attempts > 0
            ), 0),
            'mastered_topics', (
                select count(*) filter (where attempts > 0 and mastery_score >= 90)::integer
                  from student_mastery
            ),
            'practised_topics', (
                select count(*) filter (where attempts > 0)::integer
                  from student_mastery
            ),
            'improvement', coalesce((
                select round(avg(improvement)::numeric, 1)
                  from topic_improvements
            ), 0),
            'active_days', (
                select count(distinct (answered_at at time zone 'Asia/Manila')::date)::integer
                  from period_questions
            ),
            'last_practiced_at', (select max(answered_at) from period_questions)
        ),
        'topics', coalesce((
            select jsonb_agg(to_jsonb(topic) order by topic.mastery, topic.period_answers desc, topic.competency_key)
              from (
                select
                    mastery.competency_key,
                    mastery.grade_level,
                    mastery.mastery_score::numeric as mastery,
                    mastery.difficulty,
                    mastery.attempts as lifetime_attempts,
                    mastery.correct_answers as lifetime_correct,
                    coalesce(round(
                        (100.0 * mastery.correct_answers / nullif(mastery.attempts, 0))::numeric,
                        1
                    ), 0) as lifetime_accuracy,
                    mastery.hints_used as lifetime_hints,
                    coalesce(activity.answers, 0)::integer as period_answers,
                    coalesce(round(
                        (100.0 * activity.correct / nullif(activity.answers, 0))::numeric,
                        1
                    ), 0) as period_accuracy,
                    coalesce(activity.hints, 0)::integer as period_hints,
                    coalesce(improvement.improvement, 0)::numeric as improvement,
                    greatest(mastery.last_practiced_at, activity.last_practiced_at) as last_practiced_at,
                    mastery.next_review_at
                  from student_mastery mastery
                  left join period_topic_activity activity
                    on activity.competency_key = mastery.competency_key
                  left join topic_improvements improvement
                    on improvement.competency_key = mastery.competency_key
                 where mastery.attempts > 0
                    or coalesce(activity.answers, 0) > 0
              ) topic
        ), '[]'::jsonb),
        'daily_activity', coalesce((
            select jsonb_agg(to_jsonb(day) order by day.activity_date)
              from (
                select
                    (answered_at at time zone 'Asia/Manila')::date as activity_date,
                    count(*)::integer as answers,
                    count(*) filter (where is_correct)::integer as correct,
                    coalesce(round(
                        (100.0 * count(*) filter (where is_correct) / nullif(count(*), 0))::numeric,
                        1
                    ), 0) as accuracy,
                    coalesce(sum(hints_revealed), 0)::integer as hints_used
                  from period_questions
                 group by (answered_at at time zone 'Asia/Manila')::date
              ) day
        ), '[]'::jsonb),
        'recent_activity', coalesce((
            select jsonb_agg(to_jsonb(activity) order by activity.answered_at desc)
              from (
                select
                    id,
                    competency_key,
                    difficulty,
                    is_correct,
                    hints_revealed as hints_used,
                    mastery_after,
                    response_ms,
                    answered_at
                  from period_questions
                 order by answered_at desc, id desc
                 limit 20
              ) activity
        ), '[]'::jsonb)
    ) into result;

    return result;
end;
$function$;

REVOKE ALL ON FUNCTION public.teacher_learning_hub_analytics(uuid, uuid, integer) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.teacher_learning_hub_analytics(uuid, uuid, integer) TO service_role;
REVOKE ALL ON FUNCTION public.teacher_learning_hub_student_analytics(uuid, uuid, uuid, integer) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.teacher_learning_hub_student_analytics(uuid, uuid, uuid, integer) TO service_role;

NOTIFY pgrst, 'reload schema';
