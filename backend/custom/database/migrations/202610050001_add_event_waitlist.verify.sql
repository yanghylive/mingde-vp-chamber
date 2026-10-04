-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_event_ticket.waitlist_enabled' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_ticket'
  AND column_name = 'waitlist_enabled';

SELECT 'ch_event_waitlist.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_waitlist';

SELECT 'ch_event_waitlist.id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_waitlist'
  AND column_name = 'id';

SELECT 'ch_event_waitlist.tenant_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_waitlist'
  AND column_name = 'tenant_id';

SELECT 'ch_event_waitlist.status' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_waitlist'
  AND column_name = 'status';

SELECT 'ch_event_waitlist.uk_waitlist_member' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('indexes=', COUNT(DISTINCT index_name)) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_waitlist'
  AND index_name = 'uk_waitlist_member';

SELECT 'ch_event_waitlist.idx_waitlist_promote' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('indexes=', COUNT(DISTINCT index_name)) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_waitlist'
  AND index_name = 'idx_waitlist_promote';
