import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(new URL('../../database/supabase/2026_09_13_recovery_and_incident_alerts.sql', import.meta.url), 'utf8');
const auditMigration = readFileSync(new URL('../../database/supabase/2026_09_12_platform_insights_and_durable_audit.sql', import.meta.url), 'utf8');
const admin = '11111111-1111-4111-8111-111111111111';
const teacher = '22222222-2222-4222-8222-222222222222';
const student = '33333333-3333-4333-8333-333333333333';
const otherTeacher = '44444444-4444-4444-8444-444444444444';
const classId = '55555555-5555-4555-8555-555555555555';
const quizId = '66666666-6666-4666-8666-666666666666';
const sessionId = '77777777-7777-4777-8777-777777777777';

async function database() {
    const db = new PGlite();
    await db.exec(`
        create role anon; create role authenticated; create role service_role;
        create schema auth; grant usage on schema auth to authenticated;
        create function auth.uid() returns uuid language sql stable as $$
            select nullif(current_setting('request.jwt.claim.sub', true), '')::uuid; $$;
        create table profiles (id uuid primary key, role text, first_name text, last_name text, email text,
            grade_level integer, class_id uuid, suspended_at timestamptz, auth_sessions_invalid_before timestamptz);
        create table classes (id uuid primary key, teacher_id uuid references profiles(id), class_name text,
            grade_level integer, archived_at timestamptz);
        create table quizzes (id uuid primary key, teacher_id uuid references profiles(id), topic text, visibility text, grade_level integer);
        create table class_members (class_id uuid references classes(id) on delete cascade,
            student_id uuid references profiles(id) on delete cascade, primary key (class_id,student_id));
        create table class_customizations (class_id uuid primary key references classes(id) on delete cascade, icon text);
        create table quiz_questions (id bigint primary key, quiz_id uuid references quizzes(id) on delete cascade, question text);
        create table quiz_versions (id uuid primary key default gen_random_uuid(), quiz_id uuid references quizzes(id) on delete cascade, snapshot jsonb);
        create table quiz_bookmarks (quiz_id uuid references quizzes(id) on delete cascade, user_id uuid references profiles(id));
        create table quiz_ratings (quiz_id uuid references quizzes(id) on delete cascade, rating integer);
        create table quiz_sessions (id uuid primary key, class_id uuid references classes(id) on delete cascade,
            teacher_id uuid references profiles(id), source_quiz_id uuid references quizzes(id) on delete set null,
            status text, is_active boolean, retake_mode boolean, ended_at timestamptz);
        create table quiz_results (id uuid primary key default gen_random_uuid(), session_id uuid references quiz_sessions(id) on delete cascade,
            student_id uuid references profiles(id) on delete cascade, correct_answers integer);
        create table quiz_reports (id uuid primary key default gen_random_uuid(), quiz_id uuid references quizzes(id) on delete set null,
            status text, reviewed_by uuid, reviewed_at timestamptz);
        create table audit_logs (id bigint generated always as identity primary key, actor_id uuid references profiles(id) on delete set null,
            actor_role text, actor_name text, action text, target_type text, target_id text, metadata jsonb,
            event_category text, severity text, outcome text, correlation_id uuid, created_at timestamptz default now());
        create table privileged_audit_outbox (id uuid primary key, actor_id uuid, actor_role text, actor_name text,
            action text, target_type text, target_id text, metadata jsonb, status text default 'pending',
            completion_metadata jsonb default '{}', last_error text, created_at timestamptz default now(), completed_at timestamptz);
        create table notifications (user_id uuid, dedupe_key text, title text, unique(user_id,dedupe_key));
        create table notification_deliveries (id uuid primary key default gen_random_uuid(), event_type text,
            status text, locked_at timestamptz);
        create table mathverse_schema_migrations (migration_key text primary key);
        create function notify_all_admins(p_type text,p_title text,p_message text,p_action_url text,p_data jsonb,p_dedupe_key text)
            returns void language sql as $$ insert into notifications select id,p_dedupe_key,p_title from profiles
                where role='admin' and suspended_at is null on conflict do nothing; $$;
        alter table profiles enable row level security;
        alter table classes enable row level security;
        alter table quizzes enable row level security;
        grant select on profiles, classes, quizzes to authenticated;
        create policy baseline_read on profiles for select to authenticated using (id=auth.uid());
        create policy baseline_read on classes for select to authenticated using (true);
        create policy baseline_read on quizzes for select to authenticated using (true);
        insert into profiles (id,role,first_name,last_name,email,grade_level,class_id) values
            ('${admin}','admin','A','Admin','admin@example.test',null,null),
            ('${teacher}','teacher','T','Teacher','teacher@example.test',null,null),
            ('${student}','student','S','Student','student@example.test',4,'${classId}'),
            ('${otherTeacher}','teacher','O','Teacher','other@example.test',null,null);
        insert into classes values ('${classId}','${teacher}','Orion',4,null);
        insert into quizzes values ('${quizId}','${teacher}','Fractions','shared',4);
        insert into class_members values ('${classId}','${student}');
        insert into class_customizations values ('${classId}','rocket');
        insert into quiz_questions values (1,'${quizId}','A preserved question');
        insert into quiz_versions (quiz_id,snapshot) values ('${quizId}','{"topic":"Fractions"}');
        insert into quiz_bookmarks values ('${quizId}','${otherTeacher}');
        insert into quiz_ratings values ('${quizId}',5);
        insert into quiz_sessions values ('${sessionId}','${classId}','${teacher}','${quizId}','active',true,false,null);
        insert into quiz_results (session_id,student_id,correct_answers) values ('${sessionId}','${student}',8);
        insert into quiz_reports (quiz_id,status) values ('${quizId}','pending');
    `);
    await db.exec(auditMigration.slice(auditMigration.indexOf('create or replace function public.create_privileged_audit_intent('), auditMigration.indexOf('create or replace function public.search_audit_logs(')));
    await db.exec(migration);
    return db;
}
async function transition(db, actor, kind, id, restore = false) {
    return (await db.query('select set_recovery_item($1::uuid,$2,$3::uuid,$4) as data', [actor,kind,id,restore])).rows[0].data;
}
async function asStudent(db, query, issuedAt = Math.floor(Date.now()/1000)) {
    await db.query("select set_config('request.jwt.claim.sub',$1,false)", [student]);
    await db.query("select set_config('request.jwt.claims',$1,false)", [JSON.stringify({sub:student,iat:issuedAt})]);
    await db.exec('set role authenticated');
    try { return (await db.query(query)).rows; }
    finally { await db.exec('reset role'); }
}

