import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(
    new URL('../../database/supabase/2026_09_20_vr_authenticated_client.sql', import.meta.url),
    'utf8',
);

const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const otherStudent = '33333333-3333-4333-8333-333333333333';
const session = '44444444-4444-4444-8444-444444444444';

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
        insert into mathverse_schema_migrations (migration_key) values
            ('2026_09_20_vr_legacy_access.sql'),
            ('2026_09_20_vr_score_submission.sql');

        create table profiles (
            id uuid primary key,
            role text not null,
            suspended_at timestamptz,
            deactivated_at timestamptz
        );
        create table quiz_sessions (
            id uuid primary key,
            teacher_id uuid not null references profiles(id),
            status text not null default 'active',
            is_active boolean not null default true,
            due_at timestamptz default now() + interval '1 day'
        );
        create table quiz_session_students (
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            eligibility_status text not null default 'eligible',
            retake_due_at timestamptz,
            primary key (session_id, student_id)
        );
        create table quiz_participants (
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            primary key (session_id, student_id)
        );

        create function get_vr_quiz_score_slot(p_session_id uuid, p_student_id uuid)
        returns table (
            can_submit boolean, status text, attempt_number integer,
            retake_granted_at text, message text
        ) language sql security definer as $$
            select true, p_student_id::text, 1, '', 'stub slot'
        $$;

        create function submit_vr_quiz_score(
            p_session_id uuid, p_student_id uuid, p_submission_id uuid,
            p_attempt_number integer, p_retake_granted_at text,
            p_correct_answers integer, p_total_questions integer
        ) returns table (
            saved boolean, status text, result_id uuid, attempt_number integer,
            correct_answers integer, total_questions integer, message text
        ) language sql security definer as $$
            select true, p_student_id::text, p_submission_id, p_attempt_number,
                   p_correct_answers, p_total_questions, 'stub receipt'
        $$;

        grant execute on function get_vr_quiz_score_slot(uuid, uuid)
            to anon, authenticated, service_role;
        grant execute on function submit_vr_quiz_score(uuid, uuid, uuid, integer, text, integer, integer)
            to anon, authenticated, service_role;
        grant insert (session_id, student_id) on quiz_participants to anon;

        insert into profiles (id, role) values
            ('${teacher}', 'teacher'),
            ('${student}', 'student'),
            ('${otherStudent}', 'student');
        insert into quiz_sessions (id, teacher_id) values ('${session}', '${teacher}');
        insert into quiz_session_students (session_id, student_id) values
            ('${session}', '${student}');
    `);
    await db.exec(migration);
    return db;
}

async function authenticatedRpc(db, functionName, values, userId = student) {
    await db.query("select set_config('request.jwt.claim.sub', $1, false)", [userId]);
    await db.exec('set role authenticated');
    try {
        const placeholders = values.map((_, index) => `$${index + 1}`).join(',');
        return (await db.query(
            `select * from public.${functionName}(${placeholders})`,
            values,
        )).rows[0];
    } finally {
        await db.exec('reset role');
        await db.exec("select set_config('request.jwt.claim.sub', '', false)");
    }
}

test('authenticated Unity migration is ordered, idempotent, and closes legacy anonymous writes', async t => {
    const db = await database(t);
    await db.exec(migration);

    const state = (await db.query(`
        select
            (select count(*)::integer from mathverse_schema_migrations
             where migration_key = '2026_09_20_vr_authenticated_client.sql') as migration_rows,
            has_function_privilege('authenticated',
                'register_my_vr_quiz_participant(uuid)', 'execute') as authenticated_join,
            has_function_privilege('authenticated',
                'get_my_vr_quiz_score_slot(uuid)', 'execute') as authenticated_slot,
            has_function_privilege('authenticated',
                'submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)',
                'execute') as authenticated_score,
            has_function_privilege('anon',
                'get_vr_quiz_score_slot(uuid,uuid)', 'execute') as anonymous_old_slot,
            has_function_privilege('anon',
                'submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)',
                'execute') as anonymous_old_score,
            has_column_privilege('anon', 'quiz_participants', 'student_id', 'insert')
                as anonymous_participant_insert,
            has_function_privilege('authenticated',
                'get_vr_quiz_score_slot(uuid,uuid)', 'execute') as authenticated_old_slot,
            has_function_privilege('authenticated',
                'submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)',
                'execute') as authenticated_old_score,
            has_column_privilege('authenticated', 'quiz_participants', 'student_id', 'insert')
                as authenticated_direct_participant_insert
    `)).rows[0];

    assert.deepEqual(state, {
        migration_rows: 1,
        authenticated_join: true,
        authenticated_slot: true,
        authenticated_score: true,
        anonymous_old_slot: false,
        anonymous_old_score: false,
        anonymous_participant_insert: false,
        authenticated_old_slot: false,
        authenticated_old_score: false,
        authenticated_direct_participant_insert: false,
    });
});

test('participant registration derives identity from the signed-in user and is idempotent', async t => {
    const db = await database(t);
    const first = await authenticatedRpc(db, 'register_my_vr_quiz_participant', [session]);
    const second = await authenticatedRpc(db, 'register_my_vr_quiz_participant', [session]);

    assert.equal(first.joined, true);
    assert.equal(first.status, 'joined');
    assert.equal(second.joined, true);
    assert.equal(second.status, 'already_joined');

    const participants = (await db.query(
        'select student_id from quiz_participants where session_id = $1',
        [session],
    )).rows;
    assert.deepEqual(participants.map(row => row.student_id), [student]);
});

test('score wrappers always forward auth.uid and expose no student-id argument', async t => {
    const db = await database(t);
    await authenticatedRpc(db, 'register_my_vr_quiz_participant', [session]);

    const slot = await authenticatedRpc(db, 'get_my_vr_quiz_score_slot', [session]);
    assert.equal(slot.status, student);

    const submissionId = randomUUID();
    const receipt = await authenticatedRpc(db, 'submit_my_vr_quiz_score', [
        session,
        submissionId,
        1,
        '',
        4,
        5,
    ]);
    assert.equal(receipt.saved, true);
    assert.equal(receipt.status, student);
    assert.equal(receipt.result_id, submissionId);

    const signatures = (await db.query(`
        select oid::regprocedure::text as signature
        from pg_proc
        where pronamespace = 'public'::regnamespace
          and proname in (
              'register_my_vr_quiz_participant',
              'get_my_vr_quiz_score_slot',
              'submit_my_vr_quiz_score'
          )
        order by proname
    `)).rows.map(row => row.signature);
    assert.deepEqual(signatures, [
        'get_my_vr_quiz_score_slot(uuid)',
        'register_my_vr_quiz_participant(uuid)',
        'submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)',
    ]);
});

test('registration rejects unassigned, suspended, inactive, and expired student access', async t => {
    const db = await database(t);

    assert.equal(
        (await authenticatedRpc(
            db,
            'register_my_vr_quiz_participant',
            [session],
            otherStudent,
        )).status,
        'not_eligible',
    );

    await db.query('update profiles set suspended_at = now() where id = $1', [student]);
    assert.equal(
        (await authenticatedRpc(db, 'register_my_vr_quiz_participant', [session])).status,
        'account_unavailable',
    );

    await db.query('update profiles set suspended_at = null where id = $1', [student]);
    await db.query('update quiz_sessions set status = $1, is_active = false where id = $2', [
        'completed',
        session,
    ]);
    assert.equal(
        (await authenticatedRpc(db, 'register_my_vr_quiz_participant', [session])).status,
        'not_available',
    );

    await db.query(
        "update quiz_sessions set status = 'active', is_active = true where id = $1",
        [session],
    );
    await db.query(
        "update quiz_session_students set retake_due_at = now() - interval '1 minute' "
            + 'where session_id = $1 and student_id = $2',
        [session, student],
    );
    assert.equal(
        (await authenticatedRpc(db, 'register_my_vr_quiz_participant', [session])).status,
        'expired',
    );
});

test('anonymous callers cannot register a participant or call either score path', async t => {
    const db = await database(t);
    await db.exec('set role anon');
    try {
        await assert.rejects(
            db.query('select * from register_my_vr_quiz_participant($1)', [session]),
            /permission denied/i,
        );
        await assert.rejects(
            db.query('select * from get_vr_quiz_score_slot($1, $2)', [session, student]),
            /permission denied/i,
        );
        await assert.rejects(
            db.query(
                'insert into quiz_participants (session_id, student_id) values ($1, $2)',
                [session, student],
            ),
            /permission denied/i,
        );
    } finally {
        await db.exec('reset role');
    }
});
