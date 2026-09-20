import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { PGlite } from '@electric-sql/pglite';

const migration = readFileSync(
    new URL('../../database/supabase/2026_09_20_vr_score_submission.sql', import.meta.url),
    'utf8',
);
const teacher = '22222222-2222-4222-8222-222222222222';
const student = '33333333-3333-4333-8333-333333333333';
const other = '44444444-4444-4444-8444-444444444444';
const session = '77777777-7777-4777-8777-777777777777';
// Unchanged function bodies from the repository's August 29/30 migrations.
const guards = "create or replace function public.enforce_quiz_result_attempt()\nreturns trigger\nlanguage plpgsql\nsecurity definer\nset search_path = public\nas $$\ndeclare\n    assignment public.quiz_sessions%rowtype;\n    eligibility public.quiz_session_students%rowtype;\n    attempts_used integer;\nbegin\n    select * into assignment\n    from public.quiz_sessions\n    where id = new.session_id\n    for update;\n\n    if assignment.id is null then\n        raise exception 'This quiz assignment does not exist';\n    end if;\n\n    if assignment.status = 'waiting'\n       and assignment.available_at is not null\n       and assignment.available_at <= timezone('utc', now())\n       and (assignment.due_at is null or assignment.due_at > timezone('utc', now())) then\n        update public.quiz_sessions\n        set status = 'active',\n            is_active = true,\n            started_at = coalesce(started_at, available_at, timezone('utc', now()))\n        where id = assignment.id;\n        assignment.status := 'active';\n        assignment.is_active := true;\n        assignment.started_at := coalesce(\n            assignment.started_at,\n            assignment.available_at,\n            timezone('utc', now())\n        );\n    end if;\n\n    if exists (\n        select 1 from public.profiles\n        where id = new.student_id and suspended_at is not null\n    ) then\n        raise exception 'This account is suspended';\n    end if;\n\n    if assignment.available_at is not null\n       and assignment.available_at > timezone('utc', now()) then\n        raise exception 'This quiz assignment is not available yet';\n    end if;\n\n    if assignment.due_at is not null\n       and assignment.due_at <= timezone('utc', now()) then\n        raise exception 'This quiz assignment is past due';\n    end if;\n\n    if assignment.status <> 'active' or not assignment.is_active then\n        raise exception 'This quiz assignment is not active';\n    end if;\n\n    select * into eligibility\n    from public.quiz_session_students\n    where session_id = new.session_id\n      and student_id = new.student_id\n    for update;\n\n    if eligibility.session_id is null or eligibility.eligibility_status <> 'eligible' then\n        raise exception 'This student is not eligible for the quiz assignment';\n    end if;\n\n    if eligibility.retake_due_at is not null\n       and eligibility.retake_due_at <= timezone('utc', now()) then\n        raise exception 'This student retake window is past due';\n    end if;\n\n    if auth.uid() is not null and auth.uid() is distinct from new.student_id then\n        raise exception 'A student can submit only their own quiz result';\n    end if;\n\n    select count(*)::integer into attempts_used\n    from public.quiz_results\n    where session_id = new.session_id\n      and student_id = new.student_id;\n\n    if attempts_used >= eligibility.allowed_attempts then\n        raise exception 'No quiz attempts remain for this assignment';\n    end if;\n\n    update public.quiz_results\n    set is_counted = false\n    where session_id = new.session_id\n      and student_id = new.student_id\n      and is_counted;\n\n    new.attempt_number := attempts_used + 1;\n    new.is_counted := true;\n    return new;\nend;\n$$;\ncreate or replace function public.grant_quiz_retake(\n    p_session_id uuid,\n    p_student_id uuid,\n    p_teacher_id uuid,\n    p_reason text,\n    p_due_at timestamp with time zone default null\n)\nreturns table (new_allowed_attempts integer, retake_due_at timestamp with time zone)\nlanguage plpgsql\nsecurity definer\nset search_path = public\nas $$\ndeclare\n    assignment public.quiz_sessions%rowtype;\n    eligibility public.quiz_session_students%rowtype;\n    attempts_used integer;\n    next_allowed integer;\n    next_due timestamp with time zone;\n    session_due timestamp with time zone;\nbegin\n    select * into assignment\n    from public.quiz_sessions\n    where id = p_session_id and teacher_id = p_teacher_id\n    for update;\n\n    if assignment.id is null then\n        raise exception 'Quiz assignment not found for this teacher';\n    end if;\n\n    if assignment.status <> 'completed' and not assignment.retake_mode then\n        raise exception 'End the original quiz before granting a retake';\n    end if;\n\n    select * into eligibility\n    from public.quiz_session_students\n    where session_id = p_session_id and student_id = p_student_id\n    for update;\n\n    if eligibility.session_id is null then\n        raise exception 'Student is not eligible for this assignment';\n    end if;\n\n    if exists (\n        select 1 from public.profiles\n        where id = p_student_id and suspended_at is not null\n    ) then\n        raise exception 'A suspended student cannot receive a retake';\n    end if;\n\n    if p_reason is null or char_length(btrim(p_reason)) = 0\n       or char_length(p_reason) > 500 then\n        raise exception 'A retake reason between 1 and 500 characters is required';\n    end if;\n\n    next_due := coalesce(p_due_at, timezone('utc', now()) + interval '1 day');\n    if next_due <= timezone('utc', now()) then\n        raise exception 'The retake due date must be in the future';\n    end if;\n\n    select count(*)::integer into attempts_used\n    from public.quiz_results\n    where session_id = p_session_id and student_id = p_student_id;\n\n    next_allowed := greatest(attempts_used, eligibility.allowed_attempts) + 1;\n\n    update public.quiz_session_students\n    set eligibility_status = 'eligible',\n        allowed_attempts = next_allowed,\n        excused_at = null,\n        excused_by = null,\n        excuse_reason = null,\n        last_retake_granted_at = timezone('utc', now()),\n        last_retake_granted_by = p_teacher_id,\n        retake_due_at = next_due,\n        retake_reason = btrim(p_reason)\n    where session_id = p_session_id and student_id = p_student_id;\n\n    select greatest(\n        next_due,\n        max(qss.retake_due_at),\n        case\n            when assignment.status in ('waiting', 'active') and not assignment.retake_mode\n                then assignment.due_at\n            else null\n        end\n    ) into session_due\n    from public.quiz_session_students qss\n    where qss.session_id = p_session_id;\n\n    update public.quiz_sessions\n    set status = 'active',\n        is_active = true,\n        retake_mode = true,\n        available_at = timezone('utc', now()),\n        due_at = session_due,\n        ended_at = null\n    where id = p_session_id;\n\n    return query select next_allowed, next_due;\nend;\n$$;\ncreate or replace function public.freeze_completed_assignment_attempts()\nreturns trigger\nlanguage plpgsql\nsecurity definer\nset search_path = public\nas $$\nbegin\n    if new.status = 'completed' and old.status is distinct from new.status then\n        update public.quiz_session_students qss\n        set allowed_attempts = (\n            select count(*)::integer\n            from public.quiz_results qr\n            where qr.session_id = qss.session_id\n              and qr.student_id = qss.student_id\n        )\n        where qss.session_id = new.id;\n    end if;\n    return new;\nend;\n$$;\ncreate or replace function public.require_explicit_quiz_retake()\nreturns trigger\nlanguage plpgsql\nsecurity definer\nset search_path = public\nas $$\ndeclare\n    retake_granted_at timestamp with time zone;\nbegin\n    select last_retake_granted_at into retake_granted_at\n    from public.quiz_session_students\n    where session_id = new.session_id\n      and student_id = new.student_id;\n\n    if retake_granted_at is null and exists (\n        select 1\n        from public.quiz_results\n        where session_id = new.session_id\n          and student_id = new.student_id\n    ) then\n        return null;\n    end if;\n\n    return new;\nend;\n$$;\ncreate or replace function public.keep_quiz_results_immutable()\nreturns trigger\nlanguage plpgsql\nsecurity definer\nset search_path = public\nas $$\nbegin\n    if pg_trigger_depth() = 1\n       and (to_jsonb(new) - 'rank') is distinct from (to_jsonb(old) - 'rank') then\n        raise exception 'Quiz results are immutable; an authorized retake must create a new result';\n    end if;\n    return new;\nend;\n$$;";

