import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const baseMigrationSql = readFileSync(
    new URL('../../database/supabase/2026_10_11_versioned_unity_app_releases.sql', import.meta.url),
    'utf8',
);
const migrationSql = readFileSync(
    new URL('../../database/supabase/2026_10_12_cloudflare_r2_app_releases.sql', import.meta.url),
    'utf8',
);
const rollbackSql = readFileSync(
    new URL('../../database/supabase/2026_10_12_cloudflare_r2_app_releases_rollback.sql', import.meta.url),
    'utf8',
);
const securityHardeningSql = readFileSync(
    new URL('../../database/supabase/2026_09_05_security_hardening.sql', import.meta.url),
    'utf8',
);
const migration = migrationSql.toLowerCase();
const rollback = rollbackSql.toLowerCase();
const securityHardening = securityHardeningSql.toLowerCase();

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

    await db.exec(baseMigrationSql);
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

async function beginR2(
    db,
    {
        actor = admin,
        version = '2.0.0',
        platform = 'android',
        kind = 'apk',
        filename = 'MathVerse-2.0.0.apk',
        size = 350_000_000,
        mime = 'application/vnd.android.package-archive',
        partSize = 16 * 1024 * 1024,
        bucket = 'mathverse-releases',
    } = {},
) {
    const result = await asRole(db, 'service_role', `
        select * from public.begin_unity_app_release_v2(
            '${actor}', '${version}', '${platform}', '${kind}',
            '${filename}', ${size}, 'R2 release notes', '${mime}', ${partSize}, '${bucket}'
        )
    `);
    return result.rows[0];
}

async function attachR2(db, release, uploadId = 'r2-multipart-upload-1') {
    const result = await asRole(db, 'service_role', `
        select * from public.attach_unity_app_release_upload_v2(
            '${release.id}', '${admin}', '${uploadId}'
        )
    `);
    return result.rows[0];
}

async function finalizeR2(db, release, etag = '"r2-etag-22"') {
    const result = await asRole(db, 'service_role', `
        select * from public.finalize_unity_app_release_v2(
            '${release.id}', '${admin}', ${release.expected_size_bytes}, '${etag}', null
        )
    `);
    return result.rows[0];
}

async function beginLegacy(db, version = '1.0.0') {
    const result = await asRole(db, 'service_role', `
        select * from public.begin_unity_app_release(
            '${admin}', '${version}', 'android', 'apk',
            'MathVerse-${version}.apk', 1024, 'Legacy notes',
            'application/vnd.android.package-archive'
        )
    `);
    return result.rows[0];
}

test('R2 migration adds a provider-aware server-only boundary without querying storage.objects', () => {
    assert.match(migration, /add column if not exists storage_provider text not null default 'supabase'/);
    assert.match(migration, /unique \(storage_provider, storage_bucket, storage_path\)/);
    assert.match(migration, /add column if not exists provider_upload_id text/);
    assert.match(migration, /add column if not exists object_etag text/);
    assert.match(migration, /add column if not exists multipart_part_size bigint/);
    assert.match(migration, /create or replace function public\.begin_unity_app_release_v2/);
    assert.match(migration, /create or replace function public\.attach_unity_app_release_upload_v2/);
    assert.match(migration, /create or replace function public\.finalize_unity_app_release_v2/);
    assert.match(migration, /create or replace function public\.fail_unity_app_release_v2/);
    assert.match(migration, /create or replace function public\.finish_unity_app_release_cleanup_v2/);
    assert.match(migration, /create or replace function public\.activate_unity_app_release_v2/);
    assert.doesNotMatch(migration, /from storage\.objects|join storage\.objects/);
    assert.match(migration, /security definer/g);
    assert.match(migration, /set search_path = pg_catalog, public/g);
    assert.match(
        migration,
        /grant execute on function public\.finalize_unity_app_release_v2[\s\S]*?to service_role/,
    );
    assert.match(
        migration,
        /grant execute on function public\.finish_unity_app_release_cleanup_v2[\s\S]*?to service_role/,
    );
    assert.match(
        migration,
        /revoke all on function public\.finish_unity_app_release_cleanup_v2[\s\S]*?from public, anon, authenticated, service_role/,
    );
    assert.doesNotMatch(
        migration,
        /grant execute on function public\.finish_unity_app_release_cleanup_v2[\s\S]*?to authenticated/,
    );
    assert.doesNotMatch(
        migration,
        /grant execute on function public\..*_unity_app_release_v2[\s\S]*?to authenticated/,
    );
    assert.match(
        securityHardening,
        /mathverse_functions constant text\[\][\s\S]*?'finish_unity_app_release_cleanup_v2'/,
    );
});

