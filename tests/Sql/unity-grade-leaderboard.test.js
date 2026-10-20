import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migrationSql = readFileSync(
    new URL('../../database/supabase/2026_10_20_unity_grade_leaderboard.sql', import.meta.url),
    'utf8',
);
const rollbackSql = readFileSync(
    new URL('../../database/supabase/2026_10_20_unity_grade_leaderboard_rollback.sql', import.meta.url),
    'utf8',
);
const migration = migrationSql.toLowerCase();

const currentStudent = '11111111-1111-4111-8111-111111111111';
const visibleStudent = '22222222-2222-4222-8222-222222222222';
const privateStudent = '33333333-3333-4333-8333-333333333333';
const thirdStudent = '44444444-4444-4444-8444-444444444444';
const otherGradeStudent = '55555555-5555-4555-8555-555555555555';
const suspendedStudent = '66666666-6666-4666-8666-666666666666';
const deactivatedStudent = '77777777-7777-4777-8777-777777777777';
const teacher = '88888888-8888-4888-8888-888888888888';

async function database(t) {
    const db = new PGlite();
    t.after(() => db.close());

    await db.exec(`
        create role anon;
        create role authenticated;
        create role service_role bypassrls;

        create schema auth;
        grant usage on schema auth to anon, authenticated;
        create function auth.uid() returns uuid language sql stable as $$
            select nullif(current_setting('request.jwt.claim.sub', true), '')::uuid
        $$;

        create table public.mathverse_schema_migrations (
            migration_key text primary key,
            applied_at timestamptz not null default now()
        );
        insert into public.mathverse_schema_migrations (migration_key)
        values ('2026_09_13_recovery_and_incident_alerts.sql');

        create table public.profiles (
            id uuid primary key,
            username text,
            first_name text,
            last_name text,
            role text not null,
            level integer,
            trophies integer,
            grade_level integer,
            avatar_url text,
            leaderboard_alias text,
            show_on_leaderboard boolean not null default true,
            suspended_at timestamptz,
            deactivated_at timestamptz
        );

        alter table public.profiles enable row level security;
        grant select on public.profiles to anon, authenticated;
        create policy legacy_profiles_read_all
            on public.profiles
            as permissive
            for select
            to anon, authenticated
            using (true);

        insert into public.profiles (
            id, first_name, last_name, role, level, trophies, grade_level,
            avatar_url, leaderboard_alias, show_on_leaderboard,
            suspended_at, deactivated_at
        ) values
            ('${currentStudent}', 'Current', 'Learner', 'student', 7, 10, 4,
                'https://mathverse.supabase.co/storage/v1/object/public/avatars/current.png',
                null, true, null, null),
            ('${visibleStudent}', 'Visible', 'Winner', 'student', 12, 100, 4,
                'https://mathverse.supabase.co/storage/v1/object/public/avatars/visible.png',
                'Math Ace', true, null, null),
            ('${privateStudent}', 'Private', 'Person', 'student', 11, 90, 4,
                'https://mathverse.supabase.co/storage/v1/object/public/avatars/private.png',
                'Hidden Name', false, null, null),
            ('${thirdStudent}', 'Third', 'Place', 'student', 9, 80, 4,
                null, null, true, null, null),
            ('${otherGradeStudent}', 'Other', 'Grade', 'student', 50, 999, 5,
                'https://mathverse.supabase.co/storage/v1/object/public/avatars/other.png',
                null, true, null, null),
            ('${suspendedStudent}', 'Suspended', 'Student', 'student', 30, 800, 4,
                null, null, true, now(), null),
            ('${deactivatedStudent}', 'Deleted', 'Student', 'student', 30, 700, 4,
                null, null, true, null, now()),
            ('${teacher}', 'Grade', 'Teacher', 'teacher', 99, 1000, 4,
                null, null, true, null, null);
    `);

    await db.exec(migrationSql);
    return db;
}

async function asRole(db, role, userId, sql) {
    await db.query("select set_config('request.jwt.claim.sub', $1, false)", [userId ?? '']);
    await db.exec(`set role ${role}`);
    try {
        return await db.query(sql);
    } finally {
        await db.exec('reset role');
        await db.exec("select set_config('request.jwt.claim.sub', '', false)");
    }
}

