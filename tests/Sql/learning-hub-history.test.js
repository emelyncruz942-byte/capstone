import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const sql = readFileSync(new URL('../../database/functions/learning_hub_history.sql', import.meta.url), 'utf8');
const teacher = '11111111-1111-4111-8111-111111111111';
const student = '22222222-2222-4222-8222-222222222222';
const outsider = '33333333-3333-4333-8333-333333333333';
const classroom = '44444444-4444-4444-8444-444444444444';

async function setup(t) {
    const db = new PGlite(); t.after(() => db.close());
    await db.exec(`
        create role anon; create role authenticated; create role service_role;
        create table profiles (id uuid primary key, role text, first_name text, last_name text, grade_level int, suspended_at timestamptz);
        create table classes (id uuid primary key, teacher_id uuid, class_name text, grade_level int, archived_at timestamptz, deleted_at timestamptz);
        create table class_members (class_id uuid, student_id uuid);
        create table practice_questions (id uuid default gen_random_uuid(), student_id uuid, competency_key text,
            difficulty int default 1, hints_revealed int default 0, is_correct bool, mastery_after int,
            response_ms int default 12000, answered_at timestamptz);
        create table practice_mastery (student_id uuid, competency_key text, grade_level int,
            mastery_score int, difficulty int, attempts int, correct_answers int, hints_used int,
            last_practiced_at timestamptz, next_review_at timestamptz);
        insert into profiles values ('${teacher}','teacher','Teacher','One',null,null),
            ('${student}','student','Student','One',4,null),('${outsider}','student','Other','Student',4,null);
        insert into classes values ('${classroom}','${teacher}','Grade 4',4,now()-interval '2 days',null);
        insert into class_members values ('${classroom}','${student}');
        insert into practice_questions (student_id,competency_key,is_correct,mastery_after,answered_at) values
            ('${student}','g4-fractions',false,20,now()-interval '250 days'),
            ('${student}','g4-fractions',true,60,now()-interval '1 day'),
            ('${student}','g4-fractions',true,100,now()+interval '5 days'),
            ('${outsider}','g4-fractions',true,100,now()-interval '1 day');
        insert into practice_mastery values ('${student}','g4-fractions',4,60,1,2,1,0,now()-interval '1 day',null);
    `);
    await db.exec(sql);
    return db;
}

test('all-history default includes the oldest owned answer, archived classes and current activity, excluding future/unrelated records', async t => {
    const db = await setup(t);
    const summary = (await db.query('select teacher_learning_hub_analytics($1) value', [teacher])).rows[0].value;
    assert.equal(summary.period_days, 0);
    assert.equal(summary.summary.answers, 2);
    assert.equal(summary.summary.students, 1);
    assert.equal(summary.daily_activity.length, 2);
    assert.equal(summary.summary.accuracy, 50);
    assert.ok(Date.parse(summary.period_start) < Date.now() - 240 * 86400000);
    assert.ok(Date.parse(summary.period_end) <= Date.now() + 1000);
    const solo = (await db.query('select teacher_learning_hub_student_analytics($1,$2) value', [teacher, student])).rows[0].value;
    assert.equal(solo.summary.answers, 2);
    assert.equal(solo.period_days, 0);
});

test('a selected recent range remains bounded and cannot include future answers', async t => {
    const db = await setup(t);
    for (const days of [7,30,90,180]) {
        const r = (await db.query('select teacher_learning_hub_analytics($1,null,$2) value', [teacher, days])).rows[0].value;
        assert.equal(r.period_days, days);
        assert.equal(r.summary.answers, 1);
    }
});

test('class deletion and teacher/student ownership are still enforced', async t => {
    const db = await setup(t);
    await assert.rejects(db.query('select teacher_learning_hub_student_analytics($1,$2)', [teacher, outsider]), /Student is unavailable/);
    await db.query('update classes set deleted_at=now() where id=$1', [classroom]);
    await assert.rejects(db.query('select teacher_learning_hub_analytics($1,$2)', [teacher, classroom]), /Class is unavailable/);
    const r = (await db.query('select teacher_learning_hub_analytics($1) value', [teacher])).rows[0].value;
    assert.equal(r.summary.answers, 0);
});

test('direct browser roles cannot invoke the server-only analytics functions', async t => {
    const db = await setup(t);
    for (const role of ['anon','authenticated']) {
        await db.exec(`set role ${role}`);
        try {
            await assert.rejects(db.query('select teacher_learning_hub_analytics($1)', [teacher]), /permission denied/);
            await assert.rejects(db.query('select teacher_learning_hub_student_analytics($1,$2)', [teacher, student]), /permission denied/);
        } finally { await db.exec('reset role'); }
    }
});
