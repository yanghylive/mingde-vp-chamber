-- Event cash/mixed-payment reservation runtime.

SET NAMES utf8mb4;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_point_account' AND COLUMN_NAME='frozen_balance'),
  'SELECT 1',
  'ALTER TABLE `ch_point_account` ADD COLUMN `frozen_balance` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''支付中冻结积分'' AFTER `balance`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

CREATE TABLE IF NOT EXISTS `ch_point_hold` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `account_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `registration_id` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `amount` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1冻结，2已扣减，3已释放',
  `idempotency_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expire_time` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `version` int(10) UNSIGNED NOT NULL DEFAULT '1',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_point_hold_registration` (`tenant_id`,`registration_id`) USING BTREE,
  UNIQUE KEY `uk_point_hold_key` (`tenant_id`,`idempotency_key`) USING BTREE,
  KEY `idx_point_hold_expiry` (`status`,`expire_time`,`id`) USING BTREE,
  KEY `idx_point_hold_account` (`tenant_id`,`account_id`,`status`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='现金活动报名积分冻结事实';

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_event_registration' AND INDEX_NAME='idx_registration_expiry'),
  'SELECT 1',
  'ALTER TABLE `ch_event_registration` ADD KEY `idx_registration_expiry` (`status`,`reserve_expire_time`,`id`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = NULL;
