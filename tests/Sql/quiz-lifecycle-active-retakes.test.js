import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(
    new URL('../../database/supabase/2026_09_24_quiz_lifecycle_and_active_retakes.sql', import.meta.url),
    'utf8',
);

const teacher = '11111111-1111-4111-8111-111111111111';
const otherTeacher = '12222222-2222-4222-8222-222222222222';
const student = '22222222-2222-4222-8222-222222222222';
const unfinishedStudent = '23333333-3333-4333-8333-333333333333';
const classId = '33333333-3333-4333-8333-333333333333';
const sessionId = '44444444-4444-4444-8444-444444444444';

async function database(t) {
    const db = new PGlite();
    t.after(() => db.close());
    await db.exec("set time zone 'UTC'");
    await db.exec(`
        create role anon;
        create role authenticated;
        create role service_role;
        create schema auth;
        create function auth.uid() returns uuid language sql stable as $$
            select nullif(current_setting('request.jwt.claim.sub', true), '')::uuid
        $$;

        create table mathverse_schema_migrations (
            migration_key text primary key,
            applied_at timestamptz not null default now()
        );
        insert into mathverse_schema_migrations (migration_key)
        values ('2026_09_23_vr_retake_score_repair.sql');

        create table profiles (
            id uuid primary key,
            role text not null,
            suspended_at timestamptz,
            deactivated_at timestamptz
        );
        create table classes (
            id uuid primary key,
            teacher_id uuid not null references profiles(id),
            archived_at timestamptz,
            deleted_at timestamptz
        );
        create table quiz_sessions (
            id uuid primary key,
            teacher_id uuid not null references profiles(id),
            class_id uuid not null references classes(id),
            topic text not null default 'Fractions',
            status text not null default 'waiting',
            is_active boolean not null default false,
            retake_mode boolean not null default false,
            available_at timestamptz,
            due_at timestamptz default now() + interval '1 day',
            started_at timestamptz,
            ended_at timestamptz
        );
        create table quiz_session_students (
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            eligibility_status text not null default 'eligible',
            allowed_attempts integer not null default 1,
            last_retake_granted_at timestamptz,
            last_retake_granted_by uuid,
            retake_due_at timestamptz,
            retake_reason text,
            excused_at timestamptz,
            excused_by uuid,
            excuse_reason text,
            primary key (session_id, student_id)
        );
        create table quiz_participants (
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            primary key (session_id, student_id)
        );
        create table quiz_results (
            id uuid primary key,
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            correct_answers integer not null,
            total_questions integer not null,
            created_at timestamptz not null default clock_timestamp(),
            attempt_number integer not null default 1,
            is_counted boolean not null default true
        );

        create function get_vr_quiz_score_slot(uuid, uuid)
        returns table (
            can_submit boolean,
            status text,
            attempt_number integer,
            retake_granted_at text,
            message text
        ) language sql security definer as $$
            select false, 'unmigrated'::text, 0, ''::text, 'unmigrated'::text
        $$;

        insert into profiles (id, role) values
            ('${teacher}', 'teacher'),
            ('${otherTeacher}', 'teacher'),
            ('${student}', 'student'),
            ('${unfinishedStudent}', 'student');
        insert into classes (id, teacher_id) values ('${classId}', '${teacher}');
        insert into quiz_sessions (id, teacher_id, class_id)
        values ('${sessionId}', '${teacher}', '${classId}');
        insert into quiz_session_students (session_id, student_id) values
            ('${sessionId}', '${student}'),
            ('${sessionId}', '${unfinishedStudent}');
        insert into quiz_participants (session_id, student_id) values
            ('${sessionId}', '${student}'),
            ('${sessionId}', '${unfinishedStudent}');
    `);
    await db.exec(migration);
    return db;
}

async function rpc(db, name, values) {
    await db.exec('set role service_role');
    try {
        const placeholders = values.map((_, index) => `$${index + 1}`).join(',');
        return (await db.query(`select * from public.${name}(${placeholders})`, values)).rows[0];
    } finally {
        await db.exec('reset role');
    }
}

test('manual start and end transitions are ownership-scoped and idempotent', async (t) => {
    const db = await database(t);

    const started = await rpc(db, 'transition_quiz_session', [sessionId, classId, teacher, 'start']);
    assert.equal(started.outcome_code, 'started');
    assert.equal(started.changed, true);
    assert.equal(started.session_status, 'active');

    const repeatedStart = await rpc(db, 'transition_quiz_session', [sessionId, classId, teacher, 'start']);
    assert.equal(repeatedStart.outcome_code, 'already_active');
    assert.equal(repeatedStart.changed, false);

    await assert.rejects(
        rpc(db, 'transition_quiz_session', [sessionId, classId, otherTeacher, 'end']),
        /Quiz assignment not found for this teacher and class/,
    );

    const ended = await rpc(db, 'transition_quiz_session', [sessionId, classId, teacher, 'end']);
    assert.equal(ended.outcome_code, 'ended');
    assert.equal(ended.changed, true);
    assert.equal(ended.session_status, 'completed');

    const repeatedEnd = await rpc(db, 'transition_quiz_session', [sessionId, classId, teacher, 'end']);
    assert.equal(repeatedEnd.outcome_code, 'already_completed');
    assert.equal(repeatedEnd.changed, false);
});

