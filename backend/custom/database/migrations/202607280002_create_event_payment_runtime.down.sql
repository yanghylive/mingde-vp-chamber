SET NAMES utf8mb4;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_event_registration' AND INDEX_NAME='idx_registration_expiry'),
  'ALTER TABLE `ch_event_registration` DROP INDEX `idx_registration_expiry`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

DROP TABLE IF EXISTS `ch_point_hold`;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_point_account' AND COLUMN_NAME='frozen_balance'),
  'ALTER TABLE `ch_point_account` DROP COLUMN `frozen_balance`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = NULL;
