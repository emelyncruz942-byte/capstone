import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const baseSql = readFileSync(
    new URL('../../database/supabase/2026_10_11_versioned_unity_app_releases.sql', import.meta.url),
    'utf8',
);
const r2Sql = readFileSync(
    new URL('../../database/supabase/2026_10_12_cloudflare_r2_app_releases.sql', import.meta.url),
    'utf8',
);
const deletionSql = readFileSync(
    new URL('../../database/supabase/2026_10_19_deletable_unity_app_releases.sql', import.meta.url),
    'utf8',
);
const rollbackSql = readFileSync(
    new URL('../../database/supabase/2026_10_19_deletable_unity_app_releases_rollback.sql', import.meta.url),
    'utf8',
);
const migration = deletionSql.toLowerCase();

const admin = '11111111-1111-4111-8111-111111111111';
const otherAdmin = '22222222-2222-4222-8222-222222222222';
const student = '33333333-3333-4333-8333-333333333333';

async function database(t) {
    const db = new PGlite();
    t.after(() => db.close());

    await db.exec(`
        create role anon;
        create role authenticated;
        create role service_role bypassrls;
        create table profiles (
            id uuid primary key,
            role text not null,
            suspended_at timestamptz,
            deactivated_at timestamptz
        );
        create table mathverse_schema_migrations (
            migration_key text primary key,
            applied_at timestamptz not null default now()
        );
        insert into mathverse_schema_migrations(migration_key)
        values ('2026_10_03_support_tickets.sql');
        insert into profiles(id,role) values
            ('${admin}','admin'),
            ('${otherAdmin}','admin'),
            ('${student}','student');

        create schema storage;
        grant usage on schema storage to anon,authenticated,service_role;
        create table storage.buckets (
            id text primary key,
            name text not null unique,
            public boolean not null default false,
            file_size_limit bigint,
            allowed_mime_types text[]
        );
        create table storage.objects (
            id uuid primary key default gen_random_uuid(),
            bucket_id text not null references storage.buckets(id),
            name text not null,
            metadata jsonb,
            created_at timestamptz not null default now(),
            unique(bucket_id,name)
        );
        alter table storage.buckets enable row level security;
        alter table storage.objects enable row level security;
        grant select,insert,update,delete on storage.buckets to anon,authenticated,service_role;
        grant select,insert,update,delete on storage.objects to anon,authenticated,service_role;
    `);

    await db.exec(baseSql);
    await db.exec(r2Sql);
    await db.exec(deletionSql);
    return db;
}

async function asRole(db, role, sql) {
    await db.exec(`set role ${role};`);
    try {
        return await db.query(sql);
    } finally {
        await db.exec('reset role;');
    }
}

async function beginR2(db, version = '2.0.0') {
    const result = await asRole(db, 'service_role', `
        select * from public.begin_unity_app_release_v2(
            '${admin}', '${version}', 'android', 'apk',
            'MathVerse-${version}.apk', 1024, 'Release notes',
            'application/vnd.android.package-archive', 5242880, 'mathverse-releases'
        )
    `);
    return result.rows[0];
}

async function claim(db, release, actor = admin) {
    const result = await asRole(db, 'service_role', `
        select * from public.begin_unity_app_release_deletion('${release.id}','${actor}')
    `);
    return result.rows[0];
}

function finishSql(release, claimed, overrides = {}) {
    const provider = overrides.provider ?? release.storage_provider;
    const bucket = overrides.bucket ?? release.storage_bucket;
    const path = overrides.path ?? release.storage_path;
    const uploadId = overrides.uploadId ?? release.provider_upload_id;
    const token = overrides.token ?? claimed.deletion_token;
    const uploadSql = uploadId === null ? 'null' : `'${uploadId}'`;

    return `select * from public.finish_unity_app_release_deletion(
        '${release.id}','${admin}','${token}','${provider}','${bucket}','${path}',${uploadSql}
    )`;
}

