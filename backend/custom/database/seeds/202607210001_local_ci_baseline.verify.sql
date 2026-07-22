-- Verification for 202607210001_local_ci_baseline.sql
-- A healthy result contains three rows and every check_status is PASS.

SET NAMES utf8mb4;

SELECT
  'active_seed_tenants' AS `check_name`,
  2 AS `expected_count`,
  COUNT(*) AS `actual_count`,
  IF(COUNT(*) = 2, 'PASS', 'FAIL') AS `check_status`
FROM `ch_tenant`
WHERE `slug` IN ('local-primary', 'local-secondary')
  AND `status` = 1
  AND `is_del` = 0

UNION ALL

SELECT
  'active_default_channels' AS `check_name`,
  2 AS `expected_count`,
  COUNT(*) AS `actual_count`,
  IF(COUNT(*) = 2, 'PASS', 'FAIL') AS `check_status`
FROM `ch_channel` AS channel_check
JOIN `ch_tenant` AS tenant_check
  ON tenant_check.`id` = channel_check.`tenant_id`
WHERE tenant_check.`slug` IN ('local-primary', 'local-secondary')
  AND tenant_check.`status` = 1
  AND tenant_check.`is_del` = 0
  AND channel_check.`code` = 'default'
  AND channel_check.`status` = 1
  AND channel_check.`is_del` = 0

UNION ALL

SELECT
  'active_persona_roles' AS `check_name`,
  8 AS `expected_count`,
  COUNT(*) AS `actual_count`,
  IF(COUNT(*) = 8, 'PASS', 'FAIL') AS `check_status`
FROM `ch_persona_role` AS role_check
JOIN `ch_tenant` AS tenant_check
  ON tenant_check.`id` = role_check.`tenant_id`
WHERE tenant_check.`slug` IN ('local-primary', 'local-secondary')
  AND tenant_check.`status` = 1
  AND tenant_check.`is_del` = 0
  AND role_check.`code` IN ('member', 'mentor', 'coach', 'industry_leader')
  AND role_check.`status` = 1
  AND role_check.`is_del` = 0;

SELECT
  tenant_check.`slug`,
  channel_check.`code` AS `channel_code`,
  role_check.`code` AS `role_code`,
  role_check.`application_required`,
  role_check.`profile_template`
FROM `ch_tenant` AS tenant_check
JOIN `ch_channel` AS channel_check
  ON channel_check.`tenant_id` = tenant_check.`id`
 AND channel_check.`code` = 'default'
 AND channel_check.`is_del` = 0
JOIN `ch_persona_role` AS role_check
  ON role_check.`tenant_id` = tenant_check.`id`
 AND role_check.`code` IN ('member', 'mentor', 'coach', 'industry_leader')
 AND role_check.`is_del` = 0
WHERE tenant_check.`slug` IN ('local-primary', 'local-secondary')
  AND tenant_check.`is_del` = 0
ORDER BY tenant_check.`slug`, role_check.`sort`, role_check.`code`;
