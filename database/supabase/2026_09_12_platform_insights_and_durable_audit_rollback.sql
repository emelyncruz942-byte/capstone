-- Restore application code first. Audit columns/history are retained.
begin;
create table if not exists public.rollback_privileged_audit_outbox_20260912
    as select * from public.privileged_audit_outbox;
revoke all on public.rollback_privileged_audit_outbox_20260912
    from public, anon, authenticated, service_role;
drop function if exists public.teacher_learning_hub_analytics(uuid, uuid, integer);
drop function if exists public.student_trophy_leaderboard(uuid, integer);
drop function if exists public.search_audit_logs(text, text, text, text, text, timestamptz, timestamptz, integer, integer);
drop function if exists public.complete_privileged_audit_intent(uuid, boolean, jsonb, text);
drop function if exists public.create_privileged_audit_intent(uuid, text, text, text, jsonb);
drop table if exists public.privileged_audit_outbox;
drop table if exists public.system_heartbeats;
drop table if exists public.mathverse_schema_migrations;
drop index if exists public.audit_logs_category_created_idx;
drop index if exists public.audit_logs_actor_created_idx;
drop index if exists public.audit_logs_action_created_idx;
drop index if exists public.audit_logs_correlation_idx;
notify pgrst, 'reload schema';
commit;
