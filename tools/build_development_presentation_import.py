"""Build a reviewed, offline SQL import package for development fixtures.

This does not connect to a database. The caller must review and apply the private
staging DDL, bounded upload statements, and atomic apply SQL in that order.
All input records and before/after backups stay outside the public API schema.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import uuid

SCHEMA = "mathverse_development_import"
KEYS = {
    "class_members": ["class_id", "student_id"],
    "profiles": ["id"], "classes": ["id"], "quizzes": ["id"],
    "quiz_questions": ["id"], "npc_quest_catalog": ["quest_name"],
    "quiz_sessions": ["id"], "questions": ["id"],
    "quiz_results": ["id"], "quiz_participants": ["session_id", "student_id"],
    "quiz_session_students": ["session_id", "student_id"],
    "practice_sessions": ["id"], "practice_questions": ["id"],
    "practice_mastery": ["student_id", "grade_level", "competency_key"],
    "arcade_sessions": ["id"], "number_guess_sessions": ["id"],
    "arcade_scores": ["student_id", "game_key"], "number_guess_scores": ["student_id"],
    "arcade_achievements": ["student_id", "achievement_key"],
    "user_quests": ["user_id", "quest_name"],
    "npc_quest_reward_receipts": ["user_id", "quest_name"], "user_stats": ["user_id"],
}
MUTABLE = ["class_members", "quiz_sessions", "questions", "quiz_session_students",
           "quiz_participants", "quiz_results", "practice_sessions", "practice_questions",
           "practice_mastery", "arcade_sessions", "number_guess_sessions", "arcade_scores",
           "number_guess_scores", "arcade_achievements", "user_quests",
           "npc_quest_reward_receipts", "profiles"]
WINDOW = {"practice_sessions", "practice_questions", "arcade_sessions", "number_guess_sessions"}
APPEND = {"class_members", "questions"}
PROFILE_COLUMNS = ["class_id", "xp", "points", "trophies", "level"]


def read(path):
    return json.loads(Path(path).read_text(encoding="utf-8-sig"))


def quote(value):
    return "'" + str(value).replace("'", "''") + "'"


def ident(value):
    if not re.fullmatch(r"[a-z_][a-z_0-9]*", value):
        raise ValueError(f"Unsafe SQL identifier: {value}")
    return '"' + value + '"'


def array(values):
    return "ARRAY[" + ",".join(quote(v) for v in values) + "]::text[]"


def uuid_array(values):
    return "ARRAY[" + ",".join(quote(str(uuid.UUID(v))) + "::uuid" for v in values) + "]::uuid[]"


def rows_sql(batch, phase, table):
    return (f"select row_data from {SCHEMA}.payload_rows where batch_id={quote(batch)}::uuid "
            f"and phase={quote(phase)} and table_name={quote(table)}")


def json_rows_sql(batch, phase, table):
    return f"(select coalesce(jsonb_agg(row_data order by ordinal),'[]'::jsonb) from {SCHEMA}.payload_rows where batch_id={quote(batch)}::uuid and phase={quote(phase)} and table_name={quote(table)})"


def key_sql(alias, keys):
    return "jsonb_build_array(" + ",".join(f"{alias}.row_data->{quote(k)}" for k in keys) + ")"


def expected_sql(batch, spec):
    table, mode = spec["table"], spec["mode"]
    after = rows_sql(batch, "after", table)
    before = rows_sql(batch, "before", table)
    if mode == "replace":
        return after
    if mode == "readonly":
        return before
    if mode == "append":
        return before + " union all " + after
    if mode == "window":
        # Delete keys are normalized to complete key objects during packaging.
        deleted = rows_sql(batch, "delete", table)
        return (f"select b.row_data from ({before}) b where not exists "
                f"(select 1 from ({deleted}) d where {key_sql('b', KEYS[table])}={key_sql('d', KEYS[table])}) union all {after}")
    raise ValueError(mode)


def canonical_query(table, source, columns, scope=None):
    projection = "(select jsonb_object_agg(k,to_jsonb(t)->k) from unnest(" + array(columns) + ") k)"
    if scope is None:
        body = (f"select {projection} value from jsonb_populate_recordset(null::public.{ident(table)},"
                f"(select coalesce(jsonb_agg(row_data),'[]'::jsonb) from ({source}) records)) t")
    else:
        body = f"select {projection} value from public.{ident(table)} t where {scope}"
    return f"select coalesce(jsonb_agg(value order by value::text),'[]'::jsonb) from ({body}) normalized"


def comparison(batch, spec, phase="before", actual_source=None):
    columns = spec["before_columns"] if phase == "before" else spec["after_columns"]
    if phase == "after" and spec["table"] == "quizzes":
        # New assignments legitimately refresh the original shared quiz's
        # usage counter; content and other source fields remain unchanged.
        columns = [c for c in columns if c != "usage_count"]
    expected = rows_sql(batch, "before", spec["table"]) if phase == "before" else expected_sql(batch, spec)
    actual = canonical_query(spec["table"], None, columns, spec["scope"])
    if actual_source is not None:
        actual = canonical_query(spec["table"], actual_source, columns)
    wanted = canonical_query(spec["table"], expected, columns)
    return (f"if ({actual}) is distinct from ({wanted}) then raise exception "
            f"'Development import {phase} conflict in {spec['table']}; no changes committed'; end if;")


def backup_sql(batch, spec, phase):
    return (f"insert into {SCHEMA}.backup_rows(batch_id,phase,table_name,ordinal,row_data) "
            f"select {quote(batch)}::uuid,{quote(phase)},{quote(spec['table'])},"
            f"row_number() over(order by to_jsonb(t)::text),to_jsonb(t) from public.{ident(spec['table'])} t where {spec['scope']};")


def insert_sql(batch, table, columns, phase="after", order="", upsert=False, source=None):
    source = source or json_rows_sql(batch, phase, table)
    cols = ",".join(ident(k) for k in columns)
    selected = ",".join("t." + ident(k) for k in columns)
    sql = (f"insert into public.{ident(table)} ({cols}) overriding system value "
           f"select {selected} from jsonb_populate_recordset(null::public.{ident(table)},{source}) t {order}")
    if upsert:
        keys = KEYS[table]
        updates = ",".join(f"{ident(k)}=excluded.{ident(k)}" for k in columns if k not in keys)
        sql += " on conflict (" + ",".join(ident(k) for k in keys) + ") " + ("do update set " + updates if updates else "do nothing")
    return sql + ";"


def delete_sql(batch, table):
    source = json_rows_sql(batch, "delete", table)
    keys = KEYS[table]
    conditions = " and ".join(f"t.{ident(k)}=d.{ident(k)}" for k in keys)
    return f"delete from public.{ident(table)} t using jsonb_populate_recordset(null::public.{ident(table)},{source}) d where {conditions};"


def ddl_sql():
    return f"""-- Owner-only preparation; no public application records change.
