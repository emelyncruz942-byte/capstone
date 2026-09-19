-- Teacher-authorized solo Learning Hub statistics for one enrolled student.
-- Stored timestamps remain UTC; reporting days use Asia/Manila.
begin;

create or replace function public.teacher_learning_hub_student_analytics(
    p_teacher_id uuid,
    p_student_id uuid,
    p_class_id uuid default null,
    p_days integer default 30
) returns jsonb
language plpgsql
stable
security definer
set search_path = pg_catalog, public
set timezone = 'Asia/Manila'
as $$
declare
    safe_days integer := greatest(7, least(coalesce(p_days, 30), 180));
    period_start timestamptz := (
        date_trunc('day', statement_timestamp() at time zone 'Asia/Manila')
        - make_interval(days => greatest(7, least(coalesce(p_days, 30), 180)) - 1)
    ) at time zone 'Asia/Manila';
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
           and archived_at is null
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
           and class.archived_at is null
           and (p_class_id is null or class.id = p_class_id)
    ) then
        raise exception 'Student is unavailable';
    end if;

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
           and class.archived_at is null
           and (p_class_id is null or class.id = p_class_id)
    ),
    period_questions as (
        select question.*
          from public.practice_questions question
         where question.student_id = p_student_id
           and question.answered_at is not null
           and question.answered_at >= period_start
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
$$;

revoke all on function public.teacher_learning_hub_student_analytics(uuid, uuid, uuid, integer)
    from public, anon, authenticated;
grant execute on function public.teacher_learning_hub_student_analytics(uuid, uuid, uuid, integer)
    to service_role;

comment on function public.teacher_learning_hub_student_analytics(uuid, uuid, uuid, integer)
    is 'Returns individual Learning Hub statistics only when the student belongs to an active class owned by the requesting teacher.';

insert into public.mathverse_schema_migrations (migration_key)
values ('2026_09_19_teacher_student_learning_hub_insights.sql')
on conflict (migration_key) do nothing;

notify pgrst, 'reload schema';
commit;