test('class trash is atomic, retains graph and restores archived without restarting quizzes', async () => {
    const db = await database();
    try {
        await assert.rejects(transition(db, otherTeacher, 'class', classId), /Record not found/);
        await assert.rejects(transition(db, student, 'class', classId), /current teacher/);
        await transition(db, teacher, 'class', classId);
        assert.equal((await asStudent(db, 'select id from classes')).length,0);
        for (const table of ['classes','class_members','class_customizations','quiz_sessions','quiz_results']) {
            assert.equal((await db.query(`select count(*)::integer as n from ${table}`)).rows[0].n,1);
        }
        assert.equal((await db.query(`select class_id from profiles where id='${student}'`)).rows[0].class_id,null);
        assert.equal((await db.query('select status from quiz_sessions')).rows[0].status,'completed');
        await assert.rejects(db.exec("update quiz_sessions set status='waiting'"),/cannot restart/);
        await assert.rejects(db.exec('update classes set archived_at=null'),/Restore the class/);
        await assert.rejects(db.exec(`insert into quiz_sessions (id,class_id,source_quiz_id) values (gen_random_uuid(),'${classId}','${quizId}')`),/archived/);
        await transition(db, teacher, 'class', classId,true);
        const restored = (await db.query('select deleted_at,archived_at from classes')).rows[0];
        assert.equal(restored.deleted_at,null); assert.ok(restored.archived_at);
        assert.equal((await db.query('select status from quiz_sessions')).rows[0].status,'completed');
        assert.deepEqual((await db.query('select action from audit_logs order by id')).rows.map(r=>r.action),['class.trashed','class.trash_restored']);
        await assert.rejects(db.exec('delete from classes'),/Trash/);
        // Missing audit storage rolls the mutation back, rather than losing the trail.
        await db.exec('alter table audit_logs rename to missing_audit_logs');
        await assert.rejects(transition(db, teacher,'class',classId),/audit_logs/);
        assert.equal((await db.query('select deleted_at from classes')).rows[0].deleted_at,null);
    } finally { await db.close(); }
});

