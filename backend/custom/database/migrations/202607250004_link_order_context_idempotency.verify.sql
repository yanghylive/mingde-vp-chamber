-- Structural and data postconditions for the G1-01D checkout/idempotency link.

SET NAMES utf8mb4;

SELECT
  'column.ch_order_context.idempotency_record_id' AS `check_name`,
  IF(
    COUNT(*) = 1
    AND MAX(`DATA_TYPE`) = 'bigint'
    AND MAX(`COLUMN_TYPE`) LIKE '%unsigned%'
    AND MAX(`IS_NULLABLE`) = 'YES',
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('matches=', COUNT(*)) AS `details`
FROM information_schema.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'ch_order_context'
  AND `COLUMN_NAME` = 'idempotency_record_id';

SELECT
  'index.ch_order_context.uk_order_context_idempotency' AS `check_name`,
  IF(
    COUNT(*) = 1
    AND MAX(`NON_UNIQUE`) = 0
    AND MAX(`COLUMN_NAME`) = 'idempotency_record_id'
    AND MAX(`INDEX_TYPE`) = 'BTREE',
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('matches=', COUNT(*)) AS `details`
FROM information_schema.`STATISTICS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'ch_order_context'
  AND `INDEX_NAME` = 'uk_order_context_idempotency';

SELECT
  'data.ch_order_context.idempotency_reference' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('invalid_rows=', COUNT(*)) AS `details`
FROM `ch_order_context` AS context_row
LEFT JOIN `ch_idempotency_record` AS idempotency
  ON idempotency.`id` = context_row.`idempotency_record_id`
 AND idempotency.`tenant_id` = context_row.`tenant_id`
WHERE context_row.`idempotency_record_id` IS NOT NULL
  AND (
    idempotency.`id` IS NULL
    OR idempotency.`operation` <> 'createMembershipCheckout'
  );