create schema if not exists {SCHEMA};
revoke all on schema {SCHEMA} from public, anon, authenticated, service_role;
create table if not exists {SCHEMA}.batches (
 batch_id uuid primary key, metadata jsonb not null, payload_digest text not null,
 state text not null default 'staged' check(state in ('staged','applied','rolled_back')),
 created_at timestamptz not null default now(), applied_at timestamptz, rolled_back_at timestamptz
);
create table if not exists {SCHEMA}.payload_rows (
 batch_id uuid not null references {SCHEMA}.batches(batch_id),
 phase text not null check(phase in ('before','after','delete')),
 table_name text not null, ordinal integer not null, row_data jsonb not null,
 primary key(batch_id,phase,table_name,ordinal)
);
create table if not exists {SCHEMA}.backup_rows (
 batch_id uuid not null references {SCHEMA}.batches(batch_id),
 phase text not null check(phase in ('before','after')),
 table_name text not null, ordinal integer not null, row_data jsonb not null,
 primary key(batch_id,phase,table_name,ordinal)
);
alter table {SCHEMA}.batches enable row level security;
alter table {SCHEMA}.payload_rows enable row level security;
alter table {SCHEMA}.backup_rows enable row level security;
revoke all on all tables in schema {SCHEMA} from public,anon,authenticated,service_role;
comment on schema {SCHEMA} is 'Owner-only reproducible development fixture provenance and transactional rollback backups; not exposed to application clients.';
"""


def body_sql(batch, specs, payload, rollback=False):
    by_table = {s["table"]: s for s in specs}
    mutable = [s for s in specs if s["table"] in MUTABLE]
    all_scopes = {s["table"]: s["scope"] for s in specs}
    scope_sessions = all_scopes["quiz_sessions"]
    session_ids = f"select id from public.quiz_sessions t where {scope_sessions}"
    lines = []
    if rollback:
        raise ValueError("Rollback is emitted separately from backup rows, not the generated fixture payload")
    # Insert only the four missing memberships, leaving existing memberships intact.
    lines.append(insert_sql(batch, "class_members", list(payload["class_members"][0]), upsert=False))
    # Clear child rows first, while preserving existing assignments and question IDs.
    for table in ("quiz_results", "quiz_participants", "quiz_session_students"):
        lines.append(f"delete from public.{ident(table)} t where {all_scopes[table]};")
    session_columns = list(payload["quiz_sessions"][0])
    # Hold all assignments completed while importing them one at a time. A
    # unique partial index allows only one open class/source assignment.
    lines.append(insert_sql(batch, "quiz_sessions", session_columns, upsert=True))
    lines.append(insert_sql(batch, "questions", list(payload["questions"][0])))
    # Parent INSERT automatically seeds current memberships. Replace that temporary
    # ledger with the reviewed account-creation/membership-aware fixture ledger.
    lines.append(f"delete from public.quiz_session_students t where {all_scopes['quiz_session_students']};")
    elig_cols = list(payload["quiz_session_students"][0])
    elig_source = (f"(select coalesce(jsonb_agg(e.row_data || jsonb_build_object('allowed_attempts',greatest(1,(select count(*) from ({rows_sql(batch,'after','quiz_results')}) r "
                   "where r.row_data->>'session_id'=e.row_data->>'session_id' and r.row_data->>'student_id'=e.row_data->>'student_id')),'retake_due_at',null)), '[]'::jsonb) "
                   f"from ({rows_sql(batch,'after','quiz_session_students')}) e)")
    lines.append(insert_sql(batch, "quiz_session_students", elig_cols, source=elig_source))
    lines.append(insert_sql(batch, "quiz_participants", list(payload["quiz_participants"][0])))
    lines.append(f"for session_row in select * from jsonb_populate_recordset(null::public.quiz_sessions,{json_rows_sql(batch,'after','quiz_sessions')}) order by id loop")
    lines.append("update public.quiz_sessions set status='active',is_active=true,available_at=null,due_at=null where id=session_row.id;")
    result_source = f"(select coalesce(jsonb_agg(row_data),'[]'::jsonb) from ({rows_sql(batch,'after','quiz_results')}) r where r.row_data->>'session_id'=session_row.id::text)"
    lines.append(insert_sql(batch, "quiz_results", list(payload["quiz_results"][0]), source=result_source, order="order by t.student_id,t.attempt_number,t.created_at,t.id"))
    # Normal completed-state guard freezes used attempts. The final explicit
    # eligibility upsert then restores documented retake allowances and dates.
    final_session_source=f"(select jsonb_build_array(to_jsonb(session_row)))"
    lines.append(insert_sql(batch, "quiz_sessions", session_columns, source=final_session_source, upsert=True))
    lines.append("end loop;")
    lines.append(insert_sql(batch, "quiz_session_students", elig_cols, upsert=True))
    for table in ("practice_questions", "practice_sessions", "arcade_sessions", "number_guess_sessions"):
        lines.append(delete_sql(batch, table))
    for table in ("practice_sessions", "practice_questions", "arcade_sessions", "number_guess_sessions"):
        lines.append(insert_sql(batch, table, list(payload[table][0])))
    for table in ("practice_mastery", "arcade_scores", "number_guess_scores", "arcade_achievements", "npc_quest_reward_receipts", "user_quests"):
        lines.append(f"delete from public.{ident(table)} t where {all_scopes[table]};")
        lines.append(insert_sql(batch, table, list(payload[table][0])))
    profile_source = json_rows_sql(batch, "after", "profiles")
    lines.append("update public.profiles p set " + ",".join(f"{ident(k)}=t.{ident(k)}" for k in PROFILE_COLUMNS) +
                 f" from jsonb_populate_recordset(null::public.profiles,{profile_source}) t where p.id=t.id;")
    return "\n".join(lines)


def apply_sql(batch, specs, payload):
    scopes = {s["table"]: s["scope"] for s in specs}
    batchq = quote(batch) + "::uuid"
    manifest = payload["_manifest"]
    notification_scope = "n.user_id=any(notification_user_ids)"
    lock_tables = ",".join("public." + ident(s["table"]) for s in specs)
    prepare = []
    for spec in specs:
        prepare.append(comparison(batch, spec))
        if spec["table"] in MUTABLE or spec["table"] == "quizzes":
            prepare.append(backup_sql(batch, spec, "before"))
    finalize = []
    for spec in specs:
        finalize.append(comparison(batch, spec, "after"))
        if spec["table"] in MUTABLE or spec["table"] == "quizzes":
            finalize.append(backup_sql(batch, spec, "after"))
    return f"""-- Atomic developer-fixture import. Run as database owner, never from a browser.