test('migration exposes only an authenticated, auth.uid scoped leaderboard RPC', () => {
    assert.match(migration, /create or replace function public\.get_my_unity_trophy_leaderboard\(\s*p_limit integer/);
    assert.doesNotMatch(migration, /p_student_id|p_grade_level/);
    assert.match(migration, /profile\.id = \(select auth\.uid\(\)\)/);
    assert.match(migration, /profile\.grade_level = current_student\.grade_level/);
    assert.match(migration, /security definer[\s\S]*?set search_path = pg_catalog, public/);
    assert.match(
        migration,
        /revoke all on function public\.get_my_unity_trophy_leaderboard\(integer\)[\s\S]*?from public, anon, authenticated, service_role/,
    );
    assert.match(
        migration,
        /grant execute on function public\.get_my_unity_trophy_leaderboard\(integer\)[\s\S]*?to authenticated/,
    );
    assert.doesNotMatch(
        migration,
        /grant execute on function public\.get_my_unity_trophy_leaderboard\(integer\)[\s\S]*?to (anon|service_role)/,
    );
    assert.match(
        migration,
        /create policy mathverse_profiles_self_read_allow[\s\S]*?as permissive[\s\S]*?to authenticated[\s\S]*?id = \(select auth\.uid\(\)\)/,
    );
    assert.match(
        migration,
        /create policy mathverse_profiles_client_read_boundary[\s\S]*?as restrictive[\s\S]*?to anon, authenticated/,
    );
});

test('direct profile reads are limited to the authenticated user despite a legacy broad policy', async (t) => {
    const db = await database(t);

    const ownProfile = await asRole(
        db,
        'authenticated',
        currentStudent,
        'select id, grade_level from public.profiles order by id',
    );
    assert.deepEqual(ownProfile.rows, [{ id: currentStudent, grade_level: 4 }]);

    const anonymousProfiles = await asRole(
        db,
        'anon',
        null,
        'select id from public.profiles',
    );
    assert.deepEqual(anonymousProfiles.rows, []);
});

test('authenticated students receive only their grade with real levels and privacy-safe avatars', async (t) => {
    const db = await database(t);
    const result = await asRole(
        db,
        'authenticated',
        currentStudent,
        'select * from public.get_my_unity_trophy_leaderboard(3)',
    );

    assert.equal(result.rows.length, 4, 'top three plus the current student is returned');
    assert.deepEqual(result.rows.map((row) => Number(row.rank)), [1, 2, 3, 4]);
    assert.ok(result.rows.every((row) => Number(row.grade_level) === 4));
    assert.ok(result.rows.every((row) => !Object.hasOwn(row, 'student_id')));

    const winner = result.rows[0];
    assert.equal(winner.display_name, 'Math Ace');
    assert.equal(Number(winner.trophies), 100);
    assert.equal(Number(winner.level), 12);
    assert.equal(
        winner.avatar_url,
        'https://mathverse.supabase.co/storage/v1/object/public/avatars/visible.png',
    );

    const hidden = result.rows[1];
    assert.equal(hidden.display_name, 'Anonymous Student');
    assert.equal(hidden.avatar_url, null);

    const current = result.rows[3];
    assert.equal(current.display_name, 'Current L.');
    assert.equal(Number(current.level), 7);
    assert.equal(current.is_current, true);
    assert.equal(
        current.avatar_url,
        'https://mathverse.supabase.co/storage/v1/object/public/avatars/current.png',
    );
});

test('the server derives grade from auth.uid and rejects anonymous or privileged direct calls', async (t) => {
    const db = await database(t);

    const gradeFive = await asRole(
        db,
        'authenticated',
        otherGradeStudent,
        'select * from public.get_my_unity_trophy_leaderboard(10)',
    );
    assert.equal(gradeFive.rows.length, 1);
    assert.equal(Number(gradeFive.rows[0].grade_level), 5);
    assert.equal(gradeFive.rows[0].display_name, 'Other G.');

    const teacherRows = await asRole(
        db,
        'authenticated',
        teacher,
        'select * from public.get_my_unity_trophy_leaderboard(10)',
    );
    assert.deepEqual(teacherRows.rows, []);

    await assert.rejects(
        () => asRole(
            db,
            'anon',
            currentStudent,
            'select * from public.get_my_unity_trophy_leaderboard(10)',
        ),
        /permission denied/i,
    );
    await assert.rejects(
        () => asRole(
            db,
            'service_role',
            currentStudent,
            'select * from public.get_my_unity_trophy_leaderboard(10)',
        ),
        /permission denied/i,
    );
});

test('migration is rerunnable and rollback removes the client endpoint', async (t) => {
    const db = await database(t);
    await db.exec(migrationSql);

    const migrationCount = (await db.query(`
        select count(*)::integer as count
        from public.mathverse_schema_migrations
        where migration_key = '2026_10_20_unity_grade_leaderboard.sql'
    `)).rows[0].count;
    assert.equal(migrationCount, 1);

    await db.exec(rollbackSql);
    const state = (await db.query(`
        select
            to_regprocedure('public.get_my_unity_trophy_leaderboard(integer)') as function_name,
            to_regclass('public.profiles') is not null and exists (
                select 1 from pg_policies
                where schemaname = 'public'
                  and tablename = 'profiles'
                  and policyname = 'mathverse_profiles_client_read_boundary'
            ) as boundary_exists,
            exists (
                select 1 from public.mathverse_schema_migrations
                where migration_key = '2026_10_20_unity_grade_leaderboard.sql'
            ) as registered
    `)).rows[0];
    assert.equal(state.function_name, null);
    assert.equal(state.boundary_exists, false);
    assert.equal(state.registered, false);
});
