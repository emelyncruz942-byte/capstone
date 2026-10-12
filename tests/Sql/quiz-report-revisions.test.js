import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migrationSql = readFileSync(
    new URL('../../database/supabase/2026_10_12_teacher_quiz_report_revisions.sql', import.meta.url),
    'utf8',
);
const rollbackSql = readFileSync(
    new URL('../../database/supabase/2026_10_12_teacher_quiz_report_revisions_rollback.sql', import.meta.url),
    'utf8',
);
const migration = migrationSql.toLowerCase();
const rollback = rollbackSql.toLowerCase();

const creator = '11111111-1111-4111-8111-111111111111';
const reporter = '22222222-2222-4222-8222-222222222222';
const outsider = '33333333-3333-4333-8333-333333333333';
const admin = '44444444-4444-4444-8444-444444444444';
const quiz = '55555555-5555-4555-8555-555555555555';
const report = '66666666-6666-4666-8666-666666666666';

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
        insert into mathverse_schema_migrations (migration_key) values
            ('2026_09_13_recovery_and_incident_alerts.sql');

        create table profiles (
            id uuid primary key,
            role text not null,
            first_name text,
            last_name text,
            suspended_at timestamptz,
            deactivated_at timestamptz
        );
        create table quizzes (
            id uuid primary key,
            teacher_id uuid references profiles(id),
            topic text,
            grade_level integer,
            deleted_at timestamptz,
            updated_at timestamptz default now()
        );
        grant select on profiles to authenticated;
        create table quiz_reports (
            id uuid primary key default gen_random_uuid(),
            quiz_id uuid references quizzes(id) on delete set null,
            question_id bigint,
            reporter_id uuid references profiles(id) on delete set null,
            reason text not null,
            details text,
            status text not null default 'pending'
                check (status in ('pending','reviewed','dismissed')),
            reviewed_by uuid references profiles(id) on delete set null,
            reviewed_at timestamptz,
            quiz_topic text,
            quiz_grade_level integer,
            quiz_creator_id uuid references profiles(id) on delete set null,
            question_text text,
            created_at timestamptz not null default now()
        );
        alter table quiz_reports enable row level security;
        grant select, insert, update, delete on quiz_reports to authenticated, service_role;
        create policy quiz_reports_own_read on quiz_reports for select to authenticated
            using (reporter_id=auth.uid());

        create table notifications (
            id uuid primary key default gen_random_uuid(),
            user_id uuid references profiles(id),
            type text not null,
            title text not null,
            message text not null,
            action_url text,
            data jsonb not null default '{}'::jsonb,
            dedupe_key text,
            created_at timestamptz not null default now()
        );
        create table audit_logs (
            id bigint generated always as identity primary key,
            actor_id uuid references profiles(id),
            actor_role text,
            action text not null,
            target_type text not null,
            target_id text,
            metadata jsonb not null default '{}'::jsonb,
            created_at timestamptz not null default now()
        );

        create function create_notification(
            p_user_id uuid, p_type text, p_title text, p_message text,
            p_action_url text default null, p_data jsonb default '{}'::jsonb,
            p_dedupe_key text default null
        ) returns uuid language plpgsql security definer set search_path=public as $$
        declare created_id uuid;
        begin
            if p_user_id is null then return null; end if;
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
        create function quiz_management_url(p_user_id uuid)
        returns text language sql stable security definer set search_path=public as $$
            select case when role='admin' then '/admin/quizzes' else '/teacher/quizzes' end
            from profiles where id=p_user_id
        $$;

        insert into profiles(id,role,first_name,last_name) values
            ('${creator}','teacher','Tess','Creator'),
            ('${reporter}','teacher','Rita','Reporter'),
            ('${outsider}','teacher','Owen','Outsider'),
            ('${admin}','admin','Ari','Admin');
        insert into quizzes(id,teacher_id,topic,grade_level)
        values ('${quiz}','${creator}','Fraction Operations',5);
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

async function insertPendingReport(db) {
    await asService(db, `
        insert into quiz_reports(
            id,quiz_id,reporter_id,reason,details,status,
            quiz_topic,quiz_grade_level,quiz_creator_id,question_text
        ) values (
            '${report}','${quiz}','${reporter}','incorrect_answer',
            'The expected answer is incorrect.','pending',
            'Fraction Operations',5,'${creator}','What is one half plus one fourth?'
        )
    `);
}

test('revision migration keeps teacher acknowledgement separate from moderation', () => {
    assert.match(migration, /add column if not exists revised_at timestamp with time zone/);
    assert.match(migration, /add column if not exists revised_by uuid/);
    assert.match(migration, /target_report\.status <> 'pending'/);
    assert.match(migration, /set revised_at = changed_at,[\s\S]*?revised_by = p_teacher_id/);
    assert.doesNotMatch(migration, /set[\s\S]{0,80}status = 'reviewed'/);
    assert.match(migration, /quiz_report\.marked_revised/);
});

