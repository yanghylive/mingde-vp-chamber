-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_coaching_config.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coaching_config';

SELECT 'ch_coaching_config.member_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coaching_config'
  AND column_name = 'member_id';

SELECT 'ch_coaching_config.uid' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coaching_config'
  AND column_name = 'uid';

SELECT 'ch_coaching_config.uk_coaching_config_owner' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('index=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coaching_config'
  AND index_name = 'uk_coaching_config_owner';