async function database(t) {
    const db = new PGlite();
    t.after(()=>db.close());
    // Match Supabase's UTC database session, rather than PGlite's GMT+5 default.
    await db.exec("set time zone 'UTC'");
    await db.exec([
        "create role anon; create role authenticated; create role service_role;",
        "create schema auth;",
        "create function auth.uid() returns uuid language sql stable as $$ select nullif(current_setting('request.jwt.claim.sub',true),'')::uuid; $$;",
        "create table mathverse_schema_migrations (migration_key text primary key, applied_at timestamptz default now());",
        "insert into mathverse_schema_migrations (migration_key) values ('2026_09_20_vr_legacy_access.sql');",
        "create table profiles (id uuid primary key, role text, suspended_at timestamptz, deactivated_at timestamptz);",
        "create table quiz_sessions (id uuid primary key, teacher_id uuid references profiles(id), status text default 'active', is_active boolean default true, retake_mode boolean default false, available_at timestamptz default now()-interval '1 hour', due_at timestamptz default now()+interval '1 day', started_at timestamptz, ended_at timestamptz);",
        "create table quiz_session_students (session_id uuid references quiz_sessions(id), student_id uuid references profiles(id), eligibility_status text default 'eligible', allowed_attempts integer default 1, last_retake_granted_at timestamptz, last_retake_granted_by uuid, retake_due_at timestamptz, retake_reason text, excused_at timestamptz, excused_by uuid, excuse_reason text, primary key(session_id,student_id));",
        "create table quiz_participants (session_id uuid references quiz_sessions(id), student_id uuid references profiles(id), primary key(session_id,student_id));",
        "create table questions (id bigint generated always as identity primary key, session_id uuid references quiz_sessions(id), deleted_at timestamptz);",
        "create table quiz_results (id uuid primary key default gen_random_uuid(), session_id uuid references quiz_sessions(id), student_id uuid references profiles(id), correct_answers integer default 0, total_questions integer default 0, rank integer default 0, created_at timestamptz default timezone('utc',now()), attempt_number integer not null default 1 check(attempt_number>0), is_counted boolean not null default true, check(correct_answers>=0 and total_questions>=0 and correct_answers<=total_questions));",
        ...['profiles','quiz_sessions','quiz_session_students','quiz_participants','questions','quiz_results'].map(name=>"alter table "+name+" enable row level security;"),
        "insert into profiles values ('"+teacher+"','teacher',null,null),('"+student+"','student',null,null),('"+other+"','student',null,null);",
        "insert into quiz_sessions(id,teacher_id) values ('"+session+"','"+teacher+"');",
        "insert into quiz_session_students(session_id,student_id) values ('"+session+"','"+student+"'),('"+session+"','"+other+"');",
        "insert into quiz_participants values ('"+session+"','"+student+"'),('"+session+"','"+other+"');",
        "insert into questions(session_id) select '"+session+"'::uuid from generate_series(1,5);"
    ].join("\n"));
    await db.exec(guards);
    await db.exec("create trigger quiz_sessions_freeze_attempts after update of status on quiz_sessions for each row execute function freeze_completed_assignment_attempts();");
    await db.exec(migration);
    return db;
}
async function rpc(db, name, values, role='anon') {
    await db.exec('set role '+role);
    try {
        return (await db.query("select * from public."+name+"("+values.map((_,i)=>"$"+(i+1)).join(",")+")",values)).rows[0];
    } finally { await db.exec('reset role'); }
}
const slot=(db,id=student)=>rpc(db,'get_vr_quiz_score_slot',[session,id]);
const save=(db,permission,score=2,id=randomUUID(),idStudent=student,total=5)=>
    rpc(db,'submit_vr_quiz_score',[session,idStudent,id,permission.attempt_number,permission.retake_granted_at,score,total]);
