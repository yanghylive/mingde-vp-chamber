SET NAMES utf8mb4;

SELECT 'event_refund_closure.attempt_columns' AS check_name,
  IF(COUNT(*)=10,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt'
  AND COLUMN_NAME IN ('channel_id','order_context_id','requester_uid','paid_amount','cumulative_before','cumulative_after','reason_hash','lease_token','lease_expire_time','version');

SELECT 'event_refund_closure.audit_table' AS check_name,
  IF(COUNT(*)=1,'PASS','FAIL') AS check_status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt_audit'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_unicode_ci';

SELECT 'event_refund_closure.audit_columns' AS check_name,
  IF(COUNT(*)=14,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt_audit'
  AND COLUMN_NAME IN ('id','tenant_id','refund_attempt_id','action','from_status','to_status','actor_type','actor_id','provider_status','response_hash','reference_hash','failure_code','occurred_time','add_time');

SELECT 'event_refund_closure.indexes' AS check_name,
  IF(COUNT(*)=20,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND ((TABLE_NAME='ch_refund_attempt' AND INDEX_NAME IN ('idx_refund_context','idx_refund_lease','idx_refund_idempotency'))
    OR (TABLE_NAME='ch_refund_attempt_audit' AND INDEX_NAME IN ('PRIMARY','idx_refund_audit_attempt','idx_refund_audit_actor')));
