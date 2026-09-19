-- Roll back only the autonomous Learning Hub / Practice Arena.
-- Student profile XP, points, level, and trophies are retained because those
-- columns predate this feature in existing MathVerse projects.

drop function if exists public.submit_practice_answer(uuid, uuid, text, integer, timestamptz);
drop function if exists public.reveal_practice_hint(uuid, uuid);

drop table if exists public.practice_questions;
drop table if exists public.practice_mastery;
drop table if exists public.practice_sessions;
