-- Durable in-app notifications, upcoming quiz reminders, and profile email
-- synchronization for Supabase Auth security changes.
-- Run after every 2026_08_30 migration in database/supabase/README.md.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.profiles') is null
       or to_regclass('public.classes') is null
       or to_regclass('public.class_members') is null
       or to_regclass('public.quizzes') is null
       or to_regclass('public.quiz_sessions') is null
       or to_regclass('public.quiz_session_students') is null
       or to_regclass('public.quiz_results') is null
       or to_regclass('public.quiz_reports') is null then
        raise exception 'Required MathVerse tables are missing. Run the earlier migrations first.';
    end if;
end
$$;

create table if not exists public.notifications (
    id uuid primary key default uuid_generate_v4(),
    user_id uuid not null references public.profiles(id) on delete cascade,
    type text not null check (char_length(type) between 1 and 80),
    title text not null check (char_length(title) between 1 and 140),
    message text not null check (char_length(message) between 1 and 600),
    action_url text check (
        action_url is null
        or (
            char_length(action_url) between 1 and 500
            and action_url like '/%'
            and action_url not like '//%'
        )
    ),
    data jsonb not null default '{}'::jsonb,
    dedupe_key text,
    read_at timestamp with time zone,
    created_at timestamp with time zone not null default timezone('utc', now())
);

create index if not exists notifications_user_created_idx
    on public.notifications (user_id, created_at desc);
create index if not exists notifications_user_unread_idx
    on public.notifications (user_id, created_at desc)
    where read_at is null;
create unique index if not exists notifications_user_dedupe_idx
    on public.notifications (user_id, dedupe_key)
    where dedupe_key is not null;

alter table public.notifications enable row level security;

drop policy if exists notifications_select_own on public.notifications;
create policy notifications_select_own
on public.notifications for select
to authenticated
using (auth.uid() = user_id);

drop policy if exists notifications_update_own on public.notifications;
create policy notifications_update_own
on public.notifications for update
to authenticated
using (auth.uid() = user_id)
with check (auth.uid() = user_id);

revoke all on public.notifications from public, anon;
grant select on public.notifications to authenticated;
grant update (read_at) on public.notifications to authenticated;
grant all on public.notifications to service_role;

create or replace function public.create_notification(
    p_user_id uuid,
    p_type text,
    p_title text,
    p_message text,
    p_action_url text default null,
    p_data jsonb default '{}'::jsonb,
    p_dedupe_key text default null
)
returns uuid
language plpgsql
security definer
set search_path = public
as $$
declare
    notification_id uuid;
begin
    if p_user_id is null
       or not exists (select 1 from public.profiles where id = p_user_id) then
        return null;
    end if;

    insert into public.notifications (
        user_id, type, title, message, action_url, data, dedupe_key
    ) values (
        p_user_id,
        left(nullif(btrim(p_type), ''), 80),
        left(nullif(btrim(p_title), ''), 140),
        left(nullif(btrim(p_message), ''), 600),
        case when p_action_url like '/%' then left(p_action_url, 500) else null end,
        coalesce(p_data, '{}'::jsonb),
        nullif(left(btrim(p_dedupe_key), 240), '')
    )
    on conflict do nothing
    returning id into notification_id;

    return notification_id;
end;
$$;

revoke all on function public.create_notification(uuid, text, text, text, text, jsonb, text)
from public, anon, authenticated;
grant execute on function public.create_notification(uuid, text, text, text, text, jsonb, text)
to service_role;

create or replace function public.quiz_management_url(p_user_id uuid)
returns text
language sql
stable
security definer
set search_path = public
as $$
    select case
        when role = 'admin' then '/admin/quizzes'
        else '/teacher/quizzes'
    end
    from public.profiles
    where id = p_user_id
$$;

revoke all on function public.quiz_management_url(uuid)
from public, anon, authenticated;

create or replace function public.notify_all_admins(
    p_type text,
    p_title text,
    p_message text,
    p_action_url text,
    p_data jsonb,
    p_dedupe_key text
)
returns void
language plpgsql
security definer
set search_path = public
as $$
declare
    admin_row record;
