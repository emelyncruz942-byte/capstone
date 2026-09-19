-- Remove only the Number Guess game and its stored leaderboard data.

begin;

drop function if exists public.finish_number_guess_game(uuid, uuid);
drop function if exists public.submit_number_guess(uuid, uuid, integer);
drop function if exists public.start_number_guess_game(uuid);
drop function if exists public.number_guess_dashboard(uuid, integer);

drop table if exists public.number_guess_sessions;
drop table if exists public.number_guess_scores;

commit;