test('release deletion migration exposes only a two-step service-role boundary', () => {
    assert.match(migration, /add column if not exists deletion_requested_at/);
    assert.match(migration, /add column if not exists deletion_token uuid/);
    assert.match(migration, /create or replace function public\.begin_unity_app_release_deletion/);
    assert.match(migration, /create or replace function public\.finish_unity_app_release_deletion/);
    assert.match(migration, /security definer/g);
    assert.match(migration, /set search_path = pg_catalog, public/g);
    assert.match(migration, /grant execute on function public\.begin_unity_app_release_deletion[\s\S]*?to service_role/);
    assert.doesNotMatch(migration, /grant execute on function public\.(begin|finish)_unity_app_release_deletion[\s\S]*?to authenticated/);
    assert.doesNotMatch(migration, /grant delete on table public\.unity_app_releases/);
});

test('browser roles and direct table deletion remain closed', async (t) => {
    const db = await database(t);
    const release = await beginR2(db);

    await assert.rejects(
        () => asRole(db, 'authenticated', `
            select * from public.begin_unity_app_release_deletion('${release.id}','${admin}')
        `),
        /permission denied/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', `delete from public.unity_app_releases where id='${release.id}'`),
        /permission denied/i,
    );
    await assert.rejects(
        () => claim(db, release, student),
        /active administrator/i,
    );
});

test('claimed deletion is idempotent, blocks later release mutation, and binds final cleanup', async (t) => {
    const db = await database(t);
    const release = await beginR2(db);
    const claimed = await claim(db, release);
    const retried = await claim(db, release, otherAdmin);

    assert.equal(claimed.deletion_token, retried.deletion_token);
    assert.equal(claimed.deletion_requested_by, admin);
    assert.equal(claimed.is_active, false);

    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from public.attach_unity_app_release_upload_v2(
                '${release.id}','${admin}','upload-after-delete-claim'
            )
        `),
        /pending deletion/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', finishSql(release, claimed, { path: 'releases/android/wrong.apk' })),
        /storage identity no longer matches/i,
    );

    const deleted = (await asRole(db, 'service_role', finishSql(release, claimed))).rows[0];
    assert.equal(deleted.id, release.id);
    assert.equal((await asRole(db, 'service_role', `
        select count(*)::integer as count from public.unity_app_releases where id='${release.id}'
    `)).rows[0].count, 0);
});

test('the current release cannot be claimed for deletion', async (t) => {
    const db = await database(t);
    const release = await beginR2(db, '3.0.0');
    await asRole(db, 'service_role', `
        select * from public.attach_unity_app_release_upload_v2(
            '${release.id}','${admin}','active-release-upload'
        )
    `);
    await asRole(db, 'service_role', `
        select * from public.finalize_unity_app_release_v2(
            '${release.id}','${admin}',1024,'active-etag',null
        )
    `);

    await assert.rejects(
        () => claim(db, release),
        /make another version current/i,
    );
    const current = (await asRole(db, 'service_role', `
        select is_active,deletion_requested_at from public.unity_app_releases where id='${release.id}'
    `)).rows[0];
    assert.equal(current.is_active, true);
    assert.equal(current.deletion_requested_at, null);
});

test('migration is rerunnable and rollback refuses unresolved provider cleanup', async (t) => {
    const db = await database(t);
    await db.exec(deletionSql);
    const release = await beginR2(db, '4.0.0');
    const claimed = await claim(db, release);

    await assert.rejects(() => db.exec(rollbackSql), /pending unity release deletions/i);
    await db.exec('rollback');
    await asRole(db, 'service_role', finishSql(release, claimed));
    await db.exec(rollbackSql);

    const state = (await db.query(`
        select
            to_regprocedure('public.begin_unity_app_release_deletion(uuid,uuid)') as begin_rpc,
            exists(
                select 1 from information_schema.columns
                where table_schema='public' and table_name='unity_app_releases'
                  and column_name='deletion_token'
            ) as token_column,
            exists(
                select 1 from mathverse_schema_migrations
                where migration_key='2026_10_19_deletable_unity_app_releases.sql'
            ) as registered
    `)).rows[0];
    assert.equal(state.begin_rpc, null);
    assert.equal(state.token_column, false);
    assert.equal(state.registered, false);
});