test('quiz moderation trash preserves content and assignments and cannot be undone by a teacher', async () => {
    const db = await database();
    try {
        await transition(db,admin,'quiz',quizId);
        assert.equal((await asStudent(db,'select id from quizzes')).length,0);
        await assert.rejects(transition(db,teacher,'quiz',quizId,true),/Administrator restoration/);
        await assert.rejects(db.exec("update quizzes set topic='Changed'"),/Restore the quiz/);
        await assert.rejects(db.exec("update quiz_questions set question='Changed'"),/Restore the quiz/);
        await assert.rejects(db.exec('delete from quiz_versions'),/Restore the quiz/);
        await assert.rejects(db.exec(`insert into quiz_sessions (id,source_quiz_id) values (gen_random_uuid(),'${quizId}')`),/source quiz/);
        for (const table of ['quizzes','quiz_questions','quiz_versions','quiz_bookmarks','quiz_ratings','quiz_results']) {
            assert.equal((await db.query(`select count(*)::integer as n from ${table}`)).rows[0].n,1);
        }
        assert.equal((await db.query('select source_quiz_id from quiz_sessions')).rows[0].source_quiz_id,quizId);
        assert.equal((await db.query('select status from quiz_reports')).rows[0].status,'reviewed');
        await transition(db,admin,'quiz',quizId,true);
        assert.equal((await db.query('select deleted_at from quizzes')).rows[0].deleted_at,null);
        await db.exec("update quizzes set visibility='private'");
        await assert.rejects(transition(db,admin,'quiz',quizId),/Record not found/);
        await transition(db,teacher,'quiz',quizId);
        await transition(db,teacher,'quiz',quizId,true);
        await db.exec(migration); // deployment retry must not reset retained state
    } finally { await db.close(); }
});

test('deactivation blocks old JWTs, preserves suspension, requires a purge delay and protects owned records', async () => {
    const db = await database();
    try {
        const account = async (actor,id,restore=false) => db.query('select set_account_deactivated($1::uuid,$2::uuid,$3)',[actor,id,restore]);
        await assert.rejects(account(teacher,student),/administrator/);
        await account(admin,student);
        assert.equal((await asStudent(db,'select id from profiles')).length,0);
        assert.equal((await asStudent(db,'select id from quizzes')).length,0);
        assert.equal((await db.query('select count(*)::integer as n from quiz_results')).rows[0].n,1);
        await assert.rejects(db.query('select prepare_account_purge($1::uuid,$2::uuid)',[admin,student]),/seven days/);
        await account(admin,student,true);
        assert.equal((await asStudent(db,'select id from profiles',Math.floor(Date.now()/1000)-60)).length,0);
        // Simulate the next-second new sign-in without sleeping in the test.
        await db.exec(`update profiles set reactivation_tokens_invalid_before=now()-interval '2 seconds' where id='${student}'`);
        assert.equal((await asStudent(db,'select id from profiles')).length,1);
        await db.exec(`update profiles set suspended_at=now() where id='${student}'`);
        await account(admin,student); await account(admin,student,true);
        assert.ok((await db.query(`select suspended_at from profiles where id='${student}'`)).rows[0].suspended_at);
        await account(admin,student);
        await db.exec(`update profiles set deactivated_at=now()-interval '8 days' where id='${student}'`);
        const intent = (await db.query('select prepare_account_purge($1::uuid,$2::uuid) as data',[admin,student])).rows[0].data.intent_id;
        await assert.rejects(account(admin,student,true),/already pending/);
        await db.query('select cancel_account_purge($1::uuid,$2::uuid,$3::uuid)',[admin,student,intent]);
        assert.equal((await db.query('select status from privileged_audit_outbox')).rows[0].status,'failed');
        await account(admin,teacher);
        await db.exec(`update profiles set deactivated_at=now()-interval '8 days' where id='${teacher}'`);
        await assert.rejects(db.query('select prepare_account_purge($1::uuid,$2::uuid)',[admin,teacher]),/owns retained/);
    } finally { await db.close(); }
});