begin
    for admin_row in
        select id from public.profiles
        where role = 'admin' and suspended_at is null
    loop
        perform public.create_notification(
            admin_row.id,
            p_type,
            p_title,
            p_message,
            p_action_url,
            p_data,
            p_dedupe_key
        );
    end loop;
end;
$$;

revoke all on function public.notify_all_admins(text, text, text, text, jsonb, text)
from public, anon, authenticated;

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

drop trigger if exists profiles_notify_created on public.profiles;
create trigger profiles_notify_created
after insert on public.profiles
for each row execute function public.notify_profile_created();

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

drop trigger if exists profiles_notify_state_changed on public.profiles;
create trigger profiles_notify_state_changed
after update of role, suspended_at on public.profiles
for each row execute function public.notify_profile_state_changed();

create or replace function public.notify_class_membership_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    class_row record;
    student_row record;
    target_student_id uuid;
begin
    target_student_id := case when tg_op = 'DELETE' then old.student_id else new.student_id end;

    select id, teacher_id, class_name
    into class_row
    from public.classes
    where id = case when tg_op = 'DELETE' then old.class_id else new.class_id end;

    if class_row.id is null then
        return case when tg_op = 'DELETE' then old else new end;
    end if;

    select first_name, last_name into student_row
    from public.profiles where id = target_student_id;

    if tg_op = 'INSERT' then
        perform public.create_notification(
            class_row.teacher_id,
            'student_joined_class',
            'New student joined',
            coalesce(
                nullif(btrim(concat_ws(' ', student_row.first_name, student_row.last_name)), ''),
                'A student'
            ) || ' joined ' || class_row.class_name || '.',
            '/teacher/classes/' || class_row.id::text,
            jsonb_build_object('class_id', class_row.id, 'student_id', target_student_id),
            'student-joined:' || class_row.id::text || ':' || target_student_id::text || ':'
                || extract(epoch from timezone('utc', now()))::bigint::text
        );
        perform public.create_notification(
            target_student_id,
            'class_joined',
            'Class joined',
            'You joined ' || class_row.class_name || '.',
            '/student/classes/' || class_row.id::text,
            jsonb_build_object('class_id', class_row.id),
            'class-joined:' || class_row.id::text || ':' || target_student_id::text || ':'
                || extract(epoch from timezone('utc', now()))::bigint::text
        );
    else
        perform public.create_notification(
            target_student_id,
            'removed_from_class',
            'Removed from class',
            'You were removed from ' || class_row.class_name || '.',
            '/student/dashboard?section=class',
            jsonb_build_object('class_id', class_row.id),
            'class-removed:' || class_row.id::text || ':' || target_student_id::text || ':'
                || extract(epoch from timezone('utc', now()))::bigint::text
        );
    end if;

    return case when tg_op = 'DELETE' then old else new end;
end;
$$;

drop trigger if exists class_members_notify_change on public.class_members;
create trigger class_members_notify_change
after insert or delete on public.class_members
for each row execute function public.notify_class_membership_changed();

create or replace function public.notify_class_archive_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    member_row record;
    was_archived boolean;
begin
    if old.archived_at is not distinct from new.archived_at then
        return new;
    end if;

    was_archived := new.archived_at is not null;
    for member_row in
        select student_id from public.class_members where class_id = new.id
    loop
        perform public.create_notification(
            member_row.student_id,
            case when was_archived then 'class_archived' else 'class_restored' end,
            case when was_archived then 'Class archived' else 'Class restored' end,
            new.class_name || case
                when was_archived then ' was archived by your teacher.'
                else ' is active again.'
            end,
            case
                when was_archived then '/student/dashboard?section=class'
                else '/student/classes/' || new.id::text
            end,
            jsonb_build_object('class_id', new.id),
            'class-archive-state:' || new.id::text || ':' || coalesce(new.archived_at::text, 'active')
        );
    end loop;

    return new;
end;
$$;

drop trigger if exists classes_notify_archive_change on public.classes;
create trigger classes_notify_archive_change
after update of archived_at on public.classes
for each row execute function public.notify_class_archive_changed();

create or replace function public.notify_quiz_eligibility_created()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    session_row record;
    class_name text;
