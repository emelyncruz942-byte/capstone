-- Remove only the deterministic curriculum quizzes created by
-- 2026_09_20_curriculum_single_topic_quizzes.sql. Assigned sessions retain their source_quiz_id as NULL.

begin;
set local search_path = pg_catalog, public;

do $admin_guard$
begin
    if not exists (
        select 1 from public.profiles
         where id = '7607da46-85b3-4cdc-851d-23fd4eb5223e'::uuid
           and role::text = 'admin'
           and suspended_at is null
           and deactivated_at is null
    ) then
        raise exception 'The configured curriculum quiz administrator is not active';
    end if;
end
$admin_guard$;

create temporary table curriculum_quiz_rollback_ids (quiz_id uuid primary key) on commit drop;
insert into pg_temp.curriculum_quiz_rollback_ids (quiz_id) values
    ('2f0af2e1-01ec-5dc8-8c64-ecb2ca8a779a'::uuid),
    ('62ff431a-e39a-59b6-8538-f71ba181ea5b'::uuid),
    ('3a66908c-57c5-5060-bebb-262b60351e26'::uuid),
    ('a7f1ba33-f66d-5f54-bc08-1099766c2f8b'::uuid),
    ('33b7871c-e65f-5fdf-831a-ca768f29cd52'::uuid),
    ('84e4f637-d950-5129-8140-57e7a068e8b7'::uuid),
    ('73f54f09-1026-559c-a0c7-6850aa37f443'::uuid),
    ('64ae5990-092b-5f8c-995f-9190a4481224'::uuid),
    ('8e752e0f-3fce-5921-b2ec-690fe5271317'::uuid),
    ('6db34a95-ba75-5e9a-9be4-ac671ad1c6ab'::uuid),
    ('e0e36f14-e492-5a77-a2d9-bc0351b299c4'::uuid),
    ('e94ad532-8141-5ce8-a3bb-13934f4a33a6'::uuid),
    ('6890a1ac-be59-59aa-9af2-4aaf4974a80b'::uuid),
    ('c6a0860a-8d40-5aad-af24-7ae361f31598'::uuid),
    ('247b762a-3e41-59ab-b2cd-d08e2ebf45df'::uuid),
    ('098d0ad1-3d9f-5886-81d5-2bdd32fcc38d'::uuid),
    ('a8e4eebe-ccc7-5d19-acda-efc59bbe6238'::uuid),
    ('9eed480a-6f88-5978-9443-5762c9ca6719'::uuid),
    ('b672ad7a-15a1-5918-9f14-371aca5f9e64'::uuid),
    ('bdb872ae-f5fe-56a0-999c-40158911bb08'::uuid),
    ('666354ca-ae90-57e4-8eda-9a7122716dc3'::uuid),
    ('052467d1-d77e-5b9c-a299-e7f4754c05b2'::uuid),
    ('956b2da8-707e-5cf5-9cff-8db600b633ca'::uuid),
    ('ca00133e-9f6a-5e69-954c-537a15dc3c71'::uuid),
    ('7daa4ff6-73e0-59fa-b59d-7a6130a319ab'::uuid),
    ('979e2312-365e-5c84-97ca-a8f3b1b4cca0'::uuid),
    ('6d081117-1bc7-52f9-aca3-82f287c85518'::uuid),
    ('7226e4f3-497c-5dd3-9bce-e48b1345b753'::uuid),
    ('06297af6-0fa4-5364-a765-03d2194aca88'::uuid),
    ('93df5c6e-6a15-5222-93e1-8288950f2226'::uuid),
    ('77b6b7d2-ead4-50dc-8ab0-27e5eef0dbb9'::uuid),
    ('804ef680-fe69-53ec-a9fd-0dc71fa4b729'::uuid),
    ('d547f2ae-c1cb-5dfb-9a33-c78a59103ea1'::uuid),
    ('fdd453c2-71ac-5d2b-83be-020646a0fcc6'::uuid),
    ('8f7a5377-46eb-5e98-88fe-4817394eb504'::uuid),
    ('9971d390-c2b1-5d48-b1c2-77d6ff6ef2a0'::uuid),
    ('05b4941f-fab5-511e-9e34-409217fdaf05'::uuid),
    ('149e159e-05be-5e77-a709-3158762002bf'::uuid),
    ('5ba31f71-3df1-5ea7-8e77-709e2285d0bc'::uuid),
    ('3e3e1b38-39da-582f-9ffa-07148078ddc8'::uuid),
    ('9b3265e8-dc28-582c-ae91-b22012460100'::uuid),
    ('9dda8fdb-6985-590b-8dbe-7a3a9a796ab6'::uuid),
    ('e4144882-dc1e-5e27-95a7-de9476400fef'::uuid),
    ('2b5e3485-e9c6-5a62-bf47-79a50ccf4973'::uuid),
    ('c1d6d489-ea59-594f-9e95-a9f6f77d0c79'::uuid),
    ('4654211b-12fb-5481-a793-3c3c1b3a3158'::uuid),
    ('c433bd98-da4f-5d8e-91a5-a3e4ae8e0230'::uuid),
    ('3d45e5b6-2cf3-545b-b76f-68fc78403f40'::uuid),
    ('0b10308c-3220-58b5-9e87-983de923f8da'::uuid),
    ('d2bb9e46-7863-5be2-9a3c-66e12f6a7338'::uuid),
    ('cb8243ee-8b67-5e18-bf03-cec2eca07043'::uuid),
    ('b9f6864f-a4a5-5a43-8f29-f2dab6139328'::uuid),
    ('ae161335-9129-5617-bace-77e748c5fcdc'::uuid),
    ('53c36973-1925-54b2-99be-aaab5862fa29'::uuid),
    ('e0716beb-e922-5c90-9f8f-cb4d5498890a'::uuid),
    ('853360c9-0dcc-5fcb-9d23-0ca1a4eb3303'::uuid),
    ('edf1eb98-80ed-5d2e-952c-83c3f172d357'::uuid),
    ('8617b5a7-6e6e-50d3-a1ff-374aa8002a86'::uuid),
    ('0fab9caf-66f6-56e3-88f9-66fafa023f42'::uuid),
    ('709e73fb-b169-52cf-b46f-d817c2ea4f71'::uuid),
    ('0f816881-b4f5-5b9e-8062-4543494013fb'::uuid),
    ('a5ba65b2-fab3-51c8-8a66-5fef880e1481'::uuid),
    ('a47461ae-733f-5ab5-b86f-56cb93a3a7c5'::uuid),
    ('6ca807f7-66c5-5120-8962-b45e0501a172'::uuid),
    ('6796f61a-5827-5bcb-8e29-d3f5c5e9c5e5'::uuid),
    ('2d63099e-7267-5f99-af14-effd05fe4736'::uuid),
    ('c21bf8f0-7659-558c-85c0-039bf4f40d0f'::uuid),
    ('b15500d4-cd21-5000-925a-da6e42d8d6a6'::uuid),
    ('51cab2d4-407a-5b53-9ee2-aa4b50bf1054'::uuid),
    ('f08df7d9-3e86-51b3-b2d2-b15b9f3e34ad'::uuid),
    ('f1b5f72a-e068-59e6-96c6-166601f474e2'::uuid),
    ('c6a55114-2cf5-57a0-bbf3-7cc08eea101d'::uuid),
    ('fc0a223d-10ea-5441-9ad1-f00f930b8121'::uuid),
    ('23f08ca9-86bf-5fee-8284-c387773f778f'::uuid),
    ('6454e15e-930e-5915-9874-a817a84948c9'::uuid),
    ('7f9bc0dd-cfc5-50be-80f1-bbc1568eb471'::uuid),
    ('3cf704db-3530-5d4e-ad85-b10bd3e47fd9'::uuid),
    ('5d5fc855-f616-5779-a34b-a496a67601f4'::uuid),
    ('78ee3cb9-eb58-5a68-8964-cc903007f118'::uuid),
    ('18f83ad2-2cb1-5cf4-8e7e-50e4fb1502cf'::uuid),
    ('65d6af57-908a-5b5e-93da-f7ffca6525e8'::uuid),
    ('07d43d2c-4d88-5146-87d9-259307dd6e9f'::uuid),
    ('7462baee-079e-5cf4-b207-464a4633fab3'::uuid),
    ('48d6d767-8b72-53aa-be47-691af8e59fb8'::uuid),
    ('e291f52b-3720-5ea7-aacc-e0d15abde0c7'::uuid),
    ('fde1c29c-330d-55c6-819b-3e65af5fe880'::uuid),
    ('78ec4ad9-bc3b-5b39-96e4-259135d21c9b'::uuid),
    ('570475f4-a142-59c4-a053-acec94b7555d'::uuid),
    ('82ada713-c64c-54ec-a688-4c0e5f6bd8bb'::uuid),
    ('9dd57812-7865-5e9c-a0d5-a67fbf1e9761'::uuid),
    ('29685b49-90f8-5949-8982-14d5a48c614d'::uuid),
    ('4d311cb2-4a83-53d1-a6e3-fd0cffc37985'::uuid),
    ('92030069-1ece-5730-903f-f31166b84c30'::uuid),
    ('efd10051-001e-597e-bd72-1a3782f1fb6b'::uuid),
    ('19dbbf20-bcaf-5fc0-8f8a-e6fb1c3624eb'::uuid),
    ('c6d68697-d163-556d-9766-37c0080bf629'::uuid),
    ('a1c52c4a-d072-5c27-88e3-8154bdd0ef62'::uuid),
    ('a2d1ef47-dacc-5cf8-a23d-f639ba587ff4'::uuid),
    ('7855fb63-55aa-5df4-b570-b70592f89f92'::uuid),
    ('4a65cf37-c1dd-5207-bc5c-afe3e930cf28'::uuid),
    ('fb7e6800-2ecf-5abb-86d9-59f72d5f1ed7'::uuid),
    ('8b9e7eab-1c5d-5923-ad7b-45161d0715d4'::uuid),
    ('ef153d91-2637-5ebb-a802-48c9bbc95913'::uuid),
    ('c83d3587-25fe-57e1-aee5-ca8eb61126a2'::uuid),
    ('612305be-5b82-51c4-86d0-b9925ce45ea4'::uuid),
    ('0e2226a3-f0bf-555c-9bef-5b04c834c7c0'::uuid),
    ('3fef5361-8831-53be-af90-b61370f2b0d0'::uuid),
    ('0fbf4a5e-d544-5aef-88c4-b8f5230f91ec'::uuid),
    ('529c8cdf-1c16-54f0-9ef8-d3a90c6e6dde'::uuid);

-- Recovery guards intentionally prevent ordinary permanent deletes. This
-- deployment rollback bypass is transaction-local and limited to the IDs and
-- owner encoded by the paired forward migration.
drop trigger if exists quiz_questions_recovery_guard on public.quiz_questions;
drop trigger if exists quizzes_recovery_guard on public.quizzes;

delete from public.quizzes quiz
using pg_temp.curriculum_quiz_rollback_ids seed
where quiz.id = seed.quiz_id
  and quiz.teacher_id = '7607da46-85b3-4cdc-851d-23fd4eb5223e'::uuid;

create trigger quizzes_recovery_guard before insert or update or delete on public.quizzes
    for each row execute function public.recovery_guard();
create trigger quiz_questions_recovery_guard before insert or update or delete on public.quiz_questions
    for each row execute function public.recovery_quiz_content_guard();

delete from public.mathverse_schema_migrations
where migration_key = '2026_09_20_curriculum_single_topic_quizzes.sql';

notify pgrst, 'reload schema';
commit;
