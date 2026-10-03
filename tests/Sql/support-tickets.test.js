import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(
    new URL('../../database/supabase/2026_10_03_support_tickets.sql', import.meta.url),
    'utf8',
).toLowerCase();
const rollback = readFileSync(
    new URL('../../database/supabase/2026_10_03_support_tickets_rollback.sql', import.meta.url),
    'utf8',
).toLowerCase();
const migrationSql = readFileSync(
    new URL('../../database/supabase/2026_10_03_support_tickets.sql', import.meta.url),
    'utf8',
);
const rollbackSql = readFileSync(
    new URL('../../database/supabase/2026_10_03_support_tickets_rollback.sql', import.meta.url),
    'utf8',
);

const student = '11111111-1111-4111-8111-111111111111';
const teacher = '22222222-2222-4222-8222-222222222222';
const admin = '33333333-3333-4333-8333-333333333333';

async function database(t) {
    const db = new PGlite();
    t.after(() => db.close());
    await db.exec(`
        create role anon;
        create role authenticated;
        create role service_role bypassrls;
        create schema auth;
        create function auth.uid() returns uuid language sql stable as $$
            select nullif(current_setting('request.jwt.claim.sub', true), '')::uuid
        $$;

        create table mathverse_schema_migrations (
            migration_key text primary key,
            applied_at timestamptz not null default now()
        );
        insert into mathverse_schema_migrations (migration_key)
        values ('2026_10_02_vr_server_authority_and_request_guards.sql');

        create table profiles (
            id uuid primary key,
            role text not null,
            first_name text,
            last_name text,
            email text,
            suspended_at timestamptz
        );
        create table notifications (
            id uuid primary key default gen_random_uuid(),
            user_id uuid not null references profiles(id),
            type text not null,
            title text not null,
            message text not null,
            action_url text,
            data jsonb not null default '{}'::jsonb,
            dedupe_key text,
            created_at timestamptz not null default now()
        );
        grant select on profiles to authenticated;
        create function create_notification(
            p_user_id uuid, p_type text, p_title text, p_message text,
            p_action_url text default null, p_data jsonb default '{}'::jsonb,
            p_dedupe_key text default null
        ) returns uuid language plpgsql security definer set search_path=public as $$
        declare created_id uuid;
        begin
            insert into notifications(user_id,type,title,message,action_url,data,dedupe_key)
            values(p_user_id,p_type,p_title,p_message,p_action_url,p_data,p_dedupe_key)
            returning id into created_id;
            return created_id;
        end $$;
        create function notify_all_admins(
            p_type text, p_title text, p_message text, p_action_url text,
            p_data jsonb, p_dedupe_key text
        ) returns void language plpgsql security definer set search_path=public as $$
        declare profile_row record;
        begin
            for profile_row in select id from profiles where role='admin' loop
                perform create_notification(profile_row.id,p_type,p_title,p_message,
                    p_action_url,p_data,p_dedupe_key);
            end loop;
        end $$;

        insert into profiles(id,role,first_name,last_name,email) values
            ('${student}','student','Ada','Learner','ada@example.test'),
            ('${teacher}','teacher','Tess','Teacher','tess@example.test'),
            ('${admin}','admin','Ari','Admin','ari@example.test');
    `);
    await db.exec(migrationSql);
    return db;
}

async function asUser(db, userId, sql) {
    await db.exec(`set request.jwt.claim.sub='${userId}'; set role authenticated;`);
    try {
        return await db.query(sql);
    } finally {
        await db.exec('reset role;');
    }
}

async function asService(db, sql) {
    await db.exec('set role service_role;');
    try {
        return await db.query(sql);
    } finally {
        await db.exec('reset role;');
    }
}

