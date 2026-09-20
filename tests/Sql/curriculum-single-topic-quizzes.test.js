import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { PGlite } from '@electric-sql/pglite';

const migrationUrl = new URL(
    '../../database/supabase/2026_09_20_curriculum_single_topic_quizzes.sql',
    import.meta.url
);
const rollbackUrl = new URL(
    '../../database/supabase/2026_09_20_curriculum_single_topic_quizzes_rollback.sql',
    import.meta.url
);
const curriculumUrl = new URL('../../app/Support/PracticeCurriculum.php', import.meta.url);
const migration = readFileSync(migrationUrl, 'utf8');
const rollback = readFileSync(rollbackUrl, 'utf8');
const curriculum = readFileSync(curriculumUrl, 'utf8').split('private static function legacy', 1)[0];
const adminId = '7607da46-85b3-4cdc-851d-23fd4eb5223e';
const expectedByGrade = new Map([[1, 15], [2, 17], [3, 22], [4, 19], [5, 20], [6, 16]]);

function parseCurriculum() {
    const topics = [];
    const pattern = /self::topic\('([^']+)',\s*'((?:[^'\\]|\\.)*)'/g;
    let match;
    while ((match = pattern.exec(curriculum)) !== null) {
        const grade = Number(match[1].match(/^g([1-6])-/)?.[1]);
        topics.push({
            key: match[1],
            topic: match[2].replaceAll("\\'", "'").replaceAll('\\\\', '\\'),
            grade_level: grade,
        });
    }
    return topics;
}

function parseSeed() {
    const match = migration.match(
        /\$curriculum_quiz_seed_json\$(\[.*\])\$curriculum_quiz_seed_json\$::jsonb/s
    );
    assert.ok(match, 'the migration must contain its JSON seed payload');
    return JSON.parse(match[1]);
}

const seed = parseSeed();

test('seed has one five-question quiz for every visible curriculum topic', () => {
    const topics = parseCurriculum();
    assert.equal(topics.length, 109);
    assert.equal(seed.length, 109);
    assert.equal(seed.reduce((total, quiz) => total + quiz.questions.length, 0), 545);

    const expected = topics.map(({ key, topic, grade_level }) => `${key}|${grade_level}|${topic}`).sort();
    const actual = seed.map(({ topic_key, topic, grade_level }) => `${topic_key}|${grade_level}|${topic}`).sort();
    assert.deepEqual(actual, expected);

    assert.equal(new Set(seed.map(quiz => quiz.quiz_id)).size, 109);
    assert.equal(new Set(seed.map(quiz => `${quiz.grade_level}|${quiz.topic}`)).size, 109);
    for (const [grade, count] of expectedByGrade) {
        assert.equal(seed.filter(quiz => quiz.grade_level === grade).length, count);
    }
});

test('quiz titles and questions are website-neutral and structurally valid', () => {
    const forbidden = /calapan|singko|caltex|capitol|nuciti|minsu|jasmai|chlippings|fermacia|rural bank of pola|goodmorning trading|m lhuillier|7-eleven|mr\. diy|g[1-6]_|\b(?:npc|quest|reward|route|port|passenger|ferry|ferries)\b/i;

    for (const quiz of seed) {
        assert.doesNotMatch(quiz.topic, /^grade\s+[1-6]\b/i);
        assert.equal(quiz.questions.length, 5);
        for (const question of quiz.questions) {
            assert.equal(typeof question.question, 'string');
            assert.ok(question.question.trim().length > 0);
            assert.doesNotMatch(question.question, /^Question\s+\d+\s*\[/i);
            assert.equal(question.choices.length, 4);
            assert.equal(new Set(question.choices.map(choice => choice.trim())).size, 4);
            assert.ok(Number.isInteger(question.correct_answer));
            assert.ok(question.correct_answer >= 0 && question.correct_answer <= 3);
            assert.ok(question.choices[question.correct_answer].trim().length > 0);
            assert.doesNotMatch(JSON.stringify(question), forbidden);
        }
    }
});

test('topic-specific regressions do not reintroduce mixed operations', () => {
    const byKey = new Map(seed.map(quiz => [quiz.topic_key, quiz]));
    const prompts = key => byKey.get(key).questions.map(question => question.question).join(' ');

    assert.doesNotMatch(prompts('g3-add-10000'), /change|subtracts|spends|remain/i);
    assert.doesNotMatch(prompts('g3-subtract-10000'), /total cost|adds|altogether/i);
    assert.doesNotMatch(prompts('g3-multi-digit-multiplication'), /divid|quotient/i);
    assert.doesNotMatch(prompts('g4-multiply'), /divid|quotient/i);
    assert.doesNotMatch(prompts('g4-similar-fractions'), /1\/10|3\/4|1\/3/);
    assert.match(prompts('g5-decimal-place-value'), /thousandths/i);
    assert.doesNotMatch(prompts('g5-decimal-place-value'), /rounds|compares|fraction/i);
    for (const question of byKey.get('g6-fraction-operations').questions) {
        assert.match(question.question, /multipl|divid|×|÷|\sx\s/i);
    }
    assert.match(prompts('g5-polygon-area'), /base of 8 m and a height of 3 m/i);
});

test('migration assigns the active admin without publishing the email address', () => {
    assert.equal(migration.includes('@'), false);
    assert.equal(migration.includes(adminId), true);
    assert.match(migration, /admin_role is distinct from 'admin'/);
    assert.match(migration, /admin_suspended_at is not null or admin_deactivated_at is not null/);
    assert.match(migration, /digest\(lower\(btrim\(admin_email\)\), 'sha256'\)/);
    assert.match(migration, /'shared'/);
    assert.match(migration, /verified_at/);
    assert.match(migration, /verified_by/);
    assert.match(migration, /on conflict \(id\) do update/);
    assert.match(migration, /where existing\.deleted_at is null/);
});

test('paired rollback is limited to the deterministic seed IDs and admin owner', () => {
    const seedIds = new Set(seed.map(quiz => quiz.quiz_id));
    const rollbackIds = new Set(
        [...rollback.matchAll(/\('([0-9a-f-]{36})'::uuid\)/g)].map(match => match[1])
    );
    assert.deepEqual([...rollbackIds].sort(), [...seedIds].sort());
    assert.match(rollback, new RegExp(`quiz\\.teacher_id = '${adminId}'::uuid`));
    assert.doesNotMatch(rollback, /truncate/i);
});

async function database() {
    const db = new PGlite();
    await db.exec(`
        create table profiles (
            id uuid primary key,
            role text not null,
            email text not null,
            suspended_at timestamptz,
            deactivated_at timestamptz
        );
        create table quizzes (
            id uuid primary key,
            teacher_id uuid not null references profiles(id),
            topic text not null,
            grade_level integer not null,
            visibility text not null default 'shared',
            source_quiz_id uuid,
            version integer not null default 1,
            verified_at timestamptz,
            verified_by uuid references profiles(id),
            deleted_at timestamptz,
            deleted_by uuid,
            created_at timestamptz not null default now(),
            updated_at timestamptz not null default now()
        );
        create table quiz_questions (
            id bigint generated always as identity primary key,
            quiz_id uuid not null references quizzes(id) on delete cascade,
            position integer not null,
            grade integer not null,
            type text not null,
            question text not null,
            choice1 text not null,
            choice2 text not null,
            choice3 text not null,
            choice4 text not null,
            choice5 text,
            choice6 text,
            correct_answer text not null,
            created_at timestamptz not null default now(),
            unique (quiz_id, position)
        );
        create table mathverse_schema_migrations (
            migration_key text primary key,
            applied_at timestamptz not null default now()
        );
        insert into profiles (id, role, email)
        values ('${adminId}', 'admin', 'identity-checked-in-production@example.test');
    `);
    return db;
}

function executableMigration() {
    return migration
        .replace(/do \$admin_guard\$.*?\$admin_guard\$;/s, `
            do $admin_guard$
            begin
                if not exists (select 1 from profiles where id = '${adminId}' and role = 'admin') then
                    raise exception 'Test administrator is missing';
                end if;
            end
            $admin_guard$;
        `)
        .replace("notify pgrst, 'reload schema';", '');
}

test('migration creates and safely reseeds the 109 quizzes', async () => {
    const db = await database();
    try {
        const sql = executableMigration();
        await db.exec(sql);
        const counts = (await db.query(`
            select count(distinct quiz.id)::integer as quizzes,
                   count(question.id)::integer as questions
              from quizzes quiz
              join quiz_questions question on question.quiz_id = quiz.id
        `)).rows[0];
        assert.deepEqual(counts, { quizzes: 109, questions: 545 });

        const ownership = (await db.query(`
            select count(*)::integer as count
              from quizzes
             where teacher_id = $1 and verified_by = $1
               and verified_at is not null and visibility = 'shared'
        `, [adminId])).rows[0].count;
        assert.equal(ownership, 109);

        const trashedId = seed[0].quiz_id;
        await db.query("update quizzes set topic = 'Intentionally trashed', deleted_at = now() where id = $1", [trashedId]);
        await db.exec(sql);
        assert.equal((await db.query('select count(*)::integer as count from quizzes')).rows[0].count, 109);
        assert.equal((await db.query('select count(*)::integer as count from quiz_questions')).rows[0].count, 545);
        assert.equal((await db.query('select topic from quizzes where id = $1', [trashedId])).rows[0].topic, 'Intentionally trashed');
        assert.equal((await db.query(`
            select count(*)::integer as count from mathverse_schema_migrations
             where migration_key = '2026_09_20_curriculum_single_topic_quizzes.sql'
        `)).rows[0].count, 1);
    } finally {
        await db.close();
    }
});
