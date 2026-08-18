SET NAMES utf8mb4;

SELECT 'ch_expert_ai.is_listed' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_expert_ai'
  AND column_name = 'is_listed';

SELECT 'ch_expert_ai.is_listed.default_zero' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('defaults=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_expert_ai'
  AND column_name = 'is_listed'
  AND column_default = '0';