begin
    select id, class_id, topic, status, available_at, due_at
    into session_row
    from public.quiz_sessions
    where id = new.session_id;

    if session_row.id is null or new.eligibility_status <> 'eligible' then
        return new;
    end if;

    select classes.class_name into class_name
    from public.classes where id = session_row.class_id;

    perform public.create_notification(
        new.student_id,
        case when session_row.status = 'active' then 'quiz_started' else 'quiz_assigned' end,
        case when session_row.status = 'active' then 'Quiz assigned and available' else 'New quiz assigned' end,
        coalesce(session_row.topic, 'A quiz') || case
            when session_row.status = 'active' then ' is available now in '
            else ' was assigned in '
        end || coalesce(class_name, 'your class') || '.',
        '/student/classes/' || session_row.class_id::text,
        jsonb_build_object(
            'session_id', session_row.id,
            'class_id', session_row.class_id,
            'available_at', session_row.available_at,
            'due_at', session_row.due_at
        ),
        'quiz-assigned:' || session_row.id::text || ':' || new.student_id::text
    );

    return new;
end;
$$;

drop trigger if exists quiz_session_students_notify_created on public.quiz_session_students;
create trigger quiz_session_students_notify_created
after insert on public.quiz_session_students
for each row execute function public.notify_quiz_eligibility_created();

create or replace function public.notify_quiz_eligibility_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    session_row record;
begin
    select id, class_id, topic into session_row
    from public.quiz_sessions where id = new.session_id;

    if session_row.id is null then
        return new;
    end if;

    if new.eligibility_status = 'excused'
       and old.eligibility_status is distinct from new.eligibility_status then
        perform public.create_notification(
            new.student_id,
            'quiz_excused',
            'Quiz absence excused',
            'Your teacher excused you from ' || coalesce(session_row.topic, 'a quiz') || '.',
            '/student/classes/' || session_row.class_id::text,
            jsonb_build_object('session_id', new.session_id, 'reason', new.excuse_reason),
            'quiz-excused:' || new.session_id::text || ':' || new.student_id::text
        );
    elsif new.eligibility_status = 'eligible'
       and (
           new.allowed_attempts > old.allowed_attempts
           or old.eligibility_status is distinct from new.eligibility_status
       ) then
        perform public.create_notification(
            new.student_id,
            'quiz_retake_granted',
            'Quiz retake granted',
            'You can attempt ' || coalesce(session_row.topic, 'the quiz') || ' again.',
            '/student/classes/' || session_row.class_id::text,
            jsonb_build_object(
                'session_id', new.session_id,
                'allowed_attempts', new.allowed_attempts,
                'retake_due_at', new.retake_due_at
            ),
            'quiz-retake:' || new.session_id::text || ':' || new.student_id::text || ':'
                || new.allowed_attempts::text
        );
    end if;

    return new;
end;
$$;

drop trigger if exists quiz_session_students_notify_changed on public.quiz_session_students;
create trigger quiz_session_students_notify_changed
after update of eligibility_status, allowed_attempts, retake_due_at
on public.quiz_session_students
for each row execute function public.notify_quiz_eligibility_changed();

create or replace function public.notify_quiz_session_created()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    source_quiz record;
    assigning_teacher record;
begin
    if new.source_quiz_id is null then
        return new;
    end if;

    select id, teacher_id, topic into source_quiz
    from public.quizzes where id = new.source_quiz_id;

    if source_quiz.id is null or source_quiz.teacher_id = new.teacher_id then
        return new;
    end if;

    select first_name, last_name into assigning_teacher
    from public.profiles where id = new.teacher_id;

    perform public.create_notification(
        source_quiz.teacher_id,
        'shared_quiz_used',
        'Your shared quiz was used',
        coalesce(
            nullif(btrim(concat_ws(' ', assigning_teacher.first_name, assigning_teacher.last_name)), ''),
            'A teacher'
        ) || ' assigned ' || coalesce(source_quiz.topic, 'your quiz') || ' to a class.',
        public.quiz_management_url(source_quiz.teacher_id),
        jsonb_build_object('quiz_id', source_quiz.id, 'session_id', new.id),
        'shared-quiz-used:' || new.id::text
    );

    return new;
end;
$$;

