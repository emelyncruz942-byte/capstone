import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const learningHub = readFileSync(
    new URL('../../database/supabase/2026_09_01_autonomous_learning_hub.sql', import.meta.url),
    'utf8'
).replace('create extension if not exists pgcrypto;', '');
const correction = readFileSync(new URL('../../database/supabase/2026_09_19_philippine_practice_day_and_insights.sql', import.meta.url), 'utf8');

const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const classId = '33333333-3333-4333-8333-333333333333';
const sessionId = '44444444-4444-4444-8444-444444444444';
const beforeMidnightQuestion = '55555555-5555-4555-8555-555555555555';
const afterMidnightQuestion = '66666666-6666-4666-8666-666666666666';
const openQuestion = '77777777-7777-4777-8777-777777777777';

async function database() {
    const db = new PGlite();
    await db.exec(`
        create role anon; create role authenticated; create role service_role;
        create table profiles (
            id uuid primary key, role text, first_name text, last_name text,
            grade_level integer, suspended_at timestamptz
        );
        create table classes (
            id uuid primary key, teacher_id uuid, class_name text,
            grade_level integer, archived_at timestamptz
        );
        create table class_members (class_id uuid, student_id uuid);
        create table mathverse_schema_migrations (
            migration_key text primary key, applied_at timestamptz default now()
        );
    `);
    await db.exec(learningHub);
    await db.exec(correction);
    await db.exec(`
        insert into profiles (id, role, first_name, last_name, grade_level)
        values
            ('${teacher}', 'teacher', 'Nova', 'Teacher', null),
            ('${student}', 'student', 'Mira', 'Learner', 4);

        insert into classes values ('${classId}', '${teacher}', 'Orion', 4, null);
        insert into class_members values ('${classId}', '${student}');

        insert into practice_sessions (
            id, student_id, grade_level, mode, status,
            questions_answered, correct_answers
        ) values ('${sessionId}', '${student}', 4, 'daily', 'active', 2, 1);

        insert into practice_questions (
            id, session_id, student_id, competency_key, difficulty, sequence,
            prompt, answer_type, options, correct_answer, hint_steps, explanation,
            submitted_answer, is_correct, mastery_after, answered_at
        ) values
            (
                '${beforeMidnightQuestion}', '${sessionId}', '${student}',
                'g4-equivalent-fractions', 1, 1, 'Before midnight?', 'number',
                '[]', '1', '[]', 'Previous day', '1', true, 10,
                (date_trunc('day', statement_timestamp() at time zone 'Asia/Manila')
                    at time zone 'Asia/Manila') - interval '1 second'
            ),
            (
                '${afterMidnightQuestion}', '${sessionId}', '${student}',
                'g4-equivalent-fractions', 1, 2, 'After midnight?', 'number',
                '[]', '2', '[]', 'Current day', '2', true, 18,
                (date_trunc('day', statement_timestamp() at time zone 'Asia/Manila')
                    at time zone 'Asia/Manila') + interval '30 minutes'
            ),
            (
                '${openQuestion}', '${sessionId}', '${student}',
                'g4-equivalent-fractions', 1, 3, 'One plus one?', 'number',
                '[]', '2', '[]', 'One plus one is two.', null, null, null, null
            );
    `);
    return db;
}

test('practice answer totals use a server-owned Philippine calendar day', async () => {
    const db = await database();
    try {
        const submitted = (await db.query(
            `select submit_practice_answer(
                $1::uuid, $2::uuid, '2', 500, '2000-01-01T00:00:00Z'::timestamptz
            ) as data`,
            [openQuestion, student]
        )).rows[0].data;

        assert.equal(submitted.daily_answered, 2);
        assert.equal(submitted.correct, true);

        await db.exec("set time zone 'Pacific/Honolulu'");
        const replayed = (await db.query(
            'select submit_practice_answer($1::uuid, $2::uuid, $3, $4, null::timestamptz) as data',
            [openQuestion, student, '2', 500]
        )).rows[0].data;
        assert.equal(replayed.daily_answered, 2);
    } finally {
        await db.close();
    }
});

test('teacher insights group the same records into Philippine dates', async () => {
    const db = await database();
    try {
        const analytics = async () => (await db.query(
            'select teacher_learning_hub_analytics($1::uuid, null, 7) as data',
            [teacher]
        )).rows[0].data;

        const state = await analytics();
        assert.equal(state.timezone, 'Asia/Manila');
        assert.deepEqual(state.daily_activity.map(day => day.answers), [1, 1]);

        await db.exec("set time zone 'Pacific/Honolulu'");
        assert.deepEqual((await analytics()).daily_activity, state.daily_activity);

        await db.exec(correction);
        const applied = await db.query(
            "select count(*)::integer as count from mathverse_schema_migrations where migration_key = '2026_09_19_philippine_practice_day_and_insights.sql'"
        );
        assert.equal(applied.rows[0].count, 1);
    } finally {
        await db.close();
    }
});
