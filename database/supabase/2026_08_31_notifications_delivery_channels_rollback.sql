-- Roll back the email/Web Push delivery outbox while retaining in-app bells.

begin;

set local search_path = public, extensions;

drop trigger if exists notifications_queue_delivery on public.notifications;
drop function if exists public.queue_notification_delivery();
drop function if exists public.claim_notification_deliveries(integer, uuid);
drop table if exists public.notification_deliveries;

-- Restore the profile and quiz-result notification functions installed by
-- 2026_08_31_notifications_and_account_security.sql.
create or replace function public.notify_profile_created()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    applicant_name text;
begin
    if new.role = 'pending_teacher' then
        applicant_name := coalesce(
            nullif(btrim(concat_ws(' ', new.first_name, new.last_name)), ''),
            new.email,
            'A teacher applicant'
        );
        perform public.notify_all_admins(
            'teacher_verification',
            'Teacher verification requested',
            applicant_name || ' registered and is waiting for approval.',
            '/admin/dashboard?section=role-verify',
            jsonb_build_object('teacher_id', new.id),
            'teacher-verification:' || new.id::text
        );
    end if;

    return new;
end;
$$;

create or replace function public.notify_profile_state_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if old.role = 'pending_teacher' and new.role = 'teacher' then
        perform public.create_notification(
            new.id,
            'teacher_approved',
            'Teacher account approved',
            'Your MathVerse teacher account was approved. You can now create classes and quizzes.',
            '/teacher/dashboard',
            '{}'::jsonb,
            'teacher-approved:' || new.id::text
        );
    end if;

    if old.suspended_at is not null and new.suspended_at is null then
        perform public.create_notification(
            new.id,
            'account_restored',
            'Account access restored',
            'An administrator restored your MathVerse account access.',
            case
                when new.role = 'teacher' then '/teacher/dashboard'
                when new.role = 'admin' then '/admin/dashboard'
                else '/student/dashboard'
            end,
            '{}'::jsonb,
            'account-restored:' || extract(epoch from timezone('utc', now()))::bigint::text
        );
    end if;

    return new;
end;
$$;

create or replace function public.notify_quiz_result_created()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    session_row record;
    student_row record;
begin
    select id, teacher_id, class_id, topic into session_row
    from public.quiz_sessions where id = new.session_id;
    select first_name, last_name into student_row
    from public.profiles where id = new.student_id;

    if session_row.id is null then
        return new;
    end if;

    perform public.create_notification(
        new.student_id,
        'quiz_result_recorded',
        'Quiz result recorded',
        coalesce(session_row.topic, 'Quiz') || ': ' || new.correct_answers::text
            || ' of ' || new.total_questions::text || ' correct.',
        '/student/classes/' || session_row.class_id::text,
        jsonb_build_object(
            'session_id', new.session_id,
            'correct_answers', new.correct_answers,
            'total_questions', new.total_questions,
            'is_counted', new.is_counted
        ),
        'quiz-result-student:' || new.id::text
    );

    perform public.create_notification(
        session_row.teacher_id,
        'quiz_submitted',
        'Student submitted a quiz',
        coalesce(
            nullif(btrim(concat_ws(' ', student_row.first_name, student_row.last_name)), ''),
            'A student'
        ) || ' completed ' || coalesce(session_row.topic, 'a quiz') || '.',
        '/teacher/classes/' || session_row.class_id::text || '/quizzes/' || new.session_id::text || '/results',
        jsonb_build_object('session_id', new.session_id, 'student_id', new.student_id),
        'quiz-result-teacher:' || new.id::text
    );

    return new;
end;
$$;

create or replace function public.notify_quiz_status_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    eligibility_row record;
begin
    if old.status is not distinct from new.status
       or new.status not in ('active', 'completed') then
        return new;
    end if;

    for eligibility_row in
        select student_id
        from public.quiz_session_students
        where session_id = new.id and eligibility_status = 'eligible'
    loop
        perform public.create_notification(
            eligibility_row.student_id,
            case when new.status = 'active' then 'quiz_started' else 'quiz_ended' end,
            case when new.status = 'active' then 'Quiz started' else 'Quiz ended' end,
            coalesce(new.topic, 'Your quiz') || case
                when new.status = 'active' then ' is now available.'
                else ' has ended. Results and answers are now available when permitted.'
            end,
            '/student/classes/' || new.class_id::text,
            jsonb_build_object('session_id', new.id, 'class_id', new.class_id),
            'quiz-status:' || new.id::text || ':' || new.status || ':' || eligibility_row.student_id::text
        );
    end loop;

    return new;
end;
$$;

notify pgrst, 'reload schema';

commit;
