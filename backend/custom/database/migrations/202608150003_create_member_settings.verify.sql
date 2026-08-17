-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_member_settings.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_member_settings';

SELECT 'ch_member_settings.tenant_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_member_settings'
  AND column_name = 'tenant_id';

SELECT 'ch_member_settings.member_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_member_settings'
  AND column_name = 'member_id';

SELECT 'ch_member_settings.uk_tenant_member' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('index=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_member_settings'
  AND index_name = 'uk_tenant_member';
