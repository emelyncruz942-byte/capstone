begin;

drop index if exists public.push_subscriptions_user_idx;
drop table if exists public.push_subscriptions;

drop index if exists public.quizzes_shared_verified_rank_idx;
drop index if exists public.quizzes_shared_unverified_rank_idx;

-- restore_quiz_version is retained because it belongs to the August 29
-- governance feature rather than data owned by this hotfix.

commit;