test('migration is rerunnable and legacy routines keep creating Supabase rows', async (t) => {
    const db = await database(t);
    await db.exec(migrationSql);

    const legacy = await beginLegacy(db);
    assert.equal(legacy.storage_provider, 'supabase');
    assert.equal(legacy.storage_bucket, 'unity-releases');
    assert.equal(legacy.provider_upload_id, null);
    assert.equal(legacy.object_etag, null);
    assert.equal(legacy.multipart_part_size, null);

    await assert.rejects(
        () => asRole(db, 'authenticated', 'select * from unity_app_releases'),
        /permission denied/i,
    );
    await assert.rejects(
        () => asRole(db, 'authenticated', `
            select public.begin_unity_app_release_v2(
                '${admin}','3.0.0','android','apk','MathVerse.apk',1024,
                null,'application/vnd.android.package-archive',16777216,'mathverse-releases'
            )
        `),
        /permission denied/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', `
            update unity_app_releases set storage_provider='r2' where id='${legacy.id}'
        `),
        /permission denied/i,
    );
});

test('R2 flow persists only an opaque upload ID and finalizes server-verified size and ETag', async (t) => {
    const db = await database(t);
    const release = await beginR2(db);
    assert.equal(release.storage_provider, 'r2');
    assert.equal(release.storage_bucket, 'mathverse-releases');
    assert.equal(release.provider_upload_id, null);
    assert.equal(release.object_etag, null);
    assert.equal(Number(release.multipart_part_size), 16 * 1024 * 1024);

    await assert.rejects(
        () => finalizeR2(db, release),
        /not attached/i,
    );

    const attached = await attachR2(db, release);
    assert.equal(attached.provider_upload_id, 'r2-multipart-upload-1');
    const repeatedAttach = await attachR2(db, release);
    assert.equal(repeatedAttach.provider_upload_id, 'r2-multipart-upload-1');
    await assert.rejects(
        () => attachR2(db, release, 'different-upload'),
        /different multipart upload/i,
    );

    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from public.finalize_unity_app_release_v2(
                '${release.id}','${admin}',349999999,'"r2-etag-22"',null
            )
        `),
        /size does not match/i,
    );

    const finalized = await finalizeR2(db, release);
    assert.equal(finalized.status, 'ready');
    assert.equal(finalized.is_active, true);
    assert.equal(finalized.provider_upload_id, null);
    assert.equal(finalized.object_etag, '"r2-etag-22"');
    assert.equal(Number(finalized.size_bytes), 350_000_000);

    const objects = await db.query('select count(*)::integer as count from storage.objects');
    assert.equal(objects.rows[0].count, 0);

    const repeated = await finalizeR2(db, release);
    assert.equal(repeated.id, release.id);
    await assert.rejects(
        () => finalizeR2(db, release, '"different-etag"'),
        /does not match this verification/i,
    );
});

test('R2 activation requires the freshly verified exact size and ETag', async (t) => {
    const db = await database(t);
    const first = await beginR2(db, { version: '2.1.0', filename: 'MathVerse-2.1.0.apk' });
    await attachR2(db, first, 'upload-first');
    await finalizeR2(db, first, '"etag-first"');

    const second = await beginR2(db, { version: '2.2.0', filename: 'MathVerse-2.2.0.apk' });
    await attachR2(db, second, 'upload-second');
    await finalizeR2(db, second, '"etag-second"');

    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from public.activate_unity_app_release_v2(
                '${first.id}','${admin}',${first.expected_size_bytes},'"wrong"'
            )
        `),
        /no longer matches/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from public.activate_unity_app_release_v2(
                '${first.id}','${admin}',${Number(first.expected_size_bytes) - 1},'"etag-first"'
            )
        `),
        /no longer matches/i,
    );

    const activated = (await asRole(db, 'service_role', `
        select * from public.activate_unity_app_release_v2(
            '${first.id}','${otherAdmin}',${first.expected_size_bytes},'"etag-first"'
        )
    `)).rows[0];
    assert.equal(activated.is_active, true);

    const active = await asRole(db, 'service_role', `
        select id from unity_app_releases where platform='android' and is_active
    `);
    assert.deepEqual(active.rows.map((row) => row.id), [first.id]);
});

