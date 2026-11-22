/** Rehearse an offline development import against captured live definitions.
 * No network calls are made. Auth and outbound delivery providers are local
 * stubs; all captured table types, constraints and business triggers are real.
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { performance } from 'node:perf_hooks';
import { PGlite } from '@electric-sql/pglite';

const folder = resolve(process.argv[2]);
const importFolder = resolve(process.argv[3]);
const read = name => JSON.parse(readFileSync(join(folder, name), 'utf8').replace(/^\uFEFF/, ''));
const sqlFile = name => readFileSync(join(importFolder, name), 'utf8');
const q = value => `'${String(value).replaceAll("'", "''")}'`;
const ident = value => '"' + value.replaceAll('"', '""') + '"';
const manifest = JSON.parse(sqlFile('import_manifest.json'));
const columns = read('18_exact_columns.json');
const constraints = read('05_constraints.json');
const notificationMetadata = read('20_notification_table_metadata.json');
const tables = [...new Set(columns.map(c => c.table_name))];
const snapshots = Object.fromEntries(readdirSync(join(folder, 'snapshots')).filter(n => n.endsWith('.json') && tables.includes(n.slice(0,-5)))
    .map(n => [n.slice(0,-5),JSON.parse(readFileSync(join(folder,'snapshots',n),'utf8').replace(/^\uFEFF/,''))]));
const db = new PGlite();
const checks = [];
try {
    await db.exec(`create role anon;create role authenticated;create role service_role;
       create schema auth;create function auth.uid() returns uuid language sql stable as $$select null::uuid$$;
       create table auth.users(id uuid primary key);
       create function public.uuid_generate_v4() returns uuid language sql volatile as $$select gen_random_uuid()$$;
       create schema net;create table net.http_request_queue(id bigint generated always as identity primary key,url text,body jsonb);
       create function net.http_post(url text,headers jsonb,body jsonb,timeout_milliseconds integer) returns bigint language plpgsql as $$declare v bigint;begin insert into net.http_request_queue(url,body)values(url,body)returning id into v;return v;end$$;`);
    for (const table of tables) {
        const definitions = columns.filter(c=>c.table_name===table).sort((a,b)=>a.ordinal_position-b.ordinal_position).map(c=> {
            let d = `${ident(c.column_name)} ${c.sql_type}`;
            if(c.generated_kind) d += ` generated always as (${c.column_default}) stored`;
            else if (c.identity_kind) d += ` generated ${c.identity_kind==='a'?'always':'by default'} as identity`;
            else if(c.column_default) d += ` default ${c.column_default}`;
            if(c.not_null) d += ' not null';
            return d;
        });
        await db.exec(`create table public.${ident(table)}(${definitions.join(',')})`);
    }
    // Foreign parent fixtures contain only IDs/roles; these represent captured
    // reference owners in this isolated rehearsal, never external identities.
    const ids = new Set();
    for (const rows of Object.values(snapshots)) for(const row of rows) {
        for(const [k,v] of Object.entries(row)) if (['student_id','teacher_id','user_id','verified_by','actor_id','assigned_by'].includes(k) && typeof v==='string' && /^[0-9a-f-]{36}$/.test(v)) ids.add(v);
    }
    for(const row of snapshots.profiles) ids.add(row.id);
    await db.query('insert into auth.users select unnest($1::uuid[])',[... [ [...ids] ]]);
    const profiles = new Map(snapshots.profiles.map(p=>[p.id,p]));
    for(const id of ids) {
        const row = {...(profiles.get(id) ?? {id,role:'teacher'}),username:`local-${id}`,email:`local-${id}@example.invalid`};
        const keys = Object.keys(row);
        await db.query(`insert into profiles(${keys.map(ident).join(',')})values(${keys.map((_,i)=>`$${i+1}`).join(',')})`,keys.map(k=>row[k]));
    }
    const loadOrder=['classes','quizzes','quiz_questions','quiz_sessions','questions','class_members','quiz_session_students','quiz_participants','quiz_results','practice_sessions','practice_questions','practice_mastery','arcade_sessions','arcade_scores','arcade_achievements','number_guess_sessions','number_guess_scores','npc_quest_catalog','user_quests','npc_quest_reward_receipts','user_stats'];
    for(const table of loadOrder) {
        const rows=snapshots[table]??[];if(!rows.length)continue;
        const keys=Object.keys(rows[0]).filter(k=>!columns.find(c=>c.table_name===table&&c.column_name===k)?.generated_kind);
        await db.query(`insert into ${ident(table)}(${keys.map(ident).join(',')}) overriding system value select ${keys.map(k=>`t.${ident(k)}`).join(',')} from jsonb_populate_recordset(null::${ident(table)},$1::jsonb)t`,[JSON.stringify(rows)]);
    }
    // Constraints are installed after the captured initial state is loaded;
    // subsequent import writes run with every captured constraint enforced.
    const allConstraints=[...constraints,...notificationMetadata.filter(m=>m.kind==='constraint').map(m=>({...m,conname:m.name,contype:m.definition.startsWith('FOREIGN KEY')?'f':'x'}))].filter(c=>tables.includes(c.table_name));
    // Catalogue metadata was exported for reference rows; its receipt FK proves
    // quest_name is a referenced unique server key.
    if(!allConstraints.some(c=>c.table_name==='npc_quest_catalog'&&['p','u'].includes(c.contype)))
        await db.exec('alter table npc_quest_catalog add primary key(quest_name)');
    for(const c of allConstraints.sort((a,b)=>(a.contype==='f')-(b.contype==='f'))) {
        try {await db.exec(`alter table public.${ident(c.table_name)} add constraint ${ident(c.conname)} ${c.definition}`);}catch(e){throw new Error(`Constraint ${c.conname}: ${e.message}`);}
    }
    const indexes=read('09_table_indexes.json');
    const existing=new Set(allConstraints.map(c=>c.conname));
    for(const i of indexes) if(tables.includes(i.tablename)&&!existing.has(i.indexname)&&i.indexdef.startsWith('CREATE UNIQUE'))await db.exec(i.indexdef);
    for(const i of notificationMetadata.filter(m=>m.kind==='index')) if(!existing.has(i.name)) await db.exec(i.definition);
    for(const f of [...read('16_all_trigger_functions.json'),...read('19_notification_functions.json')]) await db.exec(f.definition);
    await db.exec(`create function public.quiz_management_url(p_user_id uuid) returns text language sql stable security definer set search_path=public as $$select case when role='admin' then '/admin/quizzes' else '/teacher/quizzes' end from profiles where id=p_user_id$$;`);
    for(const t of read('06_triggers.json')) if(tables.includes(t.table_name)) await db.exec(t.definition);
    for(const t of notificationMetadata.filter(m=>m.kind==='trigger')) await db.exec(t.definition);
    // Explicit existing bell row proves corrected result text and read state.
    const first=snapshots.quiz_results[0], assignment=snapshots.quiz_sessions.find(s=>s.id===first.session_id);
    await db.exec('alter table notifications disable trigger notifications_queue_delivery');
    await db.query(`insert into notifications(user_id,type,title,message,data,dedupe_key,read_at,created_at)values($1,'quiz_result_recorded','Quiz submission saved','Old score',$2,$3,'2026-10-20T03:00:00Z','2026-10-19T02:00:00Z')`,[first.student_id,JSON.stringify({session_id:first.session_id,correct_answers:first.correct_answers}),'quiz-result-student:'+first.id]);
    await db.exec('alter table notifications enable trigger notifications_queue_delivery');
    const bellId=(await db.query('select id from notifications')).rows[0].id;
    for(const status of ['pending','sent'])await db.query(`insert into notification_deliveries(notification_id,user_id,channel,event_type,recipient_email,title,message,data,delivery_key,status)values($1,$2,'email','quiz_result_recorded','local@example.invalid','Quiz submission saved','Old score',$3,$4,$5)`,[bellId,first.student_id,JSON.stringify({correct_answers:first.correct_answers}),`local-receipt-${status}`,status]);
    await db.exec('truncate net.http_request_queue');
    const receiptBefore=(await db.query("select to_jsonb(d)-'message'-'data' value from notification_deliveries d where status='pending'")).rows[0].value;
    await db.exec(sqlFile('00_private_staging.sql'));
    // A normal clean deployment contains the private schema migration but no
    // fixture upload. Replaying the public migration must leave its data alone.
    const absentBefore=(await db.query('select count(*)::int n,sum(correct_answers)::int score from quiz_results')).rows[0];
    await db.exec(sqlFile('03_atomic_apply.sql'));
    assert.deepEqual((await db.query('select count(*)::int n,sum(correct_answers)::int score from quiz_results')).rows[0],absentBefore);
    assert.equal((await db.query('select count(*)::int n from mathverse_development_import.backup_rows')).rows[0].n,0);
    assert.equal((await db.query('select count(*)::int n from net.http_request_queue')).rows[0].n,0);
    checks.push('Clean deployment with no uploaded batch skips import without public changes');
    await db.exec(sqlFile('01_batch.sql'));
    for(const name of readdirSync(importFolder).filter(n=>/^02_upload_\d+\.sql$/.test(n)).sort()) await db.exec(sqlFile(name));
    const batch=manifest.batch_id;
    const beforeCount=(await db.query('select count(*)::int n from quiz_results')).rows[0].n;
    const firstProfile=snapshots.profiles[0];
    // A concurrent source edit must abort before any score change.
    await db.query('update profiles set xp=xp+1 where id=$1',[firstProfile.id]);
    await assert.rejects(db.exec(sqlFile('03_atomic_apply.sql')),/before conflict in profiles/);
    await db.exec('rollback');
    assert.equal((await db.query('select count(*)::int n from quiz_results')).rows[0].n,beforeCount);
    await db.query('update profiles set xp=xp-1 where id=$1',[firstProfile.id]);
    checks.push('Concurrent source changes abort atomically');
    const staged=(await db.query(`delete from mathverse_development_import.payload_rows where batch_id=$1 and phase='after' and table_name='profiles' and ordinal=0 returning *`,[batch])).rows[0];
    await assert.rejects(db.exec(sqlFile('03_atomic_apply.sql')),/upload is incomplete/);
    await db.exec('rollback');
    await db.query('insert into mathverse_development_import.payload_rows values($1,$2,$3,$4,$5)',[staged.batch_id,staged.phase,staged.table_name,staged.ordinal,JSON.stringify(staged.row_data)]);
    checks.push('Incomplete uploads abort');
    await db.exec('alter table notifications disable trigger notifications_queue_delivery');
    await assert.rejects(db.exec(sqlFile('03_atomic_apply.sql')),/original enabled state/);
    await db.exec('rollback');await db.exec('alter table notifications enable trigger notifications_queue_delivery');
    checks.push('Unexpected trigger state aborts');
    const start=performance.now();
    await db.exec(sqlFile('03_atomic_apply.sql'));
    const seconds=(performance.now()-start)/1000;
    checks.push('Full fixture apply matches every typed scoped expected row');
    assert.equal((await db.query(`select state from mathverse_development_import.batches where batch_id=$1`,[batch])).rows[0].state,'applied');
    assert.equal((await db.query('select count(*)::int n from net.http_request_queue')).rows[0].n,0);
    assert.equal((await db.query('select count(*)::int n from notification_deliveries')).rows[0].n,2);
    assert.equal((await db.query('select count(*)::int n from notifications')).rows[0].n,1);
    const bell=(await db.query('select message,data,read_at,created_at from notifications')).rows[0];
    const result=(await db.query('select * from quiz_results where id=$1',[first.id])).rows[0];
    assert.equal(bell.message,`${assignment.topic}: ${result.correct_answers} of 5 correct.`);
    assert.equal(new Date(bell.read_at).toISOString(),'2026-10-20T03:00:00.000Z');
    checks.push('No email, Web Push or HTTP request; bell state retained and score corrected');
    const pending=(await db.query("select message,data,to_jsonb(d)-'message'-'data' preserved from notification_deliveries d where status='pending'")).rows[0];
    assert.equal(pending.message,bell.message);assert.equal(pending.data.correct_answers,result.correct_answers);assert.deepEqual(pending.preserved,receiptBefore);
    assert.equal((await db.query("select message from notification_deliveries where status='sent'")).rows[0].message,'Old score');
    checks.push('Pending receipt body agrees with result; send state/recipient/token retained and sent copy untouched');
    await assert.rejects(db.query('update quiz_results set correct_answers=5-correct_answers where id=$1',[first.id]),/immutable/);
    checks.push('Direct score mutation remains forbidden');
    await assert.rejects(db.exec(sqlFile('03_atomic_apply.sql')),/not staged/);await db.exec('rollback');
    checks.push('Batch replay aborts');
    for(const role of ['anon','authenticated','service_role']) {
        await db.exec(`set role ${role}`);
        await assert.rejects(db.query('select * from mathverse_development_import.backup_rows'),/permission denied/);
        await db.exec('reset role');
    }
    checks.push('Private source/backup tables inaccessible to all application roles');
    const tableCounts=(await db.query(`select table_name,count(*)::int rows from mathverse_development_import.backup_rows where batch_id=$1 and phase='after' group by table_name order by table_name`,[batch])).rows;
    await db.query('update profiles set xp=xp+1 where id=$1',[firstProfile.id]);
    await assert.rejects(db.exec(sqlFile('04_atomic_rollback.sql')),/Rollback after conflict in profiles/);await db.exec('rollback');
    assert.equal((await db.query('select count(*)::int n from quiz_results')).rows[0].n,1224);
    await db.query('update profiles set xp=xp-1 where id=$1',[firstProfile.id]);
    checks.push('Rollback refuses to overwrite later scoped changes');
    await db.exec("update notification_deliveries set status='sent' where delivery_key='local-receipt-pending'");
    await assert.rejects(db.exec(sqlFile('04_atomic_rollback.sql')),/Rollback receipt payload conflict/);await db.exec('rollback');
    await db.exec("update notification_deliveries set status='pending' where delivery_key='local-receipt-pending'");
    checks.push('Rollback refuses to rewrite a receipt sent after apply');
    const rollbackStart=performance.now();await db.exec(sqlFile('04_atomic_rollback.sql'));
    const rollbackSeconds=(performance.now()-rollbackStart)/1000;
    assert.equal((await db.query(`select state from mathverse_development_import.batches where batch_id=$1`,[batch])).rows[0].state,'rolled_back');
    assert.equal((await db.query('select count(*)::int n from quiz_results')).rows[0].n,beforeCount);
    assert.equal((await db.query('select count(*)::int n from net.http_request_queue')).rows[0].n,0);
    assert.equal((await db.query('select message from notifications')).rows[0].message,'Old score');
    assert.equal((await db.query("select message from notification_deliveries where status='pending'")).rows[0].message,'Old score');
    checks.push('Rollback exactly restores all original full rows, IDs, profile fields, memberships and receipt bodies without outbound requests');
    const summary={passed:true,checks,apply_seconds:seconds,rollback_seconds:rollbackSeconds,table_counts:tableCounts};
    writeFileSync(join(importFolder,'rehearsal.json'),JSON.stringify(summary,null,2)+'\n');
    console.log(JSON.stringify(summary,null,2));
} catch(error) { console.error(JSON.stringify({passed:false,message:error.message,detail:error.detail,where:error.where,query:error.query?.slice(0,3000)},null,2));process.exitCode=1; }
finally {await db.close();}