test('incident leases, per-channel retries, cooldowns, acknowledgements, resolution and escalation', async () => {
    const db = await database();
    try {
        const sync = async (active=true,severity='warning') => (await db.query("select sync_incident_signal('scheduler',$1,$2,'Scheduler stale','{}',3600) as data",[active,severity])).rows[0].data;
        const first = await sync(); assert.equal(first.notify,true);
        assert.equal((await sync()).notify,false);
        await db.query("select finish_incident_notification($1::uuid,$2::uuid,'{\"bell\":true}',false,'SMTP not accepted')",[first.id,first.lease]);
        assert.equal((await sync()).notify,false);
        await db.exec("update system_incidents set notification_retry_at=now()-interval '1 second'");
        const retry = await sync(); assert.equal(retry.delivered_channels.bell,true);
        await db.query("select finish_incident_notification($1::uuid,$2::uuid,'{\"email\":true}',true,null)",[retry.id,retry.lease]);
        assert.equal((await sync()).notify,false);
        await assert.rejects(db.query('select acknowledge_system_incident($1::uuid,$2::uuid)',[student,first.id]),/administrator/);
        await db.query('select acknowledge_system_incident($1::uuid,$2::uuid)',[admin,first.id]);
        await db.exec("update system_incidents set last_notified_at=now()-interval '2 hours'");
        assert.equal((await sync()).notify,false); // acknowledgement survives cooldown
        assert.equal((await sync(true,'critical')).notify,true); // genuine escalation unpauses
        await sync(false);
        const recurrence = await sync(); assert.equal(recurrence.notify,true);
        assert.deepEqual(recurrence.delivered_channels,{});
        await db.query('select notify_incident_admins($1::uuid,$2)',[recurrence.id,recurrence.notification_generation]);
        await db.query('select notify_incident_admins($1::uuid,$2)',[recurrence.id,recurrence.notification_generation]);
        assert.equal((await db.query('select count(*)::integer as n from notifications')).rows[0].n,1);
    } finally { await db.close(); }
});

test('signal aggregates exclude alert feedback and client roles cannot execute privileged recovery/alert RPCs', async () => {
    const db = await database();
    try {
        await db.exec(`insert into incident_events (reference_id,kind,subject_hash,network_hash,route_name,http_status)
            select 'MV-'||upper(lpad(to_hex(n),16,'0')),'auth_failure',repeat('a',64),repeat('b',64),'login',302 from generate_series(1,13) n;
            insert into notification_deliveries (event_type,status) values ('incident_alert','failed'),('quiz_assigned','failed');`);
        const counts = (await db.query('select incident_signal_counts(600) as data')).rows[0].data;
        assert.equal(counts.repeated_failures,13); assert.equal(counts.delivery_failures,1);
        for (const signature of ['set_recovery_item(uuid,text,uuid,boolean)','set_account_deactivated(uuid,uuid,boolean)',
            'prepare_account_purge(uuid,uuid)','sync_incident_signal(text,boolean,text,text,jsonb,integer)',
            'finish_incident_notification(uuid,uuid,jsonb,boolean,text)','incident_signal_counts(integer)']) {
            for (const role of ['anon','authenticated']) {
                assert.equal((await db.query('select has_function_privilege($1,$2,\'EXECUTE\') as yes',[role,signature])).rows[0].yes,false);
            }
        }
        assert.equal((await db.query("select has_table_privilege('authenticated','incident_events','SELECT') as yes")).rows[0].yes,false);
    } finally { await db.close(); }
});