test('failed R2 uploads retain cleanup state until exact cleanup is durably finished', async (t) => {
    const db = await database(t);
    const release = await beginR2(db, { version: '2.3.0', filename: 'MathVerse-2.3.0.apk' });
    await attachR2(db, release, 'upload-to-abort');

    const failed = (await asRole(db, 'service_role', `
        select * from public.fail_unity_app_release_v2(
            '${release.id}','${admin}','The multipart upload was aborted.'
        )
    `)).rows[0];
    assert.equal(failed.status, 'failed');
    assert.equal(failed.provider_upload_id, 'upload-to-abort');
    assert.equal(failed.object_etag, null);

    const repeatedFailure = (await asRole(db, 'service_role', `
        select * from public.fail_unity_app_release_v2(
            '${release.id}','${admin}','A repeated failure report.'
        )
    `)).rows[0];
    assert.equal(repeatedFailure.provider_upload_id, 'upload-to-abort');

    const retry = await beginR2(db, { version: '2.3.0', filename: 'Replacement-2.3.0.apk' });
    assert.notEqual(retry.id, release.id);
    assert.equal(retry.status, 'uploading');

    await assert.rejects(
        () => asRole(db, 'authenticated', `
            select * from public.finish_unity_app_release_cleanup_v2(
                '${release.id}','${admin}','upload-to-abort'
            )
        `),
        /permission denied/i,
    );
    await assert.rejects(
        () => asRole(db, 'anon', `
            select * from public.finish_unity_app_release_cleanup_v2(
                '${release.id}','${admin}','upload-to-abort'
            )
        `),
        /permission denied/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from public.finish_unity_app_release_cleanup_v2(
                '${release.id}','${student}','upload-to-abort'
            )
        `),
        /active administrator/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from public.finish_unity_app_release_cleanup_v2(
                '${release.id}','${admin}',null
            )
        `),
        /no longer matches/i,
    );
    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from public.finish_unity_app_release_cleanup_v2(
                '${release.id}','${admin}','different-upload'
            )
        `),
        /no longer matches/i,
    );

    const stillPending = (await asRole(db, 'service_role', `
        select provider_upload_id from public.unity_app_releases where id='${release.id}'
    `)).rows[0];
    assert.equal(stillPending.provider_upload_id, 'upload-to-abort');

    const cleaned = (await asRole(db, 'service_role', `
        select * from public.finish_unity_app_release_cleanup_v2(
            '${release.id}','${admin}','upload-to-abort'
        )
    `)).rows[0];
    assert.equal(cleaned.status, 'failed');
    assert.equal(cleaned.provider_upload_id, null);

    const repeatedCleanup = (await asRole(db, 'service_role', `
        select * from public.finish_unity_app_release_cleanup_v2(
            '${release.id}','${admin}','upload-to-abort'
        )
    `)).rows[0];
    assert.equal(repeatedCleanup.provider_upload_id, null);

    await assert.rejects(
        () => beginR2(db, {
            actor: student,
            version: '4.0.0',
            filename: 'MathVerse-4.0.0.apk',
        }),
        /active administrator/i,
    );
    await assert.rejects(
        () => beginR2(db, {
            version: '4.0.0',
            filename: 'MathVerse-4.0.0.apk',
            bucket: 'INVALID_BUCKET',
        }),
        /bucket name is invalid/i,
    );
});

test('rollback archives R2 metadata, retains legacy releases, and restores the Supabase-only schema', async (t) => {
    const db = await database(t);
    const legacy = await beginLegacy(db);
    const r2 = await beginR2(db, { version: '5.0.0', filename: 'MathVerse-5.0.0.apk' });
    await attachR2(db, r2, 'upload-before-rollback');
    await asRole(db, 'service_role', `
        select * from public.fail_unity_app_release_v2(
            '${r2.id}','${admin}','Cleanup is still pending during rollback.'
        )
    `);

    await db.exec(rollbackSql);

    const state = (await db.query(`
        select
            exists(
                select 1 from information_schema.columns
                where table_schema='public' and table_name='unity_app_releases'
                  and column_name='storage_provider'
            ) as provider_column_exists,
            to_regprocedure(
                'public.begin_unity_app_release_v2(uuid,text,text,text,text,bigint,text,text,bigint,text)'
            ) as begin_rpc,
            to_regprocedure(
                'public.finish_unity_app_release_cleanup_v2(uuid,uuid,text)'
            ) as cleanup_rpc,
            exists(
                select 1 from mathverse_schema_migrations
                where migration_key='2026_10_12_cloudflare_r2_app_releases.sql'
            ) as registered,
            (select count(*)::integer from unity_app_releases) as live_count,
            (select count(*)::integer
                from rollback_cloudflare_r2_unity_releases_20261012) as archived_count,
            (select provider_upload_id
                from rollback_cloudflare_r2_unity_releases_20261012
                where id='${r2.id}') as archived_upload_id,
            (select status
                from rollback_cloudflare_r2_unity_releases_20261012
                where id='${r2.id}') as archived_status,
            (select failure_reason
                from rollback_cloudflare_r2_unity_releases_20261012
                where id='${r2.id}') as archived_failure_reason
    `)).rows[0];
    assert.equal(state.provider_column_exists, false);
    assert.equal(state.begin_rpc, null);
    assert.equal(state.cleanup_rpc, null);
    assert.equal(state.registered, false);
    assert.equal(state.live_count, 1);
    assert.equal(state.archived_count, 1);
    assert.equal(state.archived_upload_id, 'upload-before-rollback');
    assert.equal(state.archived_status, 'failed');
    assert.equal(state.archived_failure_reason, 'Cleanup is still pending during rollback.');

    const retained = (await asRole(db, 'service_role', `
        select id,storage_bucket from unity_app_releases
    `)).rows[0];
    assert.equal(retained.id, legacy.id);
    assert.equal(retained.storage_bucket, 'unity-releases');

    await assert.rejects(
        () => asRole(db, 'service_role', `
            select * from rollback_cloudflare_r2_unity_releases_20261012
        `),
        /permission denied/i,
    );
    assert.match(rollback, /objects in r2 are never[\s\S]*?deleted here/);
    assert.match(rollback, /provider_upload_id still present is[\s\S]*?unfinished external cleanup/);
    assert.match(rollback, /force row level security/);
    assert.match(rollback, /revoke all privileges[\s\S]*?from public, anon, authenticated, service_role/);
});