drop trigger if exists quiz_sessions_notify_created on public.quiz_sessions;
create trigger quiz_sessions_notify_created
after insert on public.quiz_sessions
for each row execute function public.notify_quiz_session_created();

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

drop trigger if exists quiz_sessions_notify_status on public.quiz_sessions;
create trigger quiz_sessions_notify_status
after update of status on public.quiz_sessions
for each row execute function public.notify_quiz_status_changed();

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

drop trigger if exists quiz_results_notify_created on public.quiz_results;
create trigger quiz_results_notify_created
after insert on public.quiz_results
for each row execute function public.notify_quiz_result_created();

create or replace function public.notify_quiz_report_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
    report_topic text;
begin
    report_topic := coalesce(new.quiz_topic, 'A shared quiz');

    if tg_op = 'INSERT' then
        perform public.notify_all_admins(
            'quiz_report_submitted',
            'New quiz report',
            report_topic || ' was reported and needs moderation.',
            '/admin/quiz-reports/' || new.id::text,
            jsonb_build_object('report_id', new.id, 'quiz_id', new.quiz_id),
            'quiz-report-admin:' || new.id::text
        );
        perform public.create_notification(
            new.quiz_creator_id,
            'quiz_reported',
            'Your shared quiz was reported',
            report_topic || ' was submitted for administrator review.',
            public.quiz_management_url(new.quiz_creator_id),
            jsonb_build_object('report_id', new.id, 'quiz_id', new.quiz_id),
            'quiz-report-creator:' || new.id::text
        );
    elsif old.status is distinct from new.status and new.status in ('reviewed', 'dismissed') then
        perform public.create_notification(
            new.reporter_id,
            'quiz_report_resolved',
            'Quiz report ' || new.status,
            'Your report for ' || report_topic || ' was ' || new.status || ' by an administrator.',
            '/teacher/quiz-library',
            jsonb_build_object('report_id', new.id, 'status', new.status),
            'quiz-report-resolution:' || new.id::text || ':' || new.status || ':reporter'
        );
        perform public.create_notification(
            new.quiz_creator_id,
            'quiz_report_resolved',
            'Quiz report ' || new.status,
            'The administrator ' || new.status || ' a report for ' || report_topic || '.',
            public.quiz_management_url(new.quiz_creator_id),
            jsonb_build_object('report_id', new.id, 'status', new.status),
            'quiz-report-resolution:' || new.id::text || ':' || new.status || ':creator'
        );
    end if;

    return new;
end;
$$;

drop trigger if exists quiz_reports_notify_change on public.quiz_reports;
create trigger quiz_reports_notify_change
after insert or update of status on public.quiz_reports
for each row execute function public.notify_quiz_report_changed();

create or replace function public.notify_quiz_verification_changed()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if old.verified_at is distinct from new.verified_at then
        perform public.create_notification(
            new.teacher_id,
            'quiz_verification_changed',
            case when new.verified_at is null then 'Quiz verification removed' else 'Quiz verified' end,
            coalesce(new.topic, 'Your shared quiz') || case
                when new.verified_at is null then ' is no longer marked as verified.'
                else ' was verified by an administrator.'
            end,
            public.quiz_management_url(new.teacher_id),
            jsonb_build_object('quiz_id', new.id, 'verified', new.verified_at is not null),
            'quiz-verification:' || new.id::text || ':' || coalesce(new.verified_at::text, 'removed')
        );
    end if;

    return new;
end;
$$;

drop trigger if exists quizzes_notify_verification on public.quizzes;
create trigger quizzes_notify_verification
after update of verified_at on public.quizzes
for each row execute function public.notify_quiz_verification_changed();

drop function if exists public.generate_upcoming_quiz_notifications();
create or replace function public.generate_upcoming_quiz_notifications(
    p_user_id uuid default null
)
returns table (created_count integer)
language plpgsql
security definer
set search_path = public
as $$
declare
    assignment_row record;
    notification_id uuid;
    inserted_count integer := 0;
