import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { PGlite } from '@electric-sql/pglite';

const scoreMigration = readFileSync(
    new URL('../../database/supabase/2026_09_20_vr_score_submission.sql', import.meta.url),
    'utf8',
);
const authMigration = readFileSync(
    new URL('../../database/supabase/2026_09_20_vr_authenticated_client.sql', import.meta.url),
    'utf8',
);
const repairMigration = readFileSync(
    new URL('../../database/supabase/2026_09_23_vr_retake_score_repair.sql', import.meta.url),
    'utf8',
);
const lifecycleMigration = readFileSync(
    new URL('../../database/supabase/2026_09_24_quiz_lifecycle_and_active_retakes.sql', import.meta.url),
    'utf8',
);
const migrationReadme = readFileSync(
    new URL('../../database/supabase/README.md', import.meta.url),
    'utf8',
);

const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const session = '33333333-3333-4333-8333-333333333333';
const classId = '44444444-4444-4444-8444-444444444444';

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
        values ('2026_09_20_vr_legacy_access.sql');

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
            status text not null default 'active',
            is_active boolean not null default true,
            retake_mode boolean not null default false,
            available_at timestamptz default now() - interval '1 hour',
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
        create table questions (
            id bigint generated always as identity primary key,
            session_id uuid not null references quiz_sessions(id),
            deleted_at timestamptz
        );
        create table quiz_results (
            id uuid primary key,
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            correct_answers integer not null,
            total_questions integer not null,
            rank integer not null default 0,
            created_at timestamptz not null default timezone('utc', now()),
            attempt_number integer not null default 1,
            is_counted boolean not null default true
        );

        alter table quiz_participants enable row level security;
        alter table quiz_results enable row level security;

        create function enforce_quiz_result_attempt()
        returns trigger language plpgsql security definer set search_path = public as $$
        declare
            attempts_used integer;
        begin
            select count(*)::integer into attempts_used
            from quiz_results
            where session_id = new.session_id and student_id = new.student_id;

            update quiz_results set is_counted = false
            where session_id = new.session_id
              and student_id = new.student_id
              and is_counted;

            new.attempt_number := attempts_used + 1;
            new.is_counted := true;
            return new;
        end
        $$;

        create function require_explicit_quiz_retake()
        returns trigger language plpgsql security definer set search_path = public as $$
        declare
            granted_at timestamptz;
        begin
            select last_retake_granted_at into granted_at
            from quiz_session_students
            where session_id = new.session_id and student_id = new.student_id;

            if granted_at is null and exists (
                select 1 from quiz_results
                where session_id = new.session_id and student_id = new.student_id
            ) then
                return null;
            end if;
            return new;
        end
        $$;

        create function keep_quiz_results_immutable()
        returns trigger language plpgsql security definer set search_path = public as $$
        begin
            if pg_trigger_depth() = 1
               and (to_jsonb(new) - 'rank') is distinct from (to_jsonb(old) - 'rank') then
                raise exception 'Quiz results are immutable';
            end if;
            return new;
        end
        $$;

        insert into profiles (id, role) values
            ('${teacher}', 'teacher'),
            ('${student}', 'student');
        insert into classes (id, teacher_id) values ('${classId}', '${teacher}');
        insert into quiz_sessions (id, teacher_id, class_id)
        values ('${session}', '${teacher}', '${classId}');
        insert into quiz_session_students (session_id, student_id)
        values ('${session}', '${student}');
        insert into quiz_participants (session_id, student_id)
        values ('${session}', '${student}');
        insert into questions (session_id)
        select '${session}'::uuid from generate_series(1, 5);
    `);
    await db.exec(scoreMigration);
    await db.exec(authMigration);
    return db;
}

async function authenticatedRpc(db, functionName, values) {
    await db.query("select set_config('request.jwt.claim.sub', $1, false)", [student]);
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

async function serviceRpc(db, functionName, values) {
    await db.exec('set role service_role');
    try {
        const placeholders = values.map((_, index) => `$${index + 1}`).join(',');
        return (await db.query(
            `select * from public.${functionName}(${placeholders})`,
            values,
        )).rows[0];
    } finally {
        await db.exec('reset role');
    }
}

async function slot(db) {
    return authenticatedRpc(db, 'get_my_vr_quiz_score_slot', [session]);
}

async function save(db, permission, score) {
    return authenticatedRpc(db, 'submit_my_vr_quiz_score', [
        session,
        randomUUID(),
        permission.attempt_number,
        permission.retake_granted_at,
        score,
        5,
    ]);
}

test('repair removes differently named one-attempt rules and saves repeated retakes', async t => {
    const db = await database(t);
    assert.equal((await save(db, await slot(db), 4)).status, 'saved');

    await db.exec(`
        grant insert, update, delete, truncate, references, trigger
            on table quiz_results to public, anon, authenticated;
        grant insert (id, session_id, student_id, correct_answers, total_questions),
              update (correct_answers),
              references (session_id, student_id)
            on table quiz_results to public, anon, authenticated;

        alter table quiz_results
            add constraint deployed_single_result_constraint
            unique (student_id, session_id);
        create unique index deployed_single_result_index
            on quiz_results (session_id, student_id);
        create function ignore_repeat_quiz_result()
        returns trigger language plpgsql as $$
        begin
            return null;
        end
        $$;
        create trigger deployed_single_result_trigger
            before insert on quiz_results
            for each row execute function ignore_repeat_quiz_result();

        update quiz_sessions
        set retake_mode = true, status = 'active', is_active = true,
            due_at = now() + interval '1 day'
        where id = '${session}';
        update quiz_session_students
        set allowed_attempts = 2,
            last_retake_granted_at = now(),
            last_retake_granted_by = '${teacher}',
            retake_due_at = now() + interval '1 day'
        where session_id = '${session}' and student_id = '${student}';
    `);

    const driftedPrivileges = (await db.query(`
        select
            has_table_privilege('authenticated', 'quiz_results', 'insert') as table_insert,
            has_table_privilege('authenticated', 'quiz_results', 'update') as table_update,
            has_table_privilege('authenticated', 'quiz_results', 'delete') as table_delete,
            has_table_privilege('authenticated', 'quiz_results', 'truncate') as table_truncate,
            has_table_privilege('authenticated', 'quiz_results', 'references') as table_references,
            has_table_privilege('authenticated', 'quiz_results', 'trigger') as table_trigger,
            has_column_privilege('authenticated', 'quiz_results', 'correct_answers', 'insert')
                as column_insert,
            has_column_privilege('authenticated', 'quiz_results', 'correct_answers', 'update')
                as column_update,
            has_column_privilege('authenticated', 'quiz_results', 'session_id', 'references')
                as column_references
    `)).rows[0];
    assert.deepEqual(driftedPrivileges, {
        table_insert: true,
        table_update: true,
        table_delete: true,
        table_truncate: true,
        table_references: true,
        table_trigger: true,
        column_insert: true,
        column_update: true,
        column_references: true,
    });

    await db.exec(repairMigration);
    await db.exec(repairMigration);

    const permission = await slot(db);
    assert.equal(permission.attempt_number, 2);
    assert.equal((await save(db, permission, 1)).status, 'saved');

    const rows = (await db.query(`
        select attempt_number, correct_answers, is_counted
        from quiz_results
        order by attempt_number
    `)).rows;
    assert.deepEqual(rows, [
        { attempt_number: 1, correct_answers: 4, is_counted: false },
        { attempt_number: 2, correct_answers: 1, is_counted: true },
    ]);

    const repaired = (await db.query(`
        select
            to_regclass('public.deployed_single_result_constraint') is null
                as stale_constraint_index_removed,
            to_regclass('public.deployed_single_result_index') is null
                as stale_named_index_removed,
            to_regprocedure('public.ignore_repeat_quiz_result()') is null
                as stale_function_removed,
            to_regclass('public.quiz_results_attempt_number_idx') is not null
                as attempt_index_ready,
            to_regclass('public.quiz_results_one_counted_attempt_idx') is not null
                as counted_index_ready,
            (select count(*)::integer from mathverse_schema_migrations
             where migration_key = '2026_09_23_vr_retake_score_repair.sql')
                as migration_rows,
            has_function_privilege('authenticated',
                'submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)',
                'execute') as authenticated_wrapper,
            has_function_privilege('anon',
                'submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)',
                'execute') as anonymous_wrapper,
            has_function_privilege('authenticated',
                'submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)',
                'execute') as authenticated_internal,
            has_function_privilege('service_role',
                'submit_vr_quiz_score(uuid,uuid,uuid,integer,text,integer,integer)',
                'execute') as service_internal,
            has_table_privilege('authenticated', 'quiz_results', 'insert')
                as authenticated_table_insert,
            has_table_privilege('authenticated', 'quiz_results', 'update')
                as authenticated_table_update,
            has_table_privilege('authenticated', 'quiz_results', 'delete')
                as authenticated_table_delete,
            has_table_privilege('authenticated', 'quiz_results', 'truncate')
                as authenticated_table_truncate,
            has_table_privilege('authenticated', 'quiz_results', 'references')
                as authenticated_table_references,
            has_table_privilege('authenticated', 'quiz_results', 'trigger')
                as authenticated_table_trigger,
            has_any_column_privilege('authenticated', 'quiz_results', 'insert')
                as authenticated_column_insert,
            has_any_column_privilege('authenticated', 'quiz_results', 'update')
                as authenticated_column_update,
            has_any_column_privilege('authenticated', 'quiz_results', 'references')
                as authenticated_column_references,
            has_table_privilege('anon', 'quiz_results', 'insert')
                or has_table_privilege('anon', 'quiz_results', 'update')
                or has_table_privilege('anon', 'quiz_results', 'delete')
                or has_table_privilege('anon', 'quiz_results', 'truncate')
                or has_table_privilege('anon', 'quiz_results', 'references')
                or has_table_privilege('anon', 'quiz_results', 'trigger')
                as anonymous_table_write,
            has_any_column_privilege('anon', 'quiz_results', 'insert')
                or has_any_column_privilege('anon', 'quiz_results', 'update')
                or has_any_column_privilege('anon', 'quiz_results', 'references')
                as anonymous_column_write
    `)).rows[0];

    assert.deepEqual(repaired, {
        stale_constraint_index_removed: true,
        stale_named_index_removed: true,
        stale_function_removed: true,
        attempt_index_ready: true,
        counted_index_ready: true,
        migration_rows: 1,
        authenticated_wrapper: true,
        anonymous_wrapper: false,
        authenticated_internal: false,
        service_internal: true,
        authenticated_table_insert: false,
        authenticated_table_update: false,
        authenticated_table_delete: false,
        authenticated_table_truncate: false,
        authenticated_table_references: false,
        authenticated_table_trigger: false,
        authenticated_column_insert: false,
        authenticated_column_update: false,
        authenticated_column_references: false,
        anonymous_table_write: false,
        anonymous_column_write: false,
    });
});

test('active per-student retake saves attempt two without enabling global retake mode', async t => {
    const db = await database(t);
    assert.equal((await save(db, await slot(db), 4)).status, 'saved');

    await db.exec(repairMigration);
    await db.exec(lifecycleMigration);

    const grant = await serviceRpc(db, 'grant_quiz_retake', [
        session,
        student,
        teacher,
        'Teacher-approved active retake.',
        null,
    ]);
    assert.equal(grant.new_allowed_attempts, 2);

    const assignment = (await db.query(
        'select status, is_active, retake_mode from quiz_sessions where id = $1',
        [session],
    )).rows[0];
    assert.deepEqual(assignment, { status: 'active', is_active: true, retake_mode: false });

    const permission = await slot(db);
    assert.equal(permission.can_submit, true);
    assert.equal(permission.attempt_number, 2);
    assert.equal((await save(db, permission, 2)).status, 'saved');

    const attempts = (await db.query(`
        select attempt_number, correct_answers, is_counted
        from quiz_results
        where session_id = $1 and student_id = $2
        order by attempt_number
    `, [session, student])).rows;
    assert.deepEqual(attempts, [
        { attempt_number: 1, correct_answers: 4, is_counted: false },
        { attempt_number: 2, correct_answers: 2, is_counted: true },
    ]);
});

test('repair source scopes stale-index removal and documents final ordering', () => {
    assert.match(repairMigration, /constraint_row\.contype = 'u'/);
    assert.match(repairMigration, /index_row\.indpred IS NULL/);
    assert.match(repairMigration, /index_row\.indnkeyatts = 2/);
    assert.match(repairMigration, /ARRAY\['session_id', 'student_id'\]::text\[\]/);
    assert.match(
        repairMigration,
        /REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER[\s\S]*FROM PUBLIC, anon, authenticated/,
    );
    assert.match(
        repairMigration,
        /REVOKE INSERT \(%1\$I\), UPDATE \(%1\$I\), REFERENCES \(%1\$I\)/,
    );
    assert.match(repairMigration, /TO authenticated;/);
    assert.doesNotMatch(repairMigration, /GRANT EXECUTE[\s\S]{0,160}TO anon/);

    const legacy = migrationReadme.indexOf('2026_09_20_vr_legacy_access.sql');
    const score = migrationReadme.indexOf('2026_09_20_vr_score_submission.sql', legacy);
    const auth = migrationReadme.indexOf('2026_09_20_vr_authenticated_client.sql', score);
    const repair = migrationReadme.indexOf('2026_09_23_vr_retake_score_repair.sql', auth);
    assert.ok(legacy >= 0 && legacy < score && score < auth && auth < repair);
});
