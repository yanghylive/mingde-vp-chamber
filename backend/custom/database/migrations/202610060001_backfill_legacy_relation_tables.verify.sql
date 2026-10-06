-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_member_friend.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_member_friend';

SELECT 'ch_member_friend.uk_friend_pair' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('indexes=', COUNT(DISTINCT index_name)) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_member_friend'
  AND index_name = 'uk_friend_pair';

SELECT 'ch_member_friend.status' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_member_friend'
  AND column_name = 'status';

SELECT 'ch_membership_order.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_membership_order';

SELECT 'ch_membership_order.idem' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_membership_order'
  AND column_name = 'idempotency_key';

SELECT 'ch_membership_order.idx_member' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('indexes=', COUNT(DISTINCT index_name)) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_membership_order'
  AND index_name = 'idx_member';

SELECT 'ch_distribution_record.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_distribution_record';

SELECT 'ch_distribution_record.uk_invited' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('indexes=', COUNT(DISTINCT index_name)) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_distribution_record'
  AND index_name = 'uk_invited';

SELECT 'ch_distribution_record.code' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_distribution_record'
  AND column_name = 'code';
