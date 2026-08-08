-- Local/CI rollback only. Production refund audit records must be retained.
SET NAMES utf8mb4;
DROP TABLE IF EXISTS `ch_refund_attempt_audit`;

SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND INDEX_NAME='idx_refund_context'), 'ALTER TABLE `ch_refund_attempt` DROP INDEX `idx_refund_context`', 'SELECT 1');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND INDEX_NAME='idx_refund_lease'), 'ALTER TABLE `ch_refund_attempt` DROP INDEX `idx_refund_lease`', 'SELECT 1');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND INDEX_NAME='idx_refund_idempotency'), 'ALTER TABLE `ch_refund_attempt` DROP INDEX `idx_refund_idempotency`', 'SELECT 1');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_columns = 'channel_id,order_context_id,requester_uid,paid_amount,cumulative_before,cumulative_after,reason_hash,lease_token,lease_expire_time,version';
SET @ch_i = 1;
refund_drop_loop: LOOP
  SET @ch_column = SUBSTRING_INDEX(SUBSTRING_INDEX(@ch_columns, ',', @ch_i), ',', -1);
  SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME=@ch_column), CONCAT('ALTER TABLE `ch_refund_attempt` DROP COLUMN `', @ch_column, '`'), 'SELECT 1');
  PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
  SET @ch_i = @ch_i + 1;
  IF @ch_i > 10 THEN LEAVE refund_drop_loop; END IF;
END LOOP;

SET @ch_ddl = NULL;
