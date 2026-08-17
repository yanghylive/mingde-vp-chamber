-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_coaching_daily.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coaching_daily';

SELECT 'ch_coaching_daily.challenge_achieved' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coaching_daily'
  AND column_name = 'challenge_achieved';