const results=async db=>(await db.query('select * from quiz_results order by attempt_number')).rows;
async function grant(db,id=student) {
    await db.exec("update quiz_sessions set status='completed',is_active=false,retake_mode=false where id='"+session+"'");
    return (await db.query("select * from grant_quiz_retake($1,$2,$3,$4,now()+interval '1 day')",
        [session,id,teacher,'Teacher approved a retake'])).rows[0];
}
async function grantAgain(db) {
    return (await db.query("select * from grant_quiz_retake($1,$2,$3,$4,now()+interval '1 day')",
        [session,student,teacher,'Repeat grant before submission'])).rows[0];
}
async function fixture(t) {
    return database(t);
}

test('migration repeats; RPC works without direct result-table privileges',async t=>{
    const db=await fixture(t);
    await db.exec(migration);
    assert.equal((await db.query("select count(*)::integer as count from mathverse_schema_migrations where migration_key='2026_09_20_vr_score_submission.sql'")).rows[0].count,1);
    assert.equal((await slot(db)).can_submit,true);
    await db.exec('set role anon');
    try { await assert.rejects(db.query('select * from quiz_results'),/permission denied/); }
    finally { await db.exec('reset role'); }
});
test('first score wins, including a higher-score retry with the same UUID',async t=>{
    const db=await fixture(t),permission=await slot(db),id=randomUUID();
    assert.equal(permission.attempt_number,1);
    const first=await save(db,permission,2,id);
    assert.equal(first.status,'saved'); assert.equal(first.correct_answers,2);
    const duplicate=await save(db,permission,5,id);
    assert.equal(duplicate.status,'already_saved'); assert.equal(duplicate.correct_answers,2);
    assert.equal((await results(db)).length,1);
});
test('different submission UUIDs cannot replace the first numbered attempt',async t=>{
    const db=await fixture(t),a=await slot(db),b=await slot(db);
    await save(db,a,1);
    const second=await save(db,b,5);
    assert.equal(second.status,'already_recorded'); assert.equal(second.saved,false);
    assert.equal(second.correct_answers,1);
    assert.equal((await slot(db)).can_submit,false);
    assert.equal((await results(db)).length,1);
});
test('lower retake score becomes counted, preserves history, and a new grant enables one more',async t=>{
    const db=await fixture(t);
    await save(db,await slot(db),4);
    const old=(await results(db))[0];
    await grant(db);
    const permission=await slot(db);
    assert.equal(permission.attempt_number,2);
    assert.equal((await save(db,permission,1)).status,'saved');
    let rows=await results(db);
    assert.deepEqual(rows.map(r=>[r.attempt_number,r.correct_answers,r.is_counted]),[[1,4,false],[2,1,true]]);
    assert.equal(rows[0].created_at.valueOf(),old.created_at.valueOf());
    assert.equal((await slot(db)).can_submit,false);
    await grant(db);
    assert.equal((await save(db,await slot(db),0)).attempt_number,3);
    rows=await results(db);
    assert.equal(rows.length,3); assert.equal(rows.filter(r=>r.is_counted).length,1);
});
test('lost first receipt retried after a retake grant does not consume that retake',async t=>{
    const db=await fixture(t),first=await slot(db),id=randomUUID();
    await save(db,first,3,id); await grant(db);
    assert.equal((await save(db,first,5,id)).status,'already_saved');
    assert.equal((await slot(db)).attempt_number,2);
    assert.equal((await results(db)).length,1);
});
test('inflated initial allowances do not authorize a second score without a grant',async t=>{
    const db=await fixture(t);
    await db.query("update quiz_session_students set allowed_attempts=8 where student_id=$1",[student]);
    await save(db,await slot(db),2);
    assert.equal((await slot(db)).status,'retake_required');
    assert.equal((await results(db)).length,1);
});
test('stacked allowances still allow one result for the current grant',async t=>{
    const db=await fixture(t);
    await save(db,await slot(db),4); await grant(db); await grantAgain(db);
    assert.equal((await save(db,await slot(db),1)).status,'saved');
    assert.equal((await slot(db)).status,'attempts_exhausted');
    assert.equal((await results(db)).length,2);
});
test('another student retake does not authorize this student',async t=>{
    const db=await fixture(t);
    await save(db,await slot(db),2); await grant(db,other);
    assert.equal((await slot(db)).can_submit,false);
});
test('an unsubmitted old original run cannot use a new retake grant',async t=>{
    const db=await fixture(t),stale=await slot(db);
    await grant(db);
    assert.equal((await save(db,stale,5)).status,'attempt_changed');
    assert.equal((await results(db)).length,0);
    assert.equal((await save(db,await slot(db),2)).status,'saved');
});
test('replacing a grant invalidates the run started under the earlier grant',async t=>{
    const db=await fixture(t);
    await save(db,await slot(db),2); await grant(db);
    const stale=await slot(db); await grantAgain(db);
    assert.equal((await save(db,stale,5)).status,'attempt_changed');
    assert.equal((await save(db,await slot(db),1)).status,'saved');
});
test('expired quiz or retake windows reject scores without insertion',async t=>{
    const db=await fixture(t),permission=await slot(db);
    await db.query("update quiz_sessions set due_at=now()-interval '1 minute' where id=$1",[session]);
    assert.equal((await save(db,permission,2)).status,'expired');
    await grant(db);
    const retake=await slot(db);
    await db.query("update quiz_session_students set retake_due_at=now()-interval '1 minute' where student_id=$1",[student]);
    assert.equal((await save(db,retake,2)).status,'expired');
    assert.equal((await results(db)).length,0);
});
test('suspension and deactivation after quiz start reject saving',async t=>{
    const db=await fixture(t),permission=await slot(db);
    for(const field of ['suspended_at','deactivated_at']){
        await db.query("update profiles set suspended_at=null,deactivated_at=null where id=$1",[student]);
        await db.query("update profiles set "+field+"=now() where id=$1",[student]);
        assert.equal((await save(db,permission,2)).status,'account_unavailable');
    }
    assert.equal((await results(db)).length,0);
});
test('missing student identity, room registration, and assignment eligibility are rejected',async t=>{
    const db=await fixture(t);
    assert.equal((await slot(db,'00000000-0000-0000-0000-000000000000')).status,'invalid_student');
    assert.equal((await slot(db,randomUUID())).status,'invalid_student');
    await db.query("delete from quiz_participants where student_id=$1",[student]);
    assert.equal((await slot(db)).status,'not_joined');
    await db.query("insert into quiz_participants values ($1,$2)",[session,student]);
    await db.query("update quiz_session_students set eligibility_status='excused' where student_id=$1",[student]);
    assert.equal((await slot(db)).status,'not_eligible');
});
test('invalid ranges and question totals are rejected; zero score is saved',async t=>{
    const db=await fixture(t),permission=await slot(db);
    assert.equal((await save(db,permission,-1)).status,'invalid_score');
    assert.equal((await save(db,permission,6)).status,'invalid_score');
    assert.equal((await save(db,permission,2,randomUUID(),student,4)).status,'invalid_score');
    assert.equal((await save(db,permission,0)).status,'saved');
    assert.equal((await results(db))[0].correct_answers,0);
});
test('an authenticated user cannot submit another student UUID',async t=>{
    const db=await fixture(t),permission=await slot(db);
    await db.query("select set_config('request.jwt.claim.sub',$1,false)",[other]);
    const denied=await rpc(db,'submit_vr_quiz_score',[session,student,randomUUID(),1,permission.retake_granted_at,2,5],'authenticated');
    assert.equal(denied.status,'invalid_request');
    assert.equal((await results(db)).length,0);
});
test('the existing immutable guard rejects score overwrites',async t=>{
    const db=await fixture(t); await save(db,await slot(db),2);
    await assert.rejects(db.query('update quiz_results set correct_answers=5'),/immutable/);
    assert.equal((await results(db))[0].correct_answers,2);
});
test('waiting quiz can check a slot but cannot save before teacher start',async t=>{
    const db=await fixture(t);
    await db.query("update quiz_sessions set status='waiting',available_at=null where id=$1",[session]);
    const permission=await slot(db); assert.equal(permission.can_submit,true);
    await assert.rejects(save(db,permission,2),/not active/);
    assert.equal((await results(db)).length,0);
});
test('failed lifecycle validation leaves the prior counted result unchanged',async t=>{
    const db=await fixture(t); await save(db,await slot(db),4); await grant(db);
    const permission=await slot(db);
    await db.query("update quiz_sessions set available_at=now()+interval '1 hour' where id=$1",[session]);
    await assert.rejects(save(db,permission,1),/not available yet/);
    assert.deepEqual((await results(db)).map(r=>[r.correct_answers,r.is_counted]),[[4,true]]);
});
test('score RPC keeps UTC timestamps when the caller session uses Philippine time',async t=>{
    const db=await fixture(t); await save(db,await slot(db),3); await grant(db);
    await db.exec("set time zone 'Asia/Manila'");
    const permission=await slot(db);
    assert.equal((await save(db,permission,1)).status,'saved');
    const rows=await results(db);
    assert.equal(rows.length,2);
    const wall=(await db.query('select clock_timestamp() as wall')).rows[0].wall;
    assert.ok(Math.abs(rows[1].created_at.valueOf()-wall.valueOf())<10000);
});
