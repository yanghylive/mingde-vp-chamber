-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_chamber_ai_usage.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_chamber_ai_usage';

SELECT 'ch_chamber_ai_usage.tenant_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_chamber_ai_usage'
  AND column_name = 'tenant_id';

SELECT 'ch_chamber_ai_usage.member_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_chamber_ai_usage'
  AND column_name = 'member_id';
