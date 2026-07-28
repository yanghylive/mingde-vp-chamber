-- Structural checks for G2 activity runtime facts.

SET NAMES utf8mb4;

SELECT 'activity.registration.order_context_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_registration'
  AND column_name = 'order_context_id';

SELECT 'activity.registration.context_index' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,order_context_id,status',
          'PASS', 'FAIL') AS result,
       CONCAT('columns=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_registration'
  AND index_name = 'idx_registration_context';

SELECT 'activity.event.extra_columns' AS check_name,
       IF(COUNT(*) = 3, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event'
  AND column_name IN ('tags_json','speakers_json','refund_policy_json');

SELECT 'activity.ticket.refund_policy' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_ticket'
  AND column_name = 'refund_policy_json';

SELECT 'activity.checkin_token.table' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_checkin_token';

SELECT 'activity.checkin_token.columns' AS check_name,
       IF(COUNT(*) >= 8, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_checkin_token'
  AND column_name IN ('tenant_id','event_id','token_digest','valid_from','expires_time','status','issued_by_admin_id','add_time');

SELECT 'activity.checkin_token.unique' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,event_id,token_digest',
          'PASS', 'FAIL') AS result,
       CONCAT('columns=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_checkin_token'
  AND index_name = 'uk_event_checkin_token';

SELECT 'activity.reward.table' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_reward';

SELECT 'activity.reward.columns' AS check_name,
       IF(COUNT(*) >= 9, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_reward'
  AND column_name IN ('tenant_id','event_id','registration_id','uid','reward_type','points','contribution','idempotency_key','status');

SELECT 'activity.reward.unique' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,idempotency_key',
          'PASS', 'FAIL') AS result,
       CONCAT('columns=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event_reward'
  AND index_name = 'uk_event_reward_key';

SELECT 'activity.point_account.table' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_point_account';

SELECT 'activity.point_account.columns' AS check_name,
       IF(COUNT(*) >= 6, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_point_account'
  AND column_name IN ('tenant_id','member_id','uid','balance','version','update_time');

SELECT 'activity.point_account.unique_member' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,member_id',
          'PASS', 'FAIL') AS result,
       CONCAT('columns=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_point_account'
  AND index_name = 'uk_point_account_member';

SELECT 'activity.point_ledger.table' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_point_ledger';

SELECT 'activity.point_ledger.columns' AS check_name,
       IF(COUNT(*) >= 9, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_point_ledger'
  AND column_name IN ('tenant_id','account_id','member_id','uid','delta','balance_after','source_type','source_id','idempotency_key');

SELECT 'activity.point_ledger.unique' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,idempotency_key',
          'PASS', 'FAIL') AS result,
       CONCAT('columns=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_point_ledger'
  AND index_name = 'uk_point_ledger_key';

SELECT 'activity.contribution_ledger.table' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_contribution_ledger';

SELECT 'activity.contribution_ledger.columns' AS check_name,
       IF(COUNT(*) >= 7, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_contribution_ledger'
  AND column_name IN ('tenant_id','member_id','uid','delta','source_type','source_id','idempotency_key');

SELECT 'activity.contribution_ledger.unique' AS check_name,
       IF(COUNT(DISTINCT index_name) = 1
          AND GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'tenant_id,idempotency_key',
          'PASS', 'FAIL') AS result,
       CONCAT('columns=', COALESCE(GROUP_CONCAT(column_name ORDER BY seq_in_index), '')) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_contribution_ledger'
  AND index_name = 'uk_contribution_ledger_key';
