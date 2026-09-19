-- Roll back focused-topic sessions and restore the original three modes.
-- Focus-session questions are removed by the practice_questions foreign key.

begin;

delete from public.practice_sessions
where mode = 'focus';

drop index if exists public.practice_sessions_focus_topic_idx;
drop index if exists public.practice_sessions_one_active_path_grade_idx;

alter table public.practice_sessions
    drop constraint if exists practice_sessions_focus_mode_check;

alter table public.practice_sessions
    drop constraint if exists practice_sessions_mode_check;

alter table public.practice_sessions
    drop column if exists focus_competency_key;

alter table public.practice_sessions
    add constraint practice_sessions_mode_check
        check (mode in ('adventure', 'daily', 'review'));

create unique index if not exists practice_sessions_one_active_mode_grade_idx
    on public.practice_sessions (student_id, mode, grade_level)
    where status = 'active';

commit;
