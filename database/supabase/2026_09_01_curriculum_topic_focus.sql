-- Complete Grade 1-6 curriculum map and focused-topic practice sessions.
-- Run after 2026_09_01_autonomous_learning_hub.sql.

begin;

alter table public.practice_sessions
    add column if not exists focus_competency_key text
        check (
            focus_competency_key is null
            or char_length(focus_competency_key) between 1 and 80
        );

alter table public.practice_sessions
    drop constraint if exists practice_sessions_mode_check;

alter table public.practice_sessions
    add constraint practice_sessions_mode_check
        check (mode in ('adventure', 'daily', 'review', 'focus'));

alter table public.practice_sessions
    drop constraint if exists practice_sessions_focus_mode_check;

alter table public.practice_sessions
    add constraint practice_sessions_focus_mode_check
        check (
            (mode = 'focus' and focus_competency_key is not null)
            or (mode <> 'focus' and focus_competency_key is null)
        );

drop index if exists public.practice_sessions_one_active_mode_grade_idx;

create unique index if not exists practice_sessions_one_active_path_grade_idx
    on public.practice_sessions (
        student_id,
        mode,
        grade_level,
        coalesce(focus_competency_key, '')
    )
    where status = 'active';

create index if not exists practice_sessions_focus_topic_idx
    on public.practice_sessions (student_id, grade_level, focus_competency_key)
    where mode = 'focus';

commit;
