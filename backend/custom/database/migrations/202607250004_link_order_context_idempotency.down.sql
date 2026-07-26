-- Destructive local rollback for the G1-01D checkout/idempotency link.

SET NAMES utf8mb4;

SET @ch_ddl = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.`STATISTICS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'ch_order_context'
      AND `INDEX_NAME` = 'uk_order_context_idempotency'
  ),
  'ALTER TABLE `ch_order_context` DROP INDEX `uk_order_context_idempotency`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (
    SELECT 1
    FROM information_schema.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'ch_order_context'
      AND `COLUMN_NAME` = 'idempotency_record_id'
  ),
  'ALTER TABLE `ch_order_context` DROP COLUMN `idempotency_record_id`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = NULL;
