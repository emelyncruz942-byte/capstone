import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const learningHub = readFileSync(
    new URL('../../database/supabase/2026_09_01_autonomous_learning_hub.sql', import.meta.url),
    'utf8'
).replace('create extension if not exists pgcrypto;', '');
const timezoneCorrection = readFileSync(
    new URL('../../database/supabase/2026_09_19_philippine_practice_day_and_insights.sql', import.meta.url),
    'utf8'
);
const soloInsights = readFileSync(
    new URL('../../database/supabase/2026_09_19_teacher_student_learning_hub_insights.sql', import.meta.url),
    'utf8'
);

const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const otherTeacher = '33333333-3333-4333-8333-333333333333';
const otherStudent = '44444444-4444-4444-8444-444444444444';
const classId = '55555555-5555-4555-8555-555555555555';
const otherClass = '66666666-6666-4666-8666-666666666666';
const sessionId = '77777777-7777-4777-8777-777777777777';

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
    await db.exec(timezoneCorrection);
    await db.exec(soloInsights);
    await db.exec(`
        insert into profiles (id, role, first_name, last_name, grade_level)
        values
            ('${teacher}', 'teacher', 'Nova', 'Teacher', null),
            ('${student}', 'student', 'Mira', 'Learner', 4),
            ('${otherTeacher}', 'teacher', 'Other', 'Teacher', null),
            ('${otherStudent}', 'student', 'Outside', 'Learner', 4);

        insert into classes values
            ('${classId}', '${teacher}', 'Orion', 4, null),
            ('${otherClass}', '${otherTeacher}', 'Private', 4, null);
        insert into class_members values
            ('${classId}', '${student}'),
            ('${otherClass}', '${otherStudent}');

        insert into practice_sessions (
            id, student_id, grade_level, mode, status,
            questions_answered, correct_answers
        ) values ('${sessionId}', '${student}', 4, 'daily', 'active', 3, 2);

        insert into practice_mastery (
            student_id, grade_level, competency_key, mastery_score, difficulty,
            attempts, correct_answers, hints_used, last_practiced_at
        ) values
            (
                '${student}', 4, 'g4-equivalent-fractions', 45, 2,
                2, 1, 1, statement_timestamp()
            ),
            (
                '${student}', 4, 'g4-number-patterns', 90, 3,
                1, 1, 0, statement_timestamp()
            );

        insert into practice_questions (
            session_id, student_id, competency_key, difficulty, sequence,
            prompt, answer_type, options, correct_answer, hint_steps, explanation,
            hints_revealed, submitted_answer, is_correct, mastery_after,
            response_ms, answered_at
        ) values
            (
                '${sessionId}', '${student}', 'g4-equivalent-fractions', 2, 1,
                'Previous Philippine day', 'number', '[]', '1', '[]', 'Review',
                1, '0', false, 40, 1200,
                (date_trunc('day', statement_timestamp() at time zone 'Asia/Manila')
                    at time zone 'Asia/Manila') - interval '30 minutes'
            ),
            (
                '${sessionId}', '${student}', 'g4-equivalent-fractions', 2, 2,
                'Current Philippine day', 'number', '[]', '2', '[]', 'Correct',
                0, '2', true, 45, 800,
                (date_trunc('day', statement_timestamp() at time zone 'Asia/Manila')
                    at time zone 'Asia/Manila') + interval '30 minutes'
            ),
            (
                '${sessionId}', '${student}', 'g4-number-patterns', 3, 3,
                'Current pattern', 'number', '[]', '8', '[]', 'Correct',
                0, '8', true, 90, 600,
                (date_trunc('day', statement_timestamp() at time zone 'Asia/Manila')
                    at time zone 'Asia/Manila') + interval '45 minutes'
            );
    `);
    return db;
}

test('solo insights return detailed statistics for an enrolled student', async () => {
    const db = await database();
    try {
        const read = async () => (await db.query(
            'select teacher_learning_hub_student_analytics($1::uuid, $2::uuid, $3::uuid, 7) as data',
            [teacher, student, classId]
        )).rows[0].data;

        const state = await read();
        assert.equal(state.timezone, 'Asia/Manila');
        assert.equal(state.student.name, 'Mira Learner');
        assert.equal(state.classes[0].name, 'Orion');
        assert.equal(state.summary.answers, 3);
        assert.equal(Number(state.summary.accuracy), 66.7);
        assert.equal(Number(state.summary.average_mastery), 67.5);
        assert.equal(state.summary.mastered_topics, 1);
        assert.equal(state.summary.practised_topics, 2);
        assert.equal(state.summary.active_days, 2);
        assert.deepEqual(state.daily_activity.map(day => day.answers), [1, 2]);
        assert.equal(state.topics.length, 2);
        assert.equal(state.recent_activity.length, 3);

        await db.exec("set time zone 'Pacific/Honolulu'");
        assert.deepEqual((await read()).daily_activity, state.daily_activity);
    } finally {
        await db.close();
    }
});

test('solo insights reject students and classes outside the teacher roster', async () => {
    const db = await database();
    try {
        await assert.rejects(
            db.query(
                'select teacher_learning_hub_student_analytics($1::uuid, $2::uuid, null, 7)',
                [teacher, otherStudent]
            ),
            /Student is unavailable/
        );
        await assert.rejects(
            db.query(
                'select teacher_learning_hub_student_analytics($1::uuid, $2::uuid, $3::uuid, 7)',
                [teacher, student, otherClass]
            ),
            /Class is unavailable/
        );
        await assert.rejects(
            db.query(
                'select teacher_learning_hub_student_analytics($1::uuid, $2::uuid, null, 7)',
                [student, student]
            ),
            /Teacher profile unavailable/
        );
    } finally {
        await db.close();
    }
});

test('solo insights remain idempotent and service-role only', async () => {
    const db = await database();
    try {
        await db.exec(soloInsights);
        const applied = await db.query(
            "select count(*)::integer as count from mathverse_schema_migrations where migration_key = '2026_09_19_teacher_student_learning_hub_insights.sql'"
        );
        assert.equal(applied.rows[0].count, 1);

        const privileges = (await db.query(`select
            has_function_privilege(
                'anon',
                'teacher_learning_hub_student_analytics(uuid,uuid,uuid,integer)',
                'EXECUTE'
            ) as anon_execute,
            has_function_privilege(
                'authenticated',
                'teacher_learning_hub_student_analytics(uuid,uuid,uuid,integer)',
                'EXECUTE'
            ) as authenticated_execute,
            has_function_privilege(
                'service_role',
                'teacher_learning_hub_student_analytics(uuid,uuid,uuid,integer)',
                'EXECUTE'
            ) as service_execute
        `)).rows[0];
        assert.deepEqual(privileges, {
            anon_execute: false,
            authenticated_execute: false,
            service_execute: true,
        });
    } finally {
        await db.close();
    }
});