begin;
set local statement_timeout='300s';
set local lock_timeout='10s';
set local timezone='UTC';
set local standard_conforming_strings=on;
select pg_advisory_xact_lock(hashtext('mathverse_development_presentation_import'));
do $fixture$
declare
 current_batch {SCHEMA}.batches%rowtype;
 notification_ids uuid[];
 notification_user_ids uuid[];
 deliveries_before bigint;
 trigger_states jsonb;
 session_row record;
begin
 select * into current_batch from {SCHEMA}.batches where batch_id={batchq} for update;
 -- The public schema migration also runs in clean environments, where these
 -- private presentation payloads have deliberately never been uploaded.
 if not found then
  raise notice 'No staged development fixture batch; skipping presentation data import';
  return;
 end if;
 if current_batch.state <> 'staged' then raise exception 'Import batch is not staged'; end if;
 lock table {lock_tables},public.notifications,public.notification_deliveries in share row exclusive mode;
 if (select tgenabled from pg_trigger where tgrelid='public.notifications'::regclass and tgname='notifications_queue_delivery') is distinct from 'O'::"char" then
  raise exception 'Notification queue trigger is not in its reviewed original enabled state';
 end if;
 select jsonb_object_agg(t.tgrelid::text||':'||t.tgname,t.tgenabled) into trigger_states from pg_trigger t where not t.tgisinternal and t.tgrelid in (select c.oid from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public');
 -- Verify staged row counts so incomplete/reordered uploads cannot be applied.
 if exists(select 1 from jsonb_array_elements(current_batch.metadata->'upload_counts') x where
  (select count(*) from {SCHEMA}.payload_rows r where r.batch_id={batchq} and r.phase=x->>'phase' and r.table_name=x->>'table') <> (x->>'count')::integer) then
  raise exception 'Fixture upload is incomplete';
 end if;
 {chr(10).join(prepare)}
 select array_agg(distinct user_id) into notification_user_ids from (
  select unnest({uuid_array(manifest['student_ids'] + [manifest['teacher_id']])}) user_id
  union select q.teacher_id from public.quizzes q where q.id in
   (select (row_data->>'source_quiz_id')::uuid from ({rows_sql(batch,'after','quiz_sessions')}) a)
 ) recipients;
 select coalesce(array_agg(id),'{{}}'::uuid[]) into notification_ids from public.notifications n where {notification_scope};
 select count(*) into deliveries_before from public.notification_deliveries;
 insert into {SCHEMA}.backup_rows(batch_id,phase,table_name,ordinal,row_data)
 select {batchq},'before','notifications',row_number() over(order by id),to_jsonb(n) from public.notifications n where {notification_scope};
 insert into {SCHEMA}.backup_rows(batch_id,phase,table_name,ordinal,row_data)
 select {batchq},'before','notification_delivery_payloads',row_number() over(order by d.id),jsonb_build_object('id',d.id,'status',d.status,'message',d.message,'data',d.data)
 from public.notification_deliveries d join public.notifications n on n.id=d.notification_id
 join public.quiz_results r on n.dedupe_key='quiz-result-student:'||r.id::text
 where d.status in ('pending','failed') and ({scopes['quiz_results'].replace('t.', 'r.')});
 -- This one side-effect bridge queues both Web Push and receipt emails, whose
 -- downstream trigger calls net.http_post. Its transaction lock prevents other
 -- writers from observing suppression. Business/auth/grade/immutability guards
 -- and all upstream notification triggers remain enabled throughout.
 alter table public.notifications disable trigger notifications_queue_delivery;
 {body_sql(batch, specs, payload)}
 -- No imported development event is delivered or added to the bell. Existing
 -- bell rows retain their timestamps/read state; retained result score text is
 -- corrected below rather than leaving a stale score in an old notification.
 delete from public.notifications n where {notification_scope} and not(n.id=any(notification_ids));
 update public.notifications n set
 message=coalesce(s.topic,'Quiz')||': '||r.correct_answers::text||' of '||r.total_questions::text||' correct.',
 data=n.data||jsonb_build_object('correct_answers',r.correct_answers,'total_questions',r.total_questions,'attempt_number',r.attempt_number,'is_counted',r.is_counted)
 from public.quiz_results r join public.quiz_sessions s on s.id=r.session_id
 where n.dedupe_key='quiz-result-student:'||r.id::text and ({scopes['quiz_results'].replace('t.', 'r.')});
 -- Existing unsent receipts must agree with the corrected stored result too.
 -- Change only their body/data, preserving recipients, tokens, send state and
 -- timestamps. Already-sent historical copies remain untouched.
 update public.notification_deliveries d set message=n.message,data=d.data||jsonb_build_object('correct_answers',r.correct_answers,'total_questions',r.total_questions,'attempt_number',r.attempt_number,'is_counted',r.is_counted)
 from public.notifications n join public.quiz_results r on n.dedupe_key='quiz-result-student:'||r.id::text
 where d.notification_id=n.id and d.status in ('pending','failed') and ({scopes['quiz_results'].replace('t.', 'r.')});
 alter table public.notifications enable trigger notifications_queue_delivery;
 if (select count(*) from public.notification_deliveries)<>deliveries_before then raise exception 'Import unexpectedly queued a notification delivery'; end if;
 if exists(select 1 from public.quizzes t where {scopes['quizzes']} and t.usage_count<>(select count(*) from public.quiz_sessions s where s.source_quiz_id=t.id and s.class_id is not null and s.teacher_id<>t.teacher_id)) then raise exception 'Shared quiz usage counter mismatch'; end if;
 if (select jsonb_object_agg(t.tgrelid::text||':'||t.tgname,t.tgenabled) from pg_trigger t where not t.tgisinternal and t.tgrelid in (select c.oid from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public')) is distinct from trigger_states then raise exception 'Trigger states changed'; end if;
 {chr(10).join(finalize)}
 insert into {SCHEMA}.backup_rows(batch_id,phase,table_name,ordinal,row_data)
 select {batchq},'after','notifications',row_number() over(order by id),to_jsonb(n) from public.notifications n where {notification_scope};
 insert into {SCHEMA}.backup_rows(batch_id,phase,table_name,ordinal,row_data)
 select {batchq},'after','notification_delivery_payloads',b.ordinal,jsonb_build_object('id',d.id,'status',d.status,'message',d.message,'data',d.data)
 from {SCHEMA}.backup_rows b join public.notification_deliveries d on d.id=(b.row_data->>'id')::uuid
 where b.batch_id={batchq} and b.phase='before' and b.table_name='notification_delivery_payloads';
 update {SCHEMA}.batches set state='applied',applied_at=now() where batch_id={batchq};
end $fixture$;
commit;
select batch_id,state,applied_at from {SCHEMA}.batches where batch_id={batchq};
"""


def rollback_sql(batch, specs, before, payload):
    """Restore the exact atomic backup only if no scoped row changed since apply."""
    batchq = quote(batch)+"::uuid"
    scopes = {s["table"]:s["scope"] for s in specs}
    lock_tables = ",".join("public."+ident(s["table"]) for s in specs)
    manifest=payload["_manifest"]
    def records(table, phase="before"):
        return f"select row_data from {SCHEMA}.backup_rows where batch_id={batchq} and phase={quote(phase)} and table_name={quote(table)}"
    def js(table, phase="before"):
        return f"(select coalesce(jsonb_agg(row_data order by ordinal),'[]'::jsonb) from {SCHEMA}.backup_rows where batch_id={batchq} and phase={quote(phase)} and table_name={quote(table)})"
    managed=[s for s in specs if s["table"] in MUTABLE or s["table"]=="quizzes"]
    def backup_check(spec, phase):
        table=spec["table"]
        actual=f"select coalesce(jsonb_agg(to_jsonb(t) order by to_jsonb(t)::text),'[]'::jsonb) from public.{ident(table)} t where {spec['scope']}"
        expected=f"select coalesce(jsonb_agg(row_data order by row_data::text),'[]'::jsonb) from ({records(table,phase)}) b"
        return f"if ({actual}) is distinct from ({expected}) then raise exception 'Rollback {phase} conflict in {table}; no changes committed'; end if;"
    preflight="\n".join(backup_check(s,"after") for s in managed)
    checks="\n".join(backup_check(s,"before") for s in managed)
    actions=[]
    for table in ("quiz_results","quiz_participants","quiz_session_students"):
        actions.append(f"delete from public.{ident(table)} t where {scopes[table]};")
    # Remove only newly inserted question IDs. Every original assigned question
    # and its ID is retained throughout the import and rollback.
    actions.append(f"delete from public.questions t where {scopes['questions']} and not exists(select 1 from ({records('questions')}) b where (b.row_data->>'id')::bigint=t.id);")
    # Original open assignments are held completed while restoring each result
    # set separately, preserving the one-open-class/source unique index.
    actions.append(f"update public.quiz_sessions t set status='completed',is_active=false where {scopes['quiz_sessions']};")
    actions.append(f"delete from public.quiz_sessions t where {scopes['quiz_sessions']} and not exists(select 1 from ({records('quiz_sessions')}) b where (b.row_data->>'id')::uuid=t.id);")
    # Remove newly added memberships before restoring the old eligibility ledger;
    # the normal membership-removal trigger may revoke open-quiz eligibility.
    actions.append(f"delete from public.class_members t using jsonb_populate_recordset(null::public.class_members,{json_rows_sql(batch,'after','class_members')}) p where t.class_id=p.class_id and t.student_id=p.student_id;")
    old_session_cols=list(before["quiz_sessions"][0])
    held_sessions=f"(select coalesce(jsonb_agg(row_data||jsonb_build_object('status','completed','is_active',false)),'[]'::jsonb) from ({records('quiz_sessions')}) s)"
    actions.append(insert_sql(batch,"quiz_sessions",old_session_cols,source=held_sessions,upsert=True))
    # A new parent INSERT is not expected for old IDs, but clear all seed rows
    # before recreating the temporary account-aware attempt ledger.
    actions.append(f"delete from public.quiz_session_students t where {scopes['quiz_session_students']};")
    elig_source=(f"(select coalesce(jsonb_agg(e.row_data||jsonb_build_object('allowed_attempts',greatest(1,(select count(*) from ({records('quiz_results')}) r where r.row_data->>'session_id'=e.row_data->>'session_id' and r.row_data->>'student_id'=e.row_data->>'student_id')),'retake_due_at',null)),'[]'::jsonb) from ({records('quiz_session_students')}) e)")
    actions.append(insert_sql(batch,"quiz_session_students",list(before["quiz_session_students"][0]),source=elig_source))
    actions.append(insert_sql(batch,"quiz_participants",list(before["quiz_participants"][0]),source=js("quiz_participants")))
    actions.append(f"for session_row in select * from jsonb_populate_recordset(null::public.quiz_sessions,{js('quiz_sessions')}) order by id loop")
    actions.append("update public.quiz_sessions set status='active',is_active=true,available_at=null,due_at=null where id=session_row.id;")
    old_results=f"(select coalesce(jsonb_agg(row_data),'[]'::jsonb) from ({records('quiz_results')}) r where r.row_data->>'session_id'=session_row.id::text)"
    actions.append(insert_sql(batch,"quiz_results",list(before["quiz_results"][0]),source=old_results,order="order by t.student_id,t.attempt_number,t.created_at,t.id"))
    actions.append(insert_sql(batch,"quiz_sessions",old_session_cols,source="(select jsonb_build_array(to_jsonb(session_row)))",upsert=True))
    actions.append("end loop;")
    actions.append(insert_sql(batch,"quiz_session_students",list(before["quiz_session_students"][0]),source=js("quiz_session_students"),upsert=True))
    for table in ("practice_questions","practice_sessions","arcade_sessions","number_guess_sessions"):
        keys=KEYS[table]
        conditions=" and ".join(f"t.{ident(k)}=p.{ident(k)}" for k in keys)
        actions.append(f"delete from public.{ident(table)} t using jsonb_populate_recordset(null::public.{ident(table)},{json_rows_sql(batch,'after',table)}) p where {conditions};")
    for table in ("practice_sessions","practice_questions","arcade_sessions","number_guess_sessions"):
        # Only the original replaced-window rows need restoration; retained
        # earlier records have not been removed or updated.
        match=" and ".join(f"b.row_data->{quote(k)}=d.row_data->{quote(k)}" for k in KEYS[table])
        old_window=f"(select coalesce(jsonb_agg(b.row_data),'[]'::jsonb) from ({records(table)}) b where exists(select 1 from ({rows_sql(batch,'delete',table)}) d where {match}))"
        actions.append(insert_sql(batch,table,list(before[table][0]),source=old_window))
    for table in ("practice_mastery","arcade_scores","number_guess_scores","arcade_achievements","npc_quest_reward_receipts","user_quests"):
        actions.append(f"delete from public.{ident(table)} t where {scopes[table]};")
        cols=list(before[table][0]) if before[table] else list(payload[table][0])
        actions.append(insert_sql(batch,table,cols,source=js(table)))
    actions.append("update public.profiles p set "+",".join(f"{ident(k)}=t.{ident(k)}" for k in PROFILE_COLUMNS)+f" from jsonb_populate_recordset(null::public.profiles,{js('profiles')}) t where p.id=t.id;")
    notif_scope="n.user_id=any(notification_user_ids)"
    actions.append(f"delete from public.notifications n where {notif_scope} and not exists(select 1 from ({records('notifications')}) b where (b.row_data->>'id')::uuid=n.id);")
    actions.append(f"update public.notifications n set message=b.message,data=b.data from jsonb_populate_recordset(null::public.notifications,{js('notifications')}) b where b.id=n.id;")
    actions.append(f"update public.notification_deliveries d set message=b.message,data=b.data from jsonb_populate_recordset(null::public.notification_deliveries,{js('notification_delivery_payloads')}) b where b.id=d.id;")
    return f"""-- Exact transactional recovery; refuses to overwrite later scoped activity.
begin;
set local statement_timeout='300s';set local lock_timeout='10s';
set local timezone='UTC';set local standard_conforming_strings=on;
select pg_advisory_xact_lock(hashtext('mathverse_development_presentation_import'));
lock table {lock_tables},public.notifications,public.notification_deliveries in share row exclusive mode;
do $rollback$
declare current_batch {SCHEMA}.batches%rowtype;session_row record;notification_user_ids uuid[];deliveries_before bigint;trigger_states jsonb;
begin
 select * into strict current_batch from {SCHEMA}.batches where batch_id={batchq} for update;
 if current_batch.state<>'applied' then raise exception 'Rollback requires an applied batch'; end if;
 if (select tgenabled from pg_trigger where tgrelid='public.notifications'::regclass and tgname='notifications_queue_delivery') is distinct from 'O'::"char" then raise exception 'Notification queue trigger is not enabled'; end if;
 select jsonb_object_agg(t.tgrelid::text||':'||t.tgname,t.tgenabled) into trigger_states from pg_trigger t where not t.tgisinternal and t.tgrelid in(select c.oid from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public');
 {preflight}
 select array_agg(distinct user_id) into notification_user_ids from(
  select unnest({uuid_array(manifest['student_ids']+[manifest['teacher_id']])}) user_id
  union select q.teacher_id from public.quizzes q where q.id in(select (row_data->>'source_quiz_id')::uuid from ({records('quiz_sessions')}) s)
 ) recipients;
 if (select coalesce(jsonb_agg(to_jsonb(n) order by to_jsonb(n)::text),'[]'::jsonb) from public.notifications n where {notif_scope}) is distinct from
 (select coalesce(jsonb_agg(row_data order by row_data::text),'[]'::jsonb) from ({records('notifications','after')}) b) then raise exception 'Rollback notification conflict'; end if;
 if exists(select 1 from ({records('notification_delivery_payloads','after')}) b left join public.notification_deliveries d on d.id=(b.row_data->>'id')::uuid where d.id is null or jsonb_build_object('id',d.id,'status',d.status,'message',d.message,'data',d.data) is distinct from b.row_data) then raise exception 'Rollback receipt payload conflict'; end if;
 select count(*) into deliveries_before from public.notification_deliveries;
 alter table public.notifications disable trigger notifications_queue_delivery;
 {chr(10).join(actions)}
 alter table public.notifications enable trigger notifications_queue_delivery;
 if (select count(*) from public.notification_deliveries)<>deliveries_before then raise exception 'Rollback unexpectedly queued deliveries'; end if;
 if (select jsonb_object_agg(t.tgrelid::text||':'||t.tgname,t.tgenabled) from pg_trigger t where not t.tgisinternal and t.tgrelid in(select c.oid from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public')) is distinct from trigger_states then raise exception 'Rollback trigger states changed'; end if;
 {checks}
 update {SCHEMA}.batches set state='rolled_back',rolled_back_at=now() where batch_id={batchq};
end $rollback$;
commit;
select batch_id,state,rolled_back_at from {SCHEMA}.batches where batch_id={batchq};
"""


def package(snapshot, generated, output, chunk_limit=200_000):
    manifest = read(generated / "manifest.json")
    before = {p.stem: read(p) for p in snapshot.glob("*.json") if p.stem in KEYS}
    after = {p.stem: read(p) for p in (generated / "tables").glob("*.json")}
    deletes = read(generated / "deletes.json")
    classes = list(manifest["classes"].values())
    students = manifest["student_ids"]
    old_sessions = [r["id"] for r in before["quiz_sessions"]]
    new_sessions = [r["id"] for r in after["quiz_sessions"]]
    session_ids = sorted(set(old_sessions + new_sessions))
    specs = []
    for table in sorted(before):
        b, a = before[table], after.get(table, [])
        if table == "quiz_sessions":
            scope = "t.class_id=any(" + uuid_array(classes) + ")"
        elif table in {"questions", "quiz_results", "quiz_participants", "quiz_session_students"}:
            scope = "t.session_id=any(" + uuid_array(session_ids) + ")"
        elif table in {"class_members", "practice_sessions", "practice_questions", "practice_mastery", "arcade_sessions", "arcade_scores", "arcade_achievements", "number_guess_sessions", "number_guess_scores"}:
            scope = "t.student_id=any(" + uuid_array(students) + ")"
        elif table in {"user_quests", "npc_quest_reward_receipts", "user_stats"}:
            scope = "t.user_id=any(" + uuid_array(students) + ")"
        elif table == "profiles":
            scope = "t.id=any(" + uuid_array(students) + ")"
        else:
            # Read-only reference data is bounded to exact exported keys.
            key = KEYS[table][0]
            values = [str(r[key]) for r in b]
            scope = "t." + ident(key) + "::text=any(" + array(values) + ")"
        mode = "readonly" if table not in MUTABLE else "window" if table in WINDOW else "append" if table in APPEND else "replace"
        bcols = list(b[0]) if b else list(a[0]) if a else []
        acols = list(a[0]) if a and mode == "replace" else bcols
        if table == "questions":
            acols = [c for c in bcols if c != "id"]
        specs.append({"table": table, "scope": scope, "mode": mode, "before_columns": bcols, "after_columns": acols})
    digest = hashlib.sha256(json.dumps({"before":before,"after":after,"deletes":deletes},sort_keys=True,separators=(",", ":")).encode()).hexdigest()
    batch = str(uuid.uuid5(uuid.UUID("bf44138d-a79d-48e9-91ab-04e1cc9d4054"), digest))
    staging = []
    counts = []
    for phase, tables in (("before", before), ("after", after), ("delete", deletes)):
        for table, rows in sorted(tables.items()):
            if table not in KEYS:
                raise ValueError(f"Unreviewed table: {table}")
            if phase == "delete":
                rows = [r if isinstance(r,dict) else {KEYS[table][0]:r} for r in rows]
            counts.append({"phase":phase,"table":table,"count":len(rows)})
            for ordinal, row in enumerate(rows):
                staging.append((phase,table,ordinal,row))
    metadata = {"purpose":"Synthetic developer presentation fixtures; not measured student outcomes", "specs": specs, "upload_counts":counts, "fixture_manifest":manifest}
    output.mkdir(parents=True, exist_ok=True)
    (output / "00_private_staging.sql").write_text(ddl_sql(),encoding="utf-8")
    header = f"set standard_conforming_strings=on;\ninsert into {SCHEMA}.batches(batch_id,metadata,payload_digest) values({quote(batch)}::uuid,{quote(json.dumps(metadata,separators=(',',':'),ensure_ascii=False))}::jsonb,{quote(digest)}) on conflict(batch_id) do nothing;\n"
    (output / "01_batch.sql").write_text(header,encoding="utf-8")
    prefix = f"set standard_conforming_strings=on;\ninsert into {SCHEMA}.payload_rows(batch_id,phase,table_name,ordinal,row_data) values\n"
    suffix = "\non conflict(batch_id,phase,table_name,ordinal) do nothing;\n"
    chunks, current, length = [], [], len(prefix)+len(suffix)
    for phase, table, ordinal, row in staging:
        value = "("+",".join((quote(batch)+"::uuid",quote(phase),quote(table),str(ordinal),quote(json.dumps(row,separators=(",",":"),ensure_ascii=False))+"::jsonb"))+")"
        size = len(value.encode("utf-8"))+2
        if current and length+size > chunk_limit:
            chunks.append(prefix+",\n".join(current)+suffix)
            current, length = [], len(prefix)+len(suffix)
        current.append(value); length += size
    if current:
        chunks.append(prefix+",\n".join(current)+suffix)
    for i, sql in enumerate(chunks):
        (output/f"02_upload_{i:03d}.sql").write_text(sql,encoding="utf-8")
    (output / "03_atomic_apply.sql").write_text(apply_sql(batch,specs,{**after,"_manifest":manifest}),encoding="utf-8")
    (output / "04_atomic_rollback.sql").write_text(rollback_sql(batch,specs,before,{**after,"_manifest":manifest}),encoding="utf-8")
    result = {"batch_id":batch,"payload_digest":digest,"upload_chunks":len(chunks),"specs":specs,"upload_counts":counts}
    (output / "import_manifest.json").write_text(json.dumps(result,indent=2)+"\n",encoding="utf-8")
    return result


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot",type=Path,required=True)
    parser.add_argument("--generated",type=Path,required=True)
    parser.add_argument("--output",type=Path,required=True)
    parser.add_argument("--chunk-limit",type=int,default=200_000)
    parser.add_argument("--transactions-only",action="store_true",help="Do not rewrite already-uploaded staging files")
    args=parser.parse_args()
    if args.transactions_only:
        current=read(args.output/"import_manifest.json")
        before={p.stem:read(p) for p in args.snapshot.glob("*.json") if p.stem in KEYS}
        payload={p.stem:read(p) for p in (args.generated/"tables").glob("*.json")}
        payload["_manifest"]=read(args.generated/"manifest.json")
        (args.output/"03_atomic_apply.sql").write_text(apply_sql(current["batch_id"],current["specs"],payload),encoding="utf-8")
        (args.output/"04_atomic_rollback.sql").write_text(rollback_sql(current["batch_id"],current["specs"],before,payload),encoding="utf-8")
        print(json.dumps({"batch_id":current["batch_id"],"transactions_only":True}))
        return
    result=package(args.snapshot,args.generated,args.output,args.chunk_limit)
    print(json.dumps({k:result[k] for k in ("batch_id","payload_digest","upload_chunks")},indent=2))


if __name__ == "__main__":
    main()