test('blank lifecycle actions are rejected without changing the assignment', async (t) => {
    const db = await database(t);

    await assert.rejects(
        rpc(db, 'transition_quiz_session', [sessionId, classId, teacher, null]),
        /Unsupported quiz lifecycle action/,
    );

    const assignment = (await db.query(
        'select status, is_active from quiz_sessions where id = $1',
        [sessionId],
    )).rows[0];
    assert.deepEqual(assignment, { status: 'waiting', is_active: false });
});

test('active retake is per student and leaves unfinished classmates able to start', async (t) => {
    const db = await database(t);
    await rpc(db, 'transition_quiz_session', [sessionId, classId, teacher, 'start']);

    await assert.rejects(
        rpc(db, 'grant_quiz_retake', [
            sessionId,
            unfinishedStudent,
            teacher,
            'Requested before finishing.',
            null,
        ]),
        /finish the active quiz/,
    );

    await db.query(
        `insert into quiz_results
            (id, session_id, student_id, correct_answers, total_questions)
         values ($1, $2, $3, 4, 5)`,
        [randomUUID(), sessionId, student],
    );

    const retake = await rpc(db, 'grant_quiz_retake', [
        sessionId,
        student,
        teacher,
        'Connection interruption.',
        null,
    ]);
    assert.equal(retake.new_allowed_attempts, 2);

    const session = (await db.query(
        'select status, is_active, retake_mode from quiz_sessions where id = $1',
        [sessionId],
    )).rows[0];
    assert.deepEqual(session, { status: 'active', is_active: true, retake_mode: false });

    const finishedSlot = await rpc(db, 'get_vr_quiz_score_slot', [sessionId, student]);
    assert.equal(finishedSlot.can_submit, true);
    assert.equal(finishedSlot.attempt_number, 2);

    const unfinishedSlot = await rpc(db, 'get_vr_quiz_score_slot', [sessionId, unfinishedStudent]);
    assert.equal(unfinishedSlot.can_submit, true);
    assert.equal(unfinishedSlot.attempt_number, 1);

    await assert.rejects(
        rpc(db, 'grant_quiz_retake', [sessionId, student, teacher, 'Duplicate grant.', null]),
        /already has an unused retake/,
    );
});

test('retake of a completed assignment reopens global retake mode', async (t) => {
    const db = await database(t);
    await db.exec(`
        update quiz_sessions
        set status = 'completed', is_active = false, ended_at = now()
        where id = '${sessionId}';
        insert into quiz_results
            (id, session_id, student_id, correct_answers, total_questions)
        values
            ('${randomUUID()}', '${sessionId}', '${student}', 3, 5);
    `);

    await rpc(db, 'grant_quiz_retake', [
        sessionId,
        student,
        teacher,
        'Teacher-approved retry.',
        null,
    ]);

    const session = (await db.query(
        'select status, is_active, retake_mode from quiz_sessions where id = $1',
        [sessionId],
    )).rows[0];
    assert.deepEqual(session, { status: 'active', is_active: true, retake_mode: true });

    const unfinishedSlot = await rpc(db, 'get_vr_quiz_score_slot', [sessionId, unfinishedStudent]);
    assert.equal(unfinishedSlot.can_submit, false);
    assert.equal(unfinishedSlot.status, 'retake_required');
});

test('an archived class cannot be reopened through a retake grant', async (t) => {
    const db = await database(t);
    await db.exec(`
        update classes set archived_at = now() where id = '${classId}';
        update quiz_sessions
        set status = 'completed', is_active = false, ended_at = now()
        where id = '${sessionId}';
        insert into quiz_results
            (id, session_id, student_id, correct_answers, total_questions)
        values
            ('${randomUUID()}', '${sessionId}', '${student}', 3, 5);
    `);

    await assert.rejects(
        rpc(db, 'grant_quiz_retake', [
            sessionId,
            student,
            teacher,
            'Attempt to reopen archived class.',
            null,
        ]),
        /archived or trashed class/,
    );

    const assignment = (await db.query(
        'select status, is_active, retake_mode from quiz_sessions where id = $1',
        [sessionId],
    )).rows[0];
    assert.deepEqual(assignment, { status: 'completed', is_active: false, retake_mode: false });
});

test('migration is repeatable and registered once', async (t) => {
    const db = await database(t);
    await db.exec(migration);
    const row = (await db.query(`
        select count(*)::integer as count
        from mathverse_schema_migrations
        where migration_key = '2026_09_24_quiz_lifecycle_and_active_retakes.sql'
    `)).rows[0];
    assert.equal(row.count, 1);

    const privileges = (await db.query(`
        select
            has_function_privilege(
                'service_role',
                'transition_quiz_session(uuid,uuid,uuid,text)',
                'execute'
            ) as service_can_transition,
            has_function_privilege(
                'authenticated',
                'transition_quiz_session(uuid,uuid,uuid,text)',
                'execute'
            ) as student_can_transition,
            has_function_privilege(
                'anon',
                'grant_quiz_retake(uuid,uuid,uuid,text,timestamp with time zone)',
                'execute'
            ) as anonymous_can_grant_retake,
            has_function_privilege(
                'service_role',
                'grant_quiz_retake(uuid,uuid,uuid,text,timestamp with time zone)',
                'execute'
            ) as service_can_grant_retake
    `)).rows[0];
    assert.deepEqual(privileges, {
        service_can_transition: true,
        student_can_transition: false,
        anonymous_can_grant_retake: false,
        service_can_grant_retake: true,
    });
});