test('revision RPC is server-only and raw creator report reads stay closed', () => {
    assert.match(migration, /drop policy if exists quiz_reports_creator_read/);
    assert.doesNotMatch(migration, /create policy quiz_reports_creator_read/);
    assert.match(
        migration,
        /revoke all on function public\.mark_quiz_report_revised\(uuid, uuid\)[\s\S]*?from public, anon, authenticated/,
    );
    assert.match(
        migration,
        /grant execute on function public\.mark_quiz_report_revised\(uuid, uuid\)[\s\S]*?to service_role/,
    );
});

test('deleted quizzes and deactivated teachers cannot be marked revised', async (t) => {
    const db = await database(t);
    await insertPendingReport(db);

    await db.exec(`update quizzes set deleted_at=now() where id='${quiz}'`);
    await assert.rejects(
        () => asService(db, `select * from mark_quiz_report_revised('${report}','${creator}')`),
        /must be restored/i,
    );

    await db.exec(`update quizzes set deleted_at=null where id='${quiz}'`);
    await db.exec(`update profiles set deactivated_at=now() where id='${creator}'`);
    await assert.rejects(
        () => asService(db, `select * from mark_quiz_report_revised('${report}','${creator}')`),
        /not permitted/i,
    );

    const creatorRows = await asUser(db, creator, 'select id from quiz_reports');
    assert.equal(creatorRows.rows.length, 0);
});

test('teacher revision remains pending, is idempotent, and is visible to admins', async (t) => {
    const db = await database(t);
    await insertPendingReport(db);

    const first = (await asService(db, `
        select * from mark_quiz_report_revised('${report}','${creator}')
    `)).rows[0];
    assert.equal(first.report_id, report);
    assert.equal(first.report_status, 'pending');
    assert.equal(first.revision_changed, true);
    assert.ok(first.report_revised_at);

    const saved = (await asService(db, `
        select status,revised_at,revised_by from quiz_reports where id='${report}'
    `)).rows[0];
    assert.equal(saved.status, 'pending');
    assert.equal(saved.revised_by, creator);

    const repeated = (await asService(db, `
        select * from mark_quiz_report_revised('${report}','${creator}')
    `)).rows[0];
    assert.equal(repeated.revision_changed, false);
    assert.equal(
        new Date(repeated.report_revised_at).toISOString(),
        new Date(first.report_revised_at).toISOString(),
    );

    const audits = await db.query(`
        select count(*)::integer as count from audit_logs
        where action='quiz_report.marked_revised' and target_id='${report}'
    `);
    assert.equal(audits.rows[0].count, 1);

    const creatorRows = await asUser(db, creator, 'select id from quiz_reports');
    assert.equal(creatorRows.rows.length, 0);
    const reporterRows = await asUser(db, reporter, 'select id from quiz_reports');
    assert.equal(reporterRows.rows.length, 1);
    const outsiderRows = await asUser(db, outsider, 'select id from quiz_reports');
    assert.equal(outsiderRows.rows.length, 0);
});

test('wrong teacher, handled reports, and direct authenticated RPC calls fail closed', async (t) => {
    const db = await database(t);
    await insertPendingReport(db);

    await assert.rejects(
        () => asService(db, `select * from mark_quiz_report_revised('${report}','${outsider}')`),
        /not permitted/i,
    );
    await assert.rejects(
        () => asUser(db, creator, `select * from mark_quiz_report_revised('${report}','${creator}')`),
        /permission denied/i,
    );

    await asService(db, `update quiz_reports set status='reviewed' where id='${report}'`);
    await assert.rejects(
        () => asService(db, `select * from mark_quiz_report_revised('${report}','${creator}')`),
        /awaiting administrator review/i,
    );
});

test('quiz owner notifications open the teacher report details page', async (t) => {
    const db = await database(t);
    await insertPendingReport(db);

    const ownerNotification = (await db.query(`
        select action_url from notifications
        where user_id='${creator}' and type='quiz_reported'
    `)).rows[0];
    assert.equal(ownerNotification.action_url, `/teacher/quiz-reports/${report}`);

    const adminNotification = (await db.query(`
        select action_url from notifications
        where user_id='${admin}' and type='quiz_report_submitted'
    `)).rows[0];
    assert.equal(adminNotification.action_url, `/admin/quiz-reports/${report}`);
});

test('rollback archives revision history and removes the workflow safely', async (t) => {
    const db = await database(t);
    await insertPendingReport(db);
    await asService(db, `select * from mark_quiz_report_revised('${report}','${creator}')`);

    await db.exec(rollbackSql);

    const state = (await db.query(`
        select
            exists(
                select 1 from information_schema.columns
                where table_schema='public' and table_name='quiz_reports'
                  and column_name='revised_at'
            ) as revised_column_exists,
            to_regprocedure('public.mark_quiz_report_revised(uuid,uuid)') as rpc,
            exists(
                select 1 from mathverse_schema_migrations
                where migration_key='2026_10_12_teacher_quiz_report_revisions.sql'
            ) as registered
    `)).rows[0];
    assert.equal(state.revised_column_exists, false);
    assert.equal(state.rpc, null);
    assert.equal(state.registered, false);

    await assert.rejects(
        () => asService(db, 'select * from rollback_quiz_report_revisions_20261012'),
        /permission denied/i,
    );
    assert.match(rollback, /force row level security/);
    assert.match(rollback, /revoke all privileges[\s\S]*?from public, anon, authenticated, service_role/);
});
