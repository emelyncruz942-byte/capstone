-- Owner-only preparation; no public application records change.
create schema if not exists mathverse_development_import;
revoke all on schema mathverse_development_import from public, anon, authenticated, service_role;
create table if not exists mathverse_development_import.batches (
 batch_id uuid primary key, metadata jsonb not null, payload_digest text not null,
 state text not null default 'staged' check(state in ('staged','applied','rolled_back')),
 created_at timestamptz not null default now(), applied_at timestamptz, rolled_back_at timestamptz
);
create table if not exists mathverse_development_import.payload_rows (
 batch_id uuid not null references mathverse_development_import.batches(batch_id),
 phase text not null check(phase in ('before','after','delete')),
 table_name text not null, ordinal integer not null, row_data jsonb not null,
 primary key(batch_id,phase,table_name,ordinal)
);
create table if not exists mathverse_development_import.backup_rows (
 batch_id uuid not null references mathverse_development_import.batches(batch_id),
 phase text not null check(phase in ('before','after')),
 table_name text not null, ordinal integer not null, row_data jsonb not null,
 primary key(batch_id,phase,table_name,ordinal)
);
alter table mathverse_development_import.batches enable row level security;
alter table mathverse_development_import.payload_rows enable row level security;
alter table mathverse_development_import.backup_rows enable row level security;
revoke all on all tables in schema mathverse_development_import from public,anon,authenticated,service_role;
comment on schema mathverse_development_import is 'Owner-only reproducible development fixture provenance and transactional rollback backups; not exposed to application clients.';
