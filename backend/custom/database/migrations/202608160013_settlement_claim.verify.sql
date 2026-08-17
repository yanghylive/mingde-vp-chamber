-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_settlement_detail.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_settlement_detail';

SELECT 'ch_settlement_detail.claim_token' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_settlement_detail'
  AND column_name = 'claim_token';

SELECT 'ch_settlement_detail.claim_expire_time' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_settlement_detail'
  AND column_name = 'claim_expire_time';
