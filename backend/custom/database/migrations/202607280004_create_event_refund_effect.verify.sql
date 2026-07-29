SELECT 'event_refund_effect.table' AS check_name,
  IF(COUNT(*)=1,'PASS','FAIL') AS check_status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_event_registration_effect';

SELECT 'event_refund_effect.columns' AS check_name,
  IF(COUNT(*)=14,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_event_registration_effect'
  AND COLUMN_NAME IN (
    'id','tenant_id','registration_id','order_context_id','effect_key','effect_hash','event_id',
    'completion_id','effect_type','refund_delta','cumulative_refunded_amount','points_delta','seat_delta','add_time'
  );

SELECT 'event_refund_effect.indexes' AS check_name,
  IF(COUNT(*)=9,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_event_registration_effect'
  AND INDEX_NAME IN (
    'PRIMARY','uk_event_registration_effect','idx_event_registration_effect_registration',
    'idx_event_registration_effect_context'
  );

SELECT 'event_refund_effect.boundaries' AS check_name,
  IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='ch_event_registration_effect' AND COLUMN_NAME='refund_delta')='decimal'
    AND (SELECT NUMERIC_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='ch_event_registration_effect' AND COLUMN_NAME='refund_delta')=16
    AND (SELECT NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='ch_event_registration_effect' AND COLUMN_NAME='refund_delta')=2
    AND (SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='ch_event_registration_effect' AND COLUMN_NAME='effect_key')='ascii_bin',
    'PASS','FAIL'
  ) AS check_status;