begin
    for assignment_row in
        select
            sessions.id,
            sessions.class_id,
            sessions.topic,
            sessions.status,
            sessions.available_at,
            sessions.due_at,
            eligibility.student_id
        from public.quiz_sessions sessions
        join public.quiz_session_students eligibility
          on eligibility.session_id = sessions.id
         and eligibility.eligibility_status = 'eligible'
        where sessions.status in ('waiting', 'active')
          and (p_user_id is null or eligibility.student_id = p_user_id)
          and not exists (
              select 1
              from public.quiz_results results
              where results.session_id = sessions.id
                and results.student_id = eligibility.student_id
                and results.is_counted = true
          )
          and (
              sessions.available_at between timezone('utc', now()) and timezone('utc', now()) + interval '5 minutes'
              or sessions.due_at between timezone('utc', now()) and timezone('utc', now()) + interval '30 minutes'
          )
    loop
        if assignment_row.status = 'waiting'
           and assignment_row.available_at between timezone('utc', now()) and timezone('utc', now()) + interval '5 minutes' then
            notification_id := public.create_notification(
                assignment_row.student_id,
                'quiz_starts_soon',
                'Quiz starts within 5 minutes',
                coalesce(assignment_row.topic, 'A quiz') || ' will become available in 5 minutes or less.',
                '/student/classes/' || assignment_row.class_id::text,
                jsonb_build_object('session_id', assignment_row.id, 'available_at', assignment_row.available_at),
                'quiz-starts-soon:' || assignment_row.id::text || ':' || assignment_row.student_id::text
                    || ':' || assignment_row.available_at::text
            );
            if notification_id is not null then inserted_count := inserted_count + 1; end if;
        end if;

        if assignment_row.due_at between timezone('utc', now()) and timezone('utc', now()) + interval '30 minutes' then
            notification_id := public.create_notification(
                assignment_row.student_id,
                'quiz_due_soon',
                'Quiz due within 30 minutes',
                coalesce(assignment_row.topic, 'A quiz') || ' is due in 30 minutes or less.',
                '/student/classes/' || assignment_row.class_id::text,
                jsonb_build_object('session_id', assignment_row.id, 'due_at', assignment_row.due_at),
                'quiz-due-soon:' || assignment_row.id::text || ':' || assignment_row.student_id::text
                    || ':' || assignment_row.due_at::text
            );
            if notification_id is not null then inserted_count := inserted_count + 1; end if;
        end if;
    end loop;

    created_count := inserted_count;
    return next;
end;
$$;

revoke all on function public.generate_upcoming_quiz_notifications(uuid)
from public, anon, authenticated;
grant execute on function public.generate_upcoming_quiz_notifications(uuid)
to service_role;

-- Keep public.profiles.email synchronized only after Supabase has completed an
-- email change. Password and completed email changes also become in-app
-- security alerts; Supabase remains responsible for sending the email alerts.
create or replace function public.handle_auth_security_change()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
    if old.email is distinct from new.email then
        update public.profiles set email = new.email where id = new.id;
        perform public.create_notification(
            new.id,
            'email_changed',
            'Email address changed',
            'Your sign-in email address was changed successfully.',
            case
                when exists (select 1 from public.profiles where id = new.id and role = 'teacher') then '/teacher/dashboard?section=security'
                when exists (select 1 from public.profiles where id = new.id and role = 'admin') then '/admin/dashboard?section=security'
                else '/student/dashboard?section=security'
            end,
            jsonb_build_object('old_email', old.email, 'new_email', new.email),
            'auth-email-changed:' || new.updated_at::text
        );
    end if;

    if old.encrypted_password is distinct from new.encrypted_password then
        perform public.create_notification(
            new.id,
            'password_changed',
            'Password changed',
            'Your MathVerse password was changed successfully.',
            case
                when exists (select 1 from public.profiles where id = new.id and role = 'teacher') then '/teacher/dashboard?section=security'
                when exists (select 1 from public.profiles where id = new.id and role = 'admin') then '/admin/dashboard?section=security'
                else '/student/dashboard?section=security'
            end,
            '{}'::jsonb,
            'auth-password-changed:' || new.updated_at::text
        );
    end if;

    return new;
end;
$$;

drop trigger if exists auth_users_mathverse_security_change on auth.users;
create trigger auth_users_mathverse_security_change
after update of email, encrypted_password on auth.users
for each row execute function public.handle_auth_security_change();

notify pgrst, 'reload schema';

commit;
