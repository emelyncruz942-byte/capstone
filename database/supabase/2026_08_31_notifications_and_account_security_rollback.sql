-- Roll back the August 31 notification and account-security database changes.

begin;

drop trigger if exists auth_users_mathverse_security_change on auth.users;
drop function if exists public.handle_auth_security_change();

drop trigger if exists quizzes_notify_verification on public.quizzes;
drop trigger if exists quiz_reports_notify_change on public.quiz_reports;
drop trigger if exists quiz_results_notify_created on public.quiz_results;
drop trigger if exists quiz_sessions_notify_status on public.quiz_sessions;
drop trigger if exists quiz_sessions_notify_created on public.quiz_sessions;
drop trigger if exists quiz_session_students_notify_changed on public.quiz_session_students;
drop trigger if exists quiz_session_students_notify_created on public.quiz_session_students;
drop trigger if exists classes_notify_archive_change on public.classes;
drop trigger if exists class_members_notify_change on public.class_members;
drop trigger if exists profiles_notify_state_changed on public.profiles;
drop trigger if exists profiles_notify_created on public.profiles;

drop function if exists public.generate_upcoming_quiz_notifications(uuid);
drop function if exists public.generate_upcoming_quiz_notifications();
drop function if exists public.notify_quiz_verification_changed();
drop function if exists public.notify_quiz_report_changed();
drop function if exists public.notify_quiz_result_created();
drop function if exists public.notify_quiz_status_changed();
drop function if exists public.notify_quiz_session_created();
drop function if exists public.notify_quiz_eligibility_changed();
drop function if exists public.notify_quiz_eligibility_created();
drop function if exists public.notify_class_archive_changed();
drop function if exists public.notify_class_membership_changed();
drop function if exists public.notify_profile_state_changed();
drop function if exists public.notify_profile_created();
drop function if exists public.notify_all_admins(text, text, text, text, jsonb, text);
drop function if exists public.quiz_management_url(uuid);
drop function if exists public.create_notification(uuid, text, text, text, text, jsonb, text);

drop table if exists public.notifications;

notify pgrst, 'reload schema';

commit;
