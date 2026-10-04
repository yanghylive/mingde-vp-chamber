-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_settlement_rule.absent' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_settlement_rule';

SELECT 'ch_settlement.absent' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_settlement';

SELECT 'ch_settlement_detail.absent' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_settlement_detail';

SELECT 'ch_payout_record.absent' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_payout_record';

SELECT 'ch_settlement_balance.absent' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_settlement_balance';
