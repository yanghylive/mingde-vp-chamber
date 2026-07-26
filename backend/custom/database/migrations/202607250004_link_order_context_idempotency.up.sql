-- Link a reserved Chamber order context to the idempotency lease that created it.
-- This makes the CRMEB-order/context commit gap observable and repairable.

SET NAMES utf8mb4;

SET @ch_ddl = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'ch_order_context'
      AND `COLUMN_NAME` = 'idempotency_record_id'
  ),
  'SELECT 1',
  'ALTER TABLE `ch_order_context` ADD COLUMN `idempotency_record_id` bigint(20) UNSIGNED NULL COMMENT ''创建订单上下文的幂等记录ID'' AFTER `context_no`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.`STATISTICS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'ch_order_context'
      AND `INDEX_NAME` = 'uk_order_context_idempotency'
  ),
  'SELECT 1',
  'ALTER TABLE `ch_order_context` ADD UNIQUE KEY `uk_order_context_idempotency` (`idempotency_record_id`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = NULL;
