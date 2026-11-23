import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(new URL('../../supabase/migrations/20261123114822_student_anonymous_profiles_default.sql', import.meta.url), 'utf8');

test('existing students become anonymous, new profiles default private, and later opt-outs survive replay', async t => {
    const db = new PGlite();
    t.after(() => db.close());
    await db.exec(`
        create table public.profiles (
            id text primary key,
            role text not null default 'student',
            show_on_leaderboard boolean not null default true
        );
        create table public.mathverse_schema_migrations (
            migration_key text primary key,
            applied_at timestamptz not null default now()
        );
        insert into public.profiles (id, role, show_on_leaderboard) values
            ('public-student','student',true),
            ('private-student','student',false),
            ('teacher','teacher',true),
            ('admin','admin',true);
    `);

    await db.exec(migration);
    assert.deepEqual((await db.query('select id,show_on_leaderboard from public.profiles order by id')).rows, [
        { id: 'admin', show_on_leaderboard: true },
        { id: 'private-student', show_on_leaderboard: false },
        { id: 'public-student', show_on_leaderboard: false },
        { id: 'teacher', show_on_leaderboard: true },
    ]);

    await db.exec("insert into public.profiles (id) values ('new-student')");
    assert.equal((await db.query("select show_on_leaderboard from public.profiles where id='new-student'")).rows[0].show_on_leaderboard, false);

    await db.exec("update public.profiles set show_on_leaderboard=true where id='public-student'");
    await db.exec(migration);
    assert.equal((await db.query("select show_on_leaderboard from public.profiles where id='public-student'")).rows[0].show_on_leaderboard, true);
    assert.equal((await db.query('select count(*)::int as total from public.mathverse_schema_migrations')).rows[0].total, 1);
});
