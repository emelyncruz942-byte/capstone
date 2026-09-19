-- MathVerse quiz regression and browser-push hotfix.
-- Run after the August 29 scheduling/governance migration.
-- This file is standalone and does not use rollback archive tables.

begin;

set local search_path = public, extensions;

do $$
begin
    if to_regclass('public.quizzes') is null
       or to_regclass('public.quiz_questions') is null
       or to_regclass('public.quiz_versions') is null then
        raise exception 'Required quiz governance tables are missing. Run the August 29 scheduling/governance migration first.';
    end if;
end
$$;

-- Support the exact shared-library order without requiring an is_verified
-- column: verified first, then uses, rating, and creation date.
create index if not exists quizzes_shared_verified_rank_idx
    on public.quizzes (usage_count desc, rating_average desc, created_at desc)
    where visibility = 'shared' and verified_at is not null;

create index if not exists quizzes_shared_unverified_rank_idx
    on public.quizzes (usage_count desc, rating_average desc, created_at desc)
    where visibility = 'shared' and verified_at is null;

-- Restore an exact snapshot atomically. PostgREST returns the real database
-- error to Laravel when validation or authorization fails.
create or replace function public.restore_quiz_version(
    p_quiz_id uuid,
    p_version integer,
    p_actor_id uuid
)
returns table (quiz_id uuid, restored_version integer)
language plpgsql
security definer
set search_path = public
as $$
declare
    current_quiz public.quizzes%rowtype;
    target_version public.quiz_versions%rowtype;
    actor_role text;
    owner_role text;
begin
    select * into current_quiz
    from public.quizzes
    where id = p_quiz_id
    for update;

    select role into actor_role
    from public.profiles
    where id = p_actor_id;

    if current_quiz.id is null
       or actor_role is null
       or (
            current_quiz.teacher_id is distinct from p_actor_id
            and not (actor_role = 'admin' and current_quiz.visibility = 'shared')
       ) then
        raise exception 'Quiz restore is not permitted';
    end if;

    select * into target_version
    from public.quiz_versions
    where quiz_versions.quiz_id = p_quiz_id
      and quiz_versions.version = p_version
    for update;

    if target_version.id is null then
        raise exception 'Quiz version not found';
    end if;

    select role into owner_role
    from public.profiles
    where id = current_quiz.teacher_id;

    update public.quizzes
    set topic = target_version.topic,
        grade_level = target_version.grade_level,
        visibility = case
            when current_quiz.teacher_id is distinct from p_actor_id
                then current_quiz.visibility
            else target_version.visibility
        end,
        version = p_version,
        verified_at = case
            when owner_role = 'admin' then timezone('utc', now())
            else null
        end,
        verified_by = case
            when owner_role = 'admin' then p_actor_id
            else null
        end,
        updated_at = timezone('utc', now())
    where id = p_quiz_id;

    delete from public.quiz_questions
    where quiz_questions.quiz_id = p_quiz_id;

    insert into public.quiz_questions (
        quiz_id, position, grade, type, question,
        choice1, choice2, choice3, choice4, choice5, choice6,
        correct_answer
    )
    select
        p_quiz_id,
        coalesce(nullif(item.question_data ->> 'position', '')::integer, item.ordinality::integer),
        target_version.grade_level,
        coalesce(nullif(item.question_data ->> 'type', ''), 'multiple_choice'),
        item.question_data ->> 'question',
        item.question_data ->> 'choice1',
        item.question_data ->> 'choice2',
        item.question_data ->> 'choice3',
        item.question_data ->> 'choice4',
        item.question_data ->> 'choice5',
        item.question_data ->> 'choice6',
        coalesce(item.question_data ->> 'correct_answer', '0')
    from jsonb_array_elements(target_version.questions)
        with ordinality as item(question_data, ordinality)
    order by item.ordinality;

    delete from public.quiz_versions
    where quiz_versions.quiz_id = p_quiz_id
      and quiz_versions.version > p_version;

    return query select p_quiz_id, p_version;
end;
$$;

revoke all on function public.restore_quiz_version(uuid, integer, uuid)
from public, anon, authenticated;
grant execute on function public.restore_quiz_version(uuid, integer, uuid)
to service_role;

-- Browser push subscriptions are server-managed. Authenticated clients cannot
-- read another administrator's endpoint or encryption keys.
create table if not exists public.push_subscriptions (
    id uuid primary key default uuid_generate_v4(),
    user_id uuid not null references public.profiles(id) on delete cascade,
    endpoint text not null unique,
    p256dh text not null,
    auth text not null,
    user_agent text,
    created_at timestamp with time zone not null default timezone('utc', now()),
    updated_at timestamp with time zone not null default timezone('utc', now())
);

create index if not exists push_subscriptions_user_idx
    on public.push_subscriptions (user_id, updated_at desc);

alter table public.push_subscriptions enable row level security;
revoke all on table public.push_subscriptions from anon, authenticated;
grant all on table public.push_subscriptions to service_role;

notify pgrst, 'reload schema';

commit;