test('support tickets use constrained fields and immutable requester content', () => {
    assert.match(migration, /create table if not exists public\.support_tickets/);
    assert.match(migration, /category in \([\s\S]*?'bug'[\s\S]*?'vr'[\s\S]*?'other'/);
    assert.match(migration, /status in \('open', 'in_progress', 'resolved', 'closed'\)/);
    assert.match(migration, /priority in \('low', 'normal', 'high', 'urgent'\)/);
    assert.match(migration, /https:\/\/mathmetaverse\\\.space/);
    assert.match(migration, /support ticket report details are immutable/);
    assert.match(migration, /lock_version := old\.lock_version \+ 1/);
});

test('support ticket RLS exposes only owned rows and closes direct writes', () => {
    assert.match(migration, /alter table public\.support_tickets enable row level security/);
    assert.match(migration, /alter table public\.support_tickets force row level security/);
    assert.match(migration, /using \(auth\.uid\(\) = reporter_id\)/);
    assert.match(migration, /role in \('student', 'teacher'\)/);
    assert.match(migration, /role = 'admin'/);
    assert.match(
        migration,
        /revoke all privileges on table public\.support_tickets\s+from public, anon, authenticated/,
    );
    assert.match(migration, /grant select on table public\.support_tickets to authenticated/);
    assert.match(migration, /grant all privileges on table public\.support_tickets to service_role/);
});

test('support ticket changes create bounded in-app notifications', () => {
    assert.match(migration, /create or replace function public\.notify_support_ticket_changed\(\)/);
    assert.match(migration, /perform public\.notify_all_admins\(/);
    assert.match(migration, /perform public\.create_notification\(/);
    assert.match(migration, /support-ticket-admin:/);
    assert.match(migration, /support-ticket-reporter:/);
});

test('support ticket rollback archives private reports without exposing the archive', () => {
    assert.match(rollback, /create table if not exists public\.rollback_support_tickets_20261003/);
    assert.match(rollback, /alter table public\.rollback_support_tickets_20261003 force row level security/);
    assert.match(
        rollback,
        /revoke all privileges on table public\.rollback_support_tickets_20261003\s+from public, anon, authenticated, service_role/,
    );
    assert.match(rollback, /select \* from public\.support_tickets\s+on conflict \(id\) do nothing/);
    assert.match(rollback, /drop table if exists public\.support_tickets/);
});

test('support ticket migration enforces ownership, closed writes, and admin lifecycle rules', async (t) => {
    const db = await database(t);

    const inserted = (await asService(db, `
        insert into support_tickets(
            reporter_id,reporter_role,reporter_name,reporter_email,
            category,subject,description,reference_id
        ) values (
            '${student}','teacher','Untrusted Name','wrong@example.test',
            'bug','Quiz score is incorrect',
            'The correct answer did not increase the displayed score.',
            'mv-ff6753c9aa5eab94'
        ) returning *
    `)).rows[0];
    assert.equal(inserted.reporter_role, 'student');
    assert.equal(inserted.reporter_name, 'Ada Learner');
    assert.equal(inserted.reporter_email, 'ada@example.test');
    assert.equal(inserted.reference_id, 'MV-FF6753C9AA5EAB94');
    assert.equal(inserted.status, 'open');
    assert.equal(inserted.priority, 'normal');

    const own = (await asUser(db, student, 'select id from support_tickets')).rows;
    assert.equal(own.length, 1);
    const other = (await asUser(db, teacher, 'select id from support_tickets')).rows;
    assert.equal(other.length, 0);
    const adminRows = (await asUser(db, admin, 'select id from support_tickets')).rows;
    assert.equal(adminRows.length, 1);

    await assert.rejects(
        () => asUser(db, teacher, `
            insert into support_tickets(
                reporter_id,reporter_role,reporter_name,reporter_email,
                category,subject,description
            ) values (
                '${teacher}','teacher','Tess Teacher','tess@example.test',
                'bug','Direct write attempt','This direct authenticated insert must stay blocked.'
            )
        `),
        /permission denied/i,
    );

    const resolved = (await asService(db, `
        update support_tickets
        set status='resolved', priority='high',
            admin_response='The scoring service was repaired.',
            assigned_to='${admin}', updated_by='${admin}'
        where id='${inserted.id}' and lock_version=1
        returning status,priority,lock_version,resolved_at
    `)).rows[0];
    assert.equal(resolved.status, 'resolved');
    assert.equal(resolved.priority, 'high');
    assert.equal(resolved.lock_version, 2);
    assert.ok(resolved.resolved_at);

    await assert.rejects(
        () => asService(db, `
            update support_tickets set subject='Changed by admin', updated_by='${admin}'
            where id='${inserted.id}'
        `),
        /report details are immutable/i,
    );

    const notifications = (await db.query(`
        select type from notifications order by created_at
    `)).rows.map((row) => row.type);
    assert.deepEqual(notifications, ['support_ticket_submitted', 'support_ticket_updated']);
});

test('support ticket rollback preserves records in a runtime-inaccessible archive', async (t) => {
    const db = await database(t);
    await asService(db, `
        insert into support_tickets(
            reporter_id,reporter_role,reporter_name,reporter_email,
            category,subject,description
        ) values (
            '${student}','student','Ada Learner','ada@example.test',
            'error','Request unavailable error',
            'Several website functions show a request unavailable page.'
        )
    `);

    await db.exec(rollbackSql);
    const archived = await db.query('select count(*)::integer as count from rollback_support_tickets_20261003');
    assert.equal(archived.rows[0].count, 1);
    const installed = await db.query(`
        select to_regclass('public.support_tickets') as relation,
               exists(select 1 from mathverse_schema_migrations
                      where migration_key='2026_10_03_support_tickets.sql') as registered
    `);
    assert.equal(installed.rows[0].relation, null);
    assert.equal(installed.rows[0].registered, false);
    await assert.rejects(
        () => asService(db, 'select * from rollback_support_tickets_20261003'),
        /permission denied/i,
    );
});
