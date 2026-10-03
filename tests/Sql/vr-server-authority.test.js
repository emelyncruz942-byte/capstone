import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(
    new URL('../../database/supabase/2026_10_02_vr_server_authority_and_request_guards.sql', import.meta.url),
    'utf8',
);
const edgeFunction = readFileSync(
    new URL('../../supabase/functions/send-admin-push/index.ts', import.meta.url),
    'utf8',
);

const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const session = '33333333-3333-4333-8333-333333333333';
const submission = '44444444-4444-4444-8444-444444444444';

async function database(t) {
    const db = new PGlite();
    t.after(() => db.close());
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
        values ('2026_09_24_quiz_lifecycle_and_active_retakes.sql');

        create table profiles (
            id uuid primary key,
            role text not null,
            suspended_at timestamptz,
            deactivated_at timestamptz
        );
        create table quiz_sessions (
            id uuid primary key,
            teacher_id uuid not null references profiles(id),
            room_code text not null,
            status text not null,
            is_active boolean not null,
            retake_mode boolean not null default false,
            available_at timestamptz,
            due_at timestamptz,
            time_limit integer not null default 20
        );
        create table quiz_session_students (
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            eligibility_status text not null default 'eligible',
            allowed_attempts integer not null default 1,
            last_retake_granted_at timestamptz,
            last_retake_granted_by uuid,
            retake_due_at timestamptz,
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
            question text not null,
            choice1 text not null,
            choice2 text not null,
            choice3 text not null,
            choice4 text not null,
            correct_answer text not null,
            deleted_at timestamptz
        );
        create table quiz_results (
            id uuid primary key,
            session_id uuid not null references quiz_sessions(id),
            student_id uuid not null references profiles(id),
            correct_answers integer not null,
            total_questions integer not null,
            attempt_number integer not null default 1,
            is_counted boolean not null default true,
            created_at timestamptz not null default now()
        );
        create table rollback_arcade_achievements_20260912 (id bigint);
        create table rollback_arcade_scores_20260912 (id bigint);
        create table rollback_privileged_audit_outbox_20260912 (id bigint);

        alter table quiz_sessions enable row level security;
        alter table questions enable row level security;
        alter table quiz_participants enable row level security;
        grant select on quiz_sessions, questions to anon, authenticated;
        grant insert on quiz_participants to anon, authenticated;
        grant select (id,room_code,status,time_limit,is_active) on quiz_sessions to anon, authenticated;
        grant select (id,session_id,question,choice1,choice2,choice3,choice4,correct_answer) on questions to anon, authenticated;
        grant insert (session_id,student_id) on quiz_participants to anon, authenticated;
        create policy mathverse_vr_legacy_read_allow on quiz_sessions for select to anon using (true);
        create policy mathverse_vr_legacy_read_boundary on quiz_sessions as restrictive for select to anon using (true);
        create policy mathverse_vr_legacy_read_allow on questions for select to anon using (true);
        create policy mathverse_vr_legacy_read_boundary on questions as restrictive for select to anon using (true);
        create policy mathverse_vr_legacy_join_allow on quiz_participants for insert to anon with check (true);
        create policy mathverse_vr_legacy_join_boundary on quiz_participants as restrictive for insert to anon with check (true);

        create function get_vr_quiz_score_slot(p_session_id uuid, p_student_id uuid)
        returns table(can_submit boolean,status text,attempt_number integer,retake_granted_at text,message text)
        language sql security definer set search_path=public as $$
            select true,'ready',1,''::text,'ready'
        $$;
        create function submit_vr_quiz_score(
            p_session_id uuid,p_student_id uuid,p_submission_id uuid,
            p_attempt_number integer,p_retake_granted_at text,
            p_correct_answers integer,p_total_questions integer
        ) returns table(saved boolean,status text,result_id uuid,attempt_number integer,
            correct_answers integer,total_questions integer,message text)
        language plpgsql security definer set search_path=public as $$
        declare existing quiz_results%rowtype;
        begin
            select * into existing from quiz_results where id=p_submission_id;
            if found then
                return query select true,'already_saved',existing.id,existing.attempt_number,
                    existing.correct_answers,existing.total_questions,'unchanged';
                return;
            end if;
            insert into quiz_results(id,session_id,student_id,correct_answers,total_questions)
            values(p_submission_id,p_session_id,p_student_id,p_correct_answers,p_total_questions)
            returning * into existing;
            return query select true,'saved',existing.id,existing.attempt_number,
                existing.correct_answers,existing.total_questions,'saved';
        end $$;
        create function register_my_vr_quiz_participant(uuid) returns void language sql security definer as $$ select $$;
        create function submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)
            returns void language sql security definer as $$ select $$;

        insert into profiles values ('${teacher}','teacher',null,null),('${student}','student',null,null);
        insert into quiz_sessions values(
            '${session}','${teacher}','1234','active',true,false,now()-interval '1 hour',now()+interval '1 day',25
        );
        insert into quiz_session_students(session_id,student_id)
            values('${session}','${student}');
        insert into questions(session_id,question,choice1,choice2,choice3,choice4,correct_answer)
        values
            ('${session}','First?','A','B','C','D','0'),
            ('${session}','Second?','Red','Blue','Green','Gold','Blue');
    `);
    await db.exec(migration);
    return db;
}

async function asStudent(db, sql) {
    await db.exec(`set request.jwt.claim.sub='${student}'; set role authenticated;`);
    try {
        return await db.query(sql);
    } finally {
        await db.exec('reset role;');
    }
}

test('safe VR RPCs hide answers and calculate an idempotent server score', async (t) => {
    const db = await database(t);

    const join = (await asStudent(db, "select * from join_my_vr_quiz_room(' 1234 ')" )).rows[0];
    assert.equal(join.joined, true);
    assert.equal(join.session_id, session);
    assert.equal(join.time_limit, 25);

    const questions = (await asStudent(db,
        `select * from get_my_vr_quiz_questions('${session}')`,
    )).rows;
    assert.equal(questions.length, 2);
    assert.deepEqual(Object.keys(questions[0]).sort(),
        ['choice1','choice2','choice3','choice4','question','question_id'].sort());
    assert.equal('correct_answer' in questions[0], false);

    const first = (await asStudent(db, `
        select * from submit_my_vr_quiz_answers(
            '${session}','${submission}',1,'',array[1,2]::bigint[],array[0,1]::integer[]
        )
    `)).rows[0];
    assert.equal(first.saved, true);
    assert.equal(first.correct_answers, 2);
    assert.equal(first.total_questions, 2);

    const replay = (await asStudent(db, `
        select * from submit_my_vr_quiz_answers(
            '${session}','${submission}',1,'',array[1,2]::bigint[],array[3,3]::integer[]
        )
    `)).rows[0];
    assert.equal(replay.saved, true);
    assert.equal(replay.correct_answers, 2);
    assert.equal((await db.query('select count(*)::integer as count from quiz_results')).rows[0].count, 1);

    const labelled = (await db.query(`select resolve_vr_answer_index(
        'C) Earth','Mercury','Venus','Earth','Mars'
    ) as answer_index`)).rows[0];
    assert.equal(labelled.answer_index, 2);

    const blank = (await db.query(`select resolve_vr_answer_index(
        null,'','','Earth','Mars'
    ) as answer_index`)).rows[0];
    assert.equal(blank.answer_index, null);
});

test('a lost score response can be retried after the quiz ends and questions change', async (t) => {
    const db = await database(t);

    const first = (await asStudent(db, `
        select * from submit_my_vr_quiz_answers(
            '${session}','${submission}',1,'',array[1,2]::bigint[],array[0,1]::integer[]
        )
    `)).rows[0];
    assert.equal(first.saved, true);
    assert.equal(first.correct_answers, 2);

    await db.exec(`
        update quiz_sessions set status='completed', is_active=false where id='${session}';
        update questions set deleted_at=now() where session_id='${session}' and id=2;
    `);

    const replay = (await asStudent(db, `
        select * from submit_my_vr_quiz_answers(
            '${session}','${submission}',1,'',array[1]::bigint[],array[3]::integer[]
        )
    `)).rows[0];
    assert.equal(replay.saved, true);
    assert.equal(replay.status, 'already_saved');
    assert.equal(replay.result_id, submission);
    assert.equal(replay.correct_answers, 2);
    assert.equal(replay.total_questions, 2);
    assert.equal((await db.query('select count(*)::integer as count from quiz_results')).rows[0].count, 1);
});

test('waiting rooms cannot reveal questions or accept an early score', async (t) => {
    const db = await database(t);
    await db.exec(`update quiz_sessions set status='waiting' where id='${session}'`);

    const join = (await asStudent(db, "select * from join_my_vr_quiz_room('1234')")).rows[0];
    assert.equal(join.joined, true);
    await assert.rejects(asStudent(db,
        `select * from get_my_vr_quiz_questions('${session}')`,
    ));

    const slot = (await asStudent(db,
        `select * from get_my_vr_quiz_score_slot('${session}')`,
    )).rows[0];
    assert.equal(slot.can_submit, false);
    assert.equal(slot.status, 'not_active');

    const early = (await asStudent(db, `
        select * from submit_my_vr_quiz_answers(
            '${session}','${submission}',1,'',array[1,2]::bigint[],array[0,1]::integer[]
        )
    `)).rows[0];
    assert.equal(early.saved, false);
    assert.equal(early.status, 'not_active');
    assert.equal((await db.query('select count(*)::integer as count from quiz_results')).rows[0].count, 0);
});

test('post-join suspension and deactivation close student read RPCs', async (t) => {
    const db = await database(t);
    await asStudent(db, "select * from join_my_vr_quiz_room('1234')");

    await db.exec(`update profiles set suspended_at=now() where id='${student}'`);
    const suspendedStatus = await asStudent(db,
        `select * from get_my_vr_quiz_status('${session}')`,
    );
    assert.deepEqual(suspendedStatus.rows, []);
    await assert.rejects(asStudent(db,
        `select * from get_my_vr_quiz_questions('${session}')`,
    ));

    await db.exec(`update profiles set suspended_at=null,deactivated_at=now() where id='${student}'`);
    const deactivatedStatus = await asStudent(db,
        `select * from get_my_vr_quiz_status('${session}')`,
    );
    assert.deepEqual(deactivatedStatus.rows, []);
    await assert.rejects(asStudent(db,
        `select * from get_my_vr_quiz_questions('${session}')`,
    ));
});

test('direct answer reads and caller-scored functions are closed', async (t) => {
    const db = await database(t);
    const permissions = (await db.query(`
        select
            has_table_privilege('anon','questions','select') as anon_questions,
            has_table_privilege('authenticated','questions','select') as auth_questions,
            has_column_privilege('anon','quiz_sessions','room_code','select') as anon_room_code,
            has_column_privilege('authenticated','questions','correct_answer','select') as auth_answer_column,
            has_column_privilege('anon','quiz_participants','student_id','insert') as anon_participant_insert,
            has_function_privilege('authenticated','submit_my_vr_quiz_score(uuid,uuid,integer,text,integer,integer)','execute') as old_score,
            has_function_privilege('authenticated','submit_my_vr_quiz_answers(uuid,uuid,integer,text,bigint[],integer[])','execute') as safe_score
    `)).rows[0];
    assert.deepEqual(permissions, {
        anon_questions: false,
        auth_questions: false,
        anon_room_code: false,
        auth_answer_column: false,
        anon_participant_insert: false,
        old_score: false,
        safe_score: true,
    });
});

test('VR and machine RPC limits are durable and archives force RLS', async (t) => {
    const db = await database(t);
    for (let attempt = 0; attempt < 12; attempt++) {
        const receipt = (await asStudent(db, "select * from join_my_vr_quiz_room('1234')")).rows[0];
        assert.notEqual(receipt.status, 'rate_limited');
    }
    const limited = (await asStudent(db, "select * from join_my_vr_quiz_room('1234')")).rows[0];
    assert.equal(limited.status, 'rate_limited');

    const nonce = 'abcdefghijklmnopqrstuvwxyzABCDEF1234567890_-';
    await db.exec('set role service_role;');
    const first = (await db.query(`select claim_machine_request(
        'send-admin-push','${nonce}',now(),2,60
    ) as claimed`)).rows[0].claimed;
    const replay = (await db.query(`select claim_machine_request(
        'send-admin-push','${nonce}',now(),2,60
    ) as claimed`)).rows[0].claimed;
    const second = (await db.query(`select claim_machine_request(
        'send-admin-push','${nonce}a',now(),2,60
    ) as claimed`)).rows[0].claimed;
    const overLimit = (await db.query(`select claim_machine_request(
        'send-admin-push','${nonce}b',now(),2,60
    ) as claimed`)).rows[0].claimed;
    await db.exec('reset role;');
    assert.equal(first, true);
    assert.equal(replay, false);
    assert.equal(second, true);
    assert.equal(overLimit, false);
    assert.equal((await db.query(`select count(*)::integer as count
        from mathverse_machine_requests where scope='send-admin-push'`)).rows[0].count, 2);

    const archives = (await db.query(`
        select c.relname, c.relrowsecurity, c.relforcerowsecurity,
               has_table_privilege('service_role',c.oid,'select') as service_select
        from pg_class c join pg_namespace n on n.oid=c.relnamespace
        where n.nspname='public' and c.relname like 'rollback_%_20260912'
        order by c.relname
    `)).rows;
    assert.equal(archives.length, 3);
    assert.ok(archives.every(row => row.relrowsecurity && row.relforcerowsecurity && !row.service_select));
});

test('Edge receiver authenticates and claims the bounded raw body before JSON parsing', () => {
    const read = edgeFunction.indexOf('readLimitedText(request.body, MAX_REQUEST_BYTES)');
    const hmac = edgeFunction.indexOf('await hmacSignature(');
    const claim = edgeFunction.indexOf('"claim_machine_request"');
    const parse = edgeFunction.indexOf('JSON.parse(rawBody)');
    assert.ok(read >= 0 && hmac > read && claim > hmac && parse > claim);
    assert.match(edgeFunction, /const SIGNATURE_PATH = "\/send-admin-push";/);
    assert.match(edgeFunction, /SIGNATURE_SCOPE,\s*request\.method\.toUpperCase\(\),\s*SIGNATURE_PATH,/);
    assert.doesNotMatch(edgeFunction, /new URL\(request\.url\)\.pathname/);
    assert.match(edgeFunction, /`v2:\$\{scope\}:\$\{method\}:\$\{path\}:\$\{timestamp\}:\$\{nonce\}:\$\{toHex\(bodyDigest\)\}`/);
    assert.doesNotMatch(edgeFunction, /x-mathverse-push-secret/i);
});
