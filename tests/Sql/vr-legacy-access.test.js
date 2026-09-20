import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(
    new URL('../../database/supabase/2026_09_20_vr_legacy_access.sql', import.meta.url),
    'utf8',
);

const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const activeSession = '33333333-3333-4333-8333-333333333333';
const completedSession = '44444444-4444-4444-8444-444444444444';

async function database() {
    const db = new PGlite();
    await db.exec(`
        create role anon;
        create role authenticated;
        create role service_role;
        create table mathverse_schema_migrations (
            migration_key text primary key,
            applied_at timestamptz not null default now()
        );
        create table profiles (
            id uuid primary key,
            role text not null
        );
        create table quiz_sessions (
            id uuid primary key,
            teacher_id uuid not null references profiles(id),
            room_code text not null,
            status text not null,
            time_limit integer not null default 20,
            is_active boolean not null default true
        );
        create table questions (
            id bigint generated always as identity primary key,
            session_id uuid not null references quiz_sessions(id),
            question text not null,
            correct_answer text not null,
            deleted_at timestamptz
        );
        create table quiz_participants (
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            primary key (session_id, student_id)
        );
        create table quiz_results (
            id uuid primary key default gen_random_uuid(),
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id)
        );
        alter table quiz_sessions enable row level security;
        alter table questions enable row level security;
        alter table quiz_participants enable row level security;
        alter table profiles enable row level security;
        alter table quiz_results enable row level security;
        insert into profiles values
            ('${teacher}', 'teacher'),
            ('${student}', 'student');
        insert into quiz_sessions (id, teacher_id, room_code, status, is_active) values
            ('${activeSession}', '${teacher}', '1234', 'active', true),
            ('${completedSession}', '${teacher}', '5678', 'completed', false);
        insert into questions (session_id, question, correct_answer) values
            ('${activeSession}', 'Visible question', '2'),
            ('${completedSession}', 'Hidden question', '3');
    `);

    return db;
}

test('VR access migration is ordered, idempotent, and restores revoked grants', async () => {
    const db = await database();
    try {
        await db.exec(migration);
        await db.exec(migration);

        const state = (await db.query(`
            select
                (select count(*)::integer from mathverse_schema_migrations
                    where migration_key = '2026_09_20_vr_legacy_access.sql') as migration_rows,
                (select count(*)::integer from pg_policy policy
                    join pg_class relation on relation.oid = policy.polrelid
                    join pg_namespace namespace on namespace.oid = relation.relnamespace
                    where namespace.nspname = 'public'
                      and relation.relname in ('quiz_sessions', 'questions', 'quiz_participants')
                      and policy.polname in (
                          'mathverse_vr_legacy_read_allow',
                          'mathverse_vr_legacy_read_boundary',
                          'mathverse_vr_legacy_join_allow',
                          'mathverse_vr_legacy_join_boundary'
                      )) as policies,
                has_column_privilege('anon', 'quiz_sessions', 'room_code', 'select') as room_lookup,
                has_table_privilege('anon', 'questions', 'select') as question_fetch,
                has_column_privilege('anon', 'quiz_participants', 'student_id', 'insert') as room_join,
                has_table_privilege('anon', 'quiz_results', 'select,insert,update,delete') as direct_results
        `)).rows[0];
        assert.deepEqual(state, {
            migration_rows: 1,
            policies: 6,
            room_lookup: true,
            question_fetch: true,
            room_join: true,
            direct_results: false,
        });

        await db.exec(`
            revoke select on quiz_sessions from anon;
            revoke select on questions from anon;
            revoke insert on quiz_participants from anon;
        `);
        await db.exec(migration);
        assert.equal((await db.query(
            "select has_column_privilege('anon', 'quiz_sessions', 'room_code', 'select') as restored",
        )).rows[0].restored, true);
    } finally {
        await db.close();
    }
});

test('anonymous Unity access stays inside active rooms and protected tables stay closed', async () => {
    const db = await database();
    try {
        await db.exec(migration);
        await db.exec('set role anon');
        try {
            const rooms = (await db.query('select room_code from quiz_sessions order by room_code')).rows;
            assert.deepEqual(rooms.map(row => row.room_code), ['1234']);
            const questions = (await db.query('select question, correct_answer from questions')).rows;
            assert.deepEqual(questions, [{ question: 'Visible question', correct_answer: '2' }]);
            await db.query('insert into quiz_participants (session_id, student_id) values ($1, $2)', [
                activeSession,
                student,
            ]);
            await assert.rejects(
                db.query('insert into quiz_participants (session_id, student_id) values ($1, $2)', [
                    completedSession,
                    student,
                ]),
                /row-level security|policy/i,
            );
            await assert.rejects(db.query('select * from profiles'), /permission denied/i);
            await assert.rejects(db.query('select * from quiz_results'), /permission denied/i);
        } finally {
            await db.exec('reset role');
        }
    } finally {
        await db.close();
    }
});
