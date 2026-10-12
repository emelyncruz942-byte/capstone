import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migrationSql = readFileSync(
    new URL('../../database/supabase/2026_10_11_versioned_unity_app_releases.sql', import.meta.url),
    'utf8',
);
const rollbackSql = readFileSync(
    new URL('../../database/supabase/2026_10_11_versioned_unity_app_releases_rollback.sql', import.meta.url),
    'utf8',
);
const migration = migrationSql.toLowerCase();
const rollback = rollbackSql.toLowerCase();

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
        create policy broad_bucket_policy on storage.buckets
            for all to anon,authenticated using(true) with check(true);
        create policy broad_object_policy on storage.objects
            for all to anon,authenticated using(true) with check(true);
    `);

    await db.exec(migrationSql);
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

async function beginRelease(
    db,
    {
        actor = admin,
        version = '1.0.0',
        platform = 'android',
        kind = 'apk',
        filename = 'MathVerse-1.0.0.apk',
        size = 1024,
        mime = null,
    } = {},
) {
    const mimeSql = mime === null ? 'null' : `'${mime}'`;
    const result = await asRole(db, 'service_role', `
        select * from public.begin_unity_app_release(
            '${actor}', '${version}', '${platform}', '${kind}',
            '${filename}', ${size}, 'Release notes', ${mimeSql}
        )
    `);
    return result.rows[0];
}

async function addStorageObject(db, release, size = release.expected_size_bytes) {
    await db.exec(`
        insert into storage.objects(bucket_id,name,metadata)
        values (
            '${release.storage_bucket}',
            '${release.storage_path}',
            jsonb_build_object('size', ${size})
        )
    `);
}

test('migration declares a private, server-only, versioned release boundary', () => {
    assert.match(migration, /insert into storage\.buckets/);
    assert.match(migration, /'unity-releases'[\s\S]*?false/);
    assert.match(migration, /as restrictive[\s\S]*?to anon, authenticated/);
    assert.match(migration, /create table if not exists public\.unity_app_releases/);
    assert.match(migration, /status in \('uploading', 'ready', 'failed'\)/);
    assert.match(migration, /one_active_per_platform_unique[\s\S]*?on public\.unity_app_releases \(platform\)[\s\S]*?where is_active/);
    assert.match(migration, /alter table public\.unity_app_releases force row level security/);
    assert.match(
        migration,
        /revoke all privileges on table public\.unity_app_releases\s+from public, anon, authenticated, service_role/,
    );
    assert.match(migration, /grant select on table public\.unity_app_releases to service_role/);
    assert.doesNotMatch(migration, /grant .*unity_app_releases to (anon|authenticated)/);
    assert.match(migration, /security definer/g);
    assert.match(migration, /set search_path = pg_catalog, public/g);
    assert.match(migration, /grant execute on function public\.finalize_unity_app_release[\s\S]*?to service_role/);
    assert.doesNotMatch(migration, /grant execute on function public\.(begin|finalize|fail|activate)_unity_app_release[\s\S]*?to authenticated/);
});

test('migration is rerunnable and browser roles cannot read metadata or private objects', async (t) => {
    const db = await database(t);
    await db.exec(migrationSql);

    const release = await beginRelease(db);
    await addStorageObject(db, release);

    await assert.rejects(
        () => asRole(db, 'authenticated', 'select * from unity_app_releases'),
        /permission denied/i,
    );

    const browserObjects = await asRole(
        db,
        'authenticated',
        "select name from storage.objects where bucket_id='unity-releases'",
    );
    assert.equal(browserObjects.rows.length, 0);

    const bucket = (await db.query(`
        select public,file_size_limit from storage.buckets where id='unity-releases'
    `)).rows[0];
    assert.equal(bucket.public, false);
    assert.equal(Number(bucket.file_size_limit), 5368709120);

    await assert.rejects(
        () => asRole(db, 'authenticated', `
            select public.activate_unity_app_release('${release.id}','${admin}')
        `),
        /permission denied/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', `
            update unity_app_releases set release_notes='direct write' where id='${release.id}'
        `),
        /permission denied/i,
    );
});

test('finalization verifies storage and atomically switches one active ready release per platform', async (t) => {
    const db = await database(t);
    const first = await beginRelease(db);

    await assert.rejects(
        () => asRole(db, 'service_role', `
            select public.finalize_unity_app_release('${first.id}','${admin}',1024,null)
        `),
        /not found in private storage/i,
    );

    await addStorageObject(db, first, 1000);
    await assert.rejects(
        () => asRole(db, 'service_role', `
            select public.finalize_unity_app_release('${first.id}','${admin}',1024,null)
        `),
        /size does not match/i,
    );
    await db.exec(`
        update storage.objects
        set metadata=jsonb_build_object('size',1024)
        where bucket_id='${first.storage_bucket}' and name='${first.storage_path}'
    `);

    const finalizedFirst = (await asRole(db, 'service_role', `
        select * from public.finalize_unity_app_release('${first.id}','${admin}',1024,null)
    `)).rows[0];
    assert.equal(finalizedFirst.status, 'ready');
    assert.equal(finalizedFirst.is_active, true);
    assert.equal(Number(finalizedFirst.size_bytes), 1024);
    assert.equal(finalizedFirst.sha256, null);

    const repeated = (await asRole(db, 'service_role', `
        select * from public.finalize_unity_app_release('${first.id}','${admin}',1024,null)
    `)).rows[0];
    assert.equal(repeated.id, first.id);
    assert.equal(repeated.is_active, true);

    const second = await beginRelease(db, {
        version: '1.1.0',
        platform: 'ios',
        kind: 'ipa',
        filename: 'MathVerse-1.1.0.ipa',
        size: 2048,
        mime: 'application/x-itunes-ipa',
    });
    await addStorageObject(db, second, 2048);
    const finalizedSecond = (await asRole(db, 'service_role', `
        select * from public.finalize_unity_app_release('${second.id}','${otherAdmin}',2048,null)
    `)).rows[0];
    assert.equal(finalizedSecond.is_active, true);

    let ready = (await asRole(db, 'service_role', `
        select id,platform,is_active,status from unity_app_releases order by created_at
    `)).rows;
    assert.equal(ready.filter((release) => release.is_active).length, 2);
    assert.equal(ready.find((release) => release.platform === 'android' && release.is_active).id, first.id);
    assert.equal(ready.find((release) => release.platform === 'ios' && release.is_active).id, second.id);

    const newerAndroid = await beginRelease(db, {
        version: '1.2.0',
        filename: 'MathVerse-1.2.0.apk',
        size: 3072,
    });
    await addStorageObject(db, newerAndroid, 3072);
    await asRole(db, 'service_role', `
        select * from public.finalize_unity_app_release('${newerAndroid.id}','${admin}',3072,null)
    `);

    ready = (await asRole(db, 'service_role', `
        select id,platform,is_active,status from unity_app_releases order by created_at
    `)).rows;
    assert.equal(ready.filter((release) => release.is_active).length, 2);
    assert.equal(ready.find((release) => release.platform === 'android' && release.is_active).id, newerAndroid.id);
    assert.equal(ready.find((release) => release.platform === 'ios' && release.is_active).id, second.id);

    const reverted = (await asRole(db, 'service_role', `
        select * from public.activate_unity_app_release('${first.id}','${admin}')
    `)).rows[0];
    assert.equal(reverted.id, first.id);
    assert.equal(reverted.is_active, true);

    ready = (await asRole(db, 'service_role', `
        select id,platform,is_active,status from unity_app_releases order by created_at
    `)).rows;
    assert.equal(ready.filter((release) => release.is_active).length, 2);
    assert.equal(ready.find((release) => release.platform === 'android' && release.is_active).id, first.id);
    assert.equal(ready.find((release) => release.platform === 'ios' && release.is_active).id, second.id);
});

test('release state changes require an active admin and preserve failed versions', async (t) => {
    const db = await database(t);

    await assert.rejects(
        () => beginRelease(db, { actor: student }),
        /active administrator/i,
    );
    await assert.rejects(
        () => beginRelease(db, {
            platform: 'ios',
            kind: 'apk',
            filename: 'wrong.apk',
        }),
        /do not match/i,
    );

    const failed = await beginRelease(db, {
        version: '2.0.0',
        filename: 'MathVerse-2.0.0.apk',
    });
    const completed = (await asRole(db, 'service_role', `
        select * from public.fail_unity_app_release(
            '${failed.id}','${admin}','Storage upload was interrupted.'
        )
    `)).rows[0];
    assert.equal(completed.status, 'failed');
    assert.equal(completed.is_active, false);
    assert.ok(completed.completed_at);
    assert.equal(completed.failure_reason, 'Storage upload was interrupted.');

    const retry = await beginRelease(db, {
        version: '2.0.0',
        filename: 'Replacement.apk',
    });
    assert.equal(retry.status, 'uploading');
    assert.notEqual(retry.id, failed.id);

    await assert.rejects(
        () => beginRelease(db, {
            version: '2.0.0',
            filename: 'Another-Replacement.apk',
        }),
        /duplicate key|unique constraint/i,
    );
});

test('rollback archives metadata and keeps the private bucket boundary', async (t) => {
    const db = await database(t);
    const release = await beginRelease(db);
    await db.exec(rollbackSql);

    const state = (await db.query(`
        select
            to_regclass('public.unity_app_releases') as release_table,
            to_regclass('public.rollback_unity_app_releases_20261011') as archive_table,
            (select public from storage.buckets where id='unity-releases') as bucket_public,
            exists(
                select 1 from mathverse_schema_migrations
                where migration_key='2026_10_11_versioned_unity_app_releases.sql'
            ) as registered,
            (select count(*)::integer from rollback_unity_app_releases_20261011) as archived
    `)).rows[0];
    assert.equal(state.release_table, null);
    assert.ok(state.archive_table);
    assert.equal(state.bucket_public, false);
    assert.equal(state.registered, false);
    assert.equal(state.archived, 1);

    await assert.rejects(
        () => asRole(db, 'service_role', 'select * from rollback_unity_app_releases_20261011'),
        /permission denied/i,
    );
    assert.match(rollback, /do not delete rows directly from storage\.objects/);
    assert.match(rollback, /private bucket and restrictive browser policies deliberately stay/);
    assert.equal(release.status, 'uploading');
});
