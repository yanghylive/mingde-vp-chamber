-- Durable event-refund ownership, amount snapshots, leases, and append-only audit.
SET NAMES utf8mb4;

SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='channel_id'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `channel_id` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `tenant_id`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='order_context_id'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `order_context_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `source_id`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='requester_uid'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `requester_uid` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `order_context_id`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='paid_amount'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `paid_amount` decimal(16,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER `amount`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='cumulative_before'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `cumulative_before` decimal(16,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER `paid_amount`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='cumulative_after'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `cumulative_after` decimal(16,2) UNSIGNED NOT NULL DEFAULT 0.00 AFTER `cumulative_before`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='reason_hash'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `reason_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `request_hash`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='lease_token'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `lease_token` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `last_query_time`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='lease_expire_time'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `lease_expire_time` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `lease_token`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND COLUMN_NAME='version'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD COLUMN `version` int(10) UNSIGNED NOT NULL DEFAULT 1 AFTER `lease_expire_time`');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND INDEX_NAME='idx_refund_context'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD KEY `idx_refund_context` (`tenant_id`,`order_context_id`,`status`,`id`) USING BTREE');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND INDEX_NAME='idx_refund_lease'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD KEY `idx_refund_lease` (`status`,`next_query_time`,`lease_expire_time`,`id`) USING BTREE');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_refund_attempt' AND INDEX_NAME='idx_refund_idempotency'), 'SELECT 1', 'ALTER TABLE `ch_refund_attempt` ADD KEY `idx_refund_idempotency` (`tenant_id`,`idempotency_record_id`,`id`) USING BTREE');
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

CREATE TABLE IF NOT EXISTS `ch_refund_attempt_audit` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `refund_attempt_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `from_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `to_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `actor_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `provider_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `response_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reference_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `failure_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `occurred_time` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_refund_audit_attempt` (`tenant_id`,`refund_attempt_id`,`id`) USING BTREE,
  KEY `idx_refund_audit_actor` (`tenant_id`,`actor_type`,`actor_id`,`occurred_time`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='退款状态变化追加审计';

SET @ch_ddl = NULL;
