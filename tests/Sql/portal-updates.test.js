import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(new URL('../../database/supabase/2026_09_13_portal_timezone_and_arcade_updates.sql', import.meta.url), 'utf8');
const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const otherTeacher = '33333333-3333-4333-8333-333333333333';
const ownClass = '44444444-4444-4444-8444-444444444444';
const otherClass = '55555555-5555-4555-8555-555555555555';

async function database() {
    const db = new PGlite();
    await db.exec(`
        create role anon; create role authenticated; create role service_role;
        create table profiles (id uuid primary key, role text, first_name text, last_name text, grade_level integer, suspended_at timestamptz);
        create table classes (id uuid primary key, teacher_id uuid, class_name text, grade_level integer, archived_at timestamptz);
        create table class_members (class_id uuid, student_id uuid);
        create table practice_questions (id uuid primary key default gen_random_uuid(), student_id uuid, competency_key text, grade_level integer,
            answered_at timestamptz, is_correct boolean, hints_revealed integer, mastery_after numeric);
        create table practice_mastery (student_id uuid, competency_key text, grade_level integer, mastery_score numeric, attempts integer, correct_answers integer, hints_used integer);
        create table number_guess_scores (student_id uuid primary key, best_score integer, games_played integer);
        create table arcade_scores (student_id uuid, game_key text, best_score integer, best_streak integer, games_played integer, primary key (student_id, game_key));
        create table arcade_achievements (student_id uuid, achievement_key text, metadata jsonb, game_key text, unlocked_at timestamptz default now(), primary key (student_id, achievement_key));
        create table mathverse_schema_migrations (migration_key text primary key, applied_at timestamptz default now());
        insert into profiles values ('${teacher}', 'teacher', 'Nova', 'Teacher', null, null), ('${student}', 'student', 'Mira', 'Learner', 4, null),
            ('${otherTeacher}', 'teacher', 'Other', 'Teacher', null, null);
        insert into classes values ('${ownClass}', '${teacher}', 'Orion', 4, null), ('${otherClass}', '${otherTeacher}', 'Private', 4, null);
        insert into class_members values ('${ownClass}', '${student}');
    `);
    await db.exec(migration);
    return db;
}

test('analytics executes with Philippine-day buckets, correct range edges, and teacher ownership', async () => {
    const db = await database();
    try {
        await db.exec(`
            insert into practice_mastery values ('${student}', 'g4-equivalent-fractions', 4, 40, 3, 2, 1);
            insert into practice_questions (student_id, competency_key, grade_level, answered_at, is_correct, hints_revealed, mastery_after)
            select '${student}', 'g4-equivalent-fractions', 4,
                ((date_trunc('day', now() at time zone 'Asia/Manila') - make_interval(days => offsets.days)) at time zone 'Asia/Manila')
                    + offsets.time_offset, true, 0, 40
            from (values (1, interval '30 minutes'), (1, interval '15 hours'), (6, interval '0 seconds'), (6, interval '-1 second')) offsets(days, time_offset);
        `);
        const analytics = async () => (await db.query('select teacher_learning_hub_analytics($1::uuid, null, 7) as data', [teacher])).rows[0].data;
        const state = await analytics();
        assert.equal(state.timezone, 'Asia/Manila');
        assert.equal(state.summary.answers, 3);
        assert.equal(state.summary.students, 1);
        assert.equal(state.daily_activity.length, 2);
        assert.deepEqual(state.daily_activity.map(day => day.answers), [1, 2]);
        assert.equal(state.students[0].name, 'Mira Learner');
        assert.equal(state.classes[0].class_name, 'Orion');
        await db.exec("set time zone 'Pacific/Honolulu'");
        assert.deepEqual((await analytics()).daily_activity, state.daily_activity);
        await assert.rejects(db.query('select teacher_learning_hub_analytics($1::uuid, $2::uuid, 7)', [teacher, otherClass]), /Class is unavailable/);
        await assert.rejects(db.query('select teacher_learning_hub_analytics($1::uuid, null, 7)', [student]), /Teacher profile unavailable/);
    } finally {
        await db.close();
    }
});

test('retired game is rejected and every remaining question family generates valid challenges', async () => {
    const db = await database();
    try {
        await assert.rejects(db.query("select arcade_generate_question('fraction-comparison', 4, 1)"), /Unsupported arcade game/);
        for (const game of ['mental-arithmetic', 'equation-balance', 'pattern-pulse']) {
            for (let grade = 1; grade <= 6; grade++) {
                for (let sequence = 1; sequence <= 8; sequence++) {
                    const challenge = (await db.query('select arcade_generate_question($1, $2, $3) as data', [game, grade, sequence])).rows[0].data;
                    assert.ok(challenge.prompt.length > 3);
                    assert.match(String(challenge.answer), /^-?\d+$/);
                    assert.ok(challenge.explanation);
                }
            }
        }
        // Applying the forward update twice must be harmless.
        await db.exec(migration);
    } finally {
        await db.close();
    }
});

test('badges count only active games, require four-game mastery, and preserve historical rows', async () => {
    const db = await database();
    try {
        await db.exec(`
            insert into number_guess_scores values ('${student}', 1, 1);
            insert into arcade_scores values ('${student}', 'mental-arithmetic', 1, 1, 1), ('${student}', 'equation-balance', 1, 1, 1),
                ('${student}', 'pattern-pulse', 1, 1, 1), ('${student}', 'fraction-comparison', 500, 500, 100);
            insert into arcade_achievements (student_id, achievement_key, metadata)
                values ('${student}', 'fraction-photon', '{"legacy":true}');
        `);
        const badges = (await db.query('select arcade_refresh_achievements($1::uuid) as data', [student])).rows[0].data;
        const keys = badges.map(badge => badge.key);
        assert.ok(keys.includes('arcade-master'));
        assert.ok(keys.includes('fraction-photon'));
        assert.ok(!keys.includes('score-five'));
        assert.ok(!keys.includes('streak-five'));
        assert.ok(!keys.includes('arcade-veteran'));
        assert.equal((await db.query("select count(*)::integer as count from arcade_scores where game_key = 'fraction-comparison'")).rows[0].count, 1);
        const metadata = (await db.query("select metadata from arcade_achievements where achievement_key = 'fraction-photon'")).rows[0].metadata;
        assert.deepEqual(metadata, { legacy: true });
        const privileges = (await db.query(`select
            has_function_privilege('anon', 'teacher_learning_hub_analytics(uuid,uuid,integer)', 'EXECUTE') as anon_analytics,
            has_function_privilege('authenticated', 'arcade_generate_question(text,integer,integer)', 'EXECUTE') as student_generator,
            has_function_privilege('service_role', 'arcade_generate_question(text,integer,integer)', 'EXECUTE') as server_generator,
            has_function_privilege('service_role', 'arcade_refresh_achievements(uuid)', 'EXECUTE') as server_badge_helper,
            has_function_privilege('service_role', 'teacher_learning_hub_analytics(uuid,uuid,integer)', 'EXECUTE') as server_analytics`)).rows[0];
        assert.deepEqual(privileges, { anon_analytics: false, student_generator: false, server_generator: false, server_badge_helper: false, server_analytics: true });
    } finally {
        await db.close();
    }
});
