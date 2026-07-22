-- Mingde VP Chamber local/CI baseline seed
-- Scope: generic tenants, default channels and persona-role definitions only.
-- Never add real users, member profiles, events, orders or production brand data here.
-- Existing rows are never updated or reactivated; reruns only insert missing keys.

SET NAMES utf8mb4;
SET @ch_seed_time = UNIX_TIMESTAMP();

INSERT INTO `ch_tenant` (
  `name`, `slug`, `display_name`, `status`, `add_time`, `update_time`, `is_del`
)
SELECT
  'Local Primary Tenant', 'local-primary', 'Local Primary Tenant', 1,
  @ch_seed_time, @ch_seed_time, 0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `ch_tenant` WHERE `slug` = 'local-primary'
);

INSERT INTO `ch_tenant` (
  `name`, `slug`, `display_name`, `status`, `add_time`, `update_time`, `is_del`
)
SELECT
  'Local Secondary Tenant', 'local-secondary', 'Local Secondary Tenant', 1,
  @ch_seed_time, @ch_seed_time, 0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `ch_tenant` WHERE `slug` = 'local-secondary'
);

INSERT INTO `ch_channel` (
  `tenant_id`, `name`, `code`, `entry_key`, `status`, `sort`,
  `add_time`, `update_time`, `is_del`
)
SELECT
  tenant_seed.`id`,
  'Default Channel',
  'default',
  MD5(CONCAT('mingde-vp-chamber:local-ci:', tenant_seed.`slug`, ':default')),
  1,
  10,
  @ch_seed_time,
  @ch_seed_time,
  0
FROM `ch_tenant` AS tenant_seed
LEFT JOIN `ch_channel` AS existing_channel
  ON existing_channel.`tenant_id` = tenant_seed.`id`
 AND existing_channel.`code` = 'default'
WHERE tenant_seed.`slug` IN ('local-primary', 'local-secondary')
  AND tenant_seed.`is_del` = 0
  AND existing_channel.`id` IS NULL;

INSERT INTO `ch_persona_role` (
  `tenant_id`, `code`, `name`, `description`, `application_required`,
  `materials_schema_json`, `profile_template`, `status`, `sort`,
  `add_time`, `update_time`, `is_del`
)
SELECT
  tenant_seed.`id`,
  role_seed.`code`,
  role_seed.`name`,
  role_seed.`description`,
  role_seed.`application_required`,
  NULL,
  role_seed.`profile_template`,
  1,
  role_seed.`sort`,
  @ch_seed_time,
  @ch_seed_time,
  0
FROM `ch_tenant` AS tenant_seed
CROSS JOIN (
  SELECT
    'member' AS `code`,
    '普通会员' AS `name`,
    '通用普通会员角色' AS `description`,
    0 AS `application_required`,
    'member' AS `profile_template`,
    10 AS `sort`
  UNION ALL
  SELECT 'mentor', '导师', '通用导师展示角色', 1, 'mentor', 20
  UNION ALL
  SELECT 'coach', '教练', '通用教练展示角色', 1, 'coach', 30
  UNION ALL
  SELECT 'industry_leader', '行业领袖', '通用行业领袖展示角色', 1, 'industry_leader', 40
) AS role_seed
LEFT JOIN `ch_persona_role` AS existing_role
  ON existing_role.`tenant_id` = tenant_seed.`id`
 AND existing_role.`code` = role_seed.`code`
WHERE tenant_seed.`slug` IN ('local-primary', 'local-secondary')
  AND tenant_seed.`is_del` = 0
  AND existing_role.`id` IS NULL;

SET @ch_seed_time = NULL;
