-- G1 member, profile, consent, and graduate-verification hardening.
-- MySQL DDL auto-commits. Every ALTER is conditional so an interrupted migration
-- can be rerun safely on MySQL 5.7 and 8.0.

SET NAMES utf8mb4;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'invite_code'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD COLUMN `invite_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT ''租户内会员推荐码'' AFTER `referrer_uid`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'attribution_locked_time'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD COLUMN `attribution_locked_time` int(10) UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''首次渠道和推荐归因锁定时间'' AFTER `invite_code`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'current_verification_id'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD COLUMN `current_verification_id` int(10) UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''当前毕业生认证申请ID投影'' AFTER `verification_status`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'current_membership_term_id'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD COLUMN `current_membership_term_id` bigint(20) UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''当前最高有效会籍期限ID投影'' AFTER `tier_expire_time`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'membership_version'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD COLUMN `membership_version` int(10) UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''会籍投影重算版本'' AFTER `current_membership_term_id`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'uk_member_invite_code'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD UNIQUE KEY `uk_member_invite_code` (`tenant_id`,`invite_code`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'idx_member_verification_projection'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD KEY `idx_member_verification_projection` (`tenant_id`,`current_verification_id`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'idx_member_term_projection'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD KEY `idx_member_term_projection` (`tenant_id`,`current_membership_term_id`,`tier`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'idx_member_attribution'),
  'SELECT 1',
  'ALTER TABLE `ch_tenant_member` ADD KEY `idx_member_attribution` (`tenant_id`,`first_channel_id`,`attribution_locked_time`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'avatar_object_key'),
  'SELECT 1',
  'ALTER TABLE `ch_member_profile` ADD COLUMN `avatar_object_key` varchar(255) NOT NULL DEFAULT '''' COMMENT ''头像对象键，不保存公开URL'' AFTER `real_name`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'main_business'),
  'SELECT 1',
  'ALTER TABLE `ch_member_profile` ADD COLUMN `main_business` varchar(500) NOT NULL DEFAULT '''' COMMENT ''主营业务'' AFTER `job_title`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'resources_json'),
  'SELECT 1',
  'ALTER TABLE `ch_member_profile` ADD COLUMN `resources_json` text COMMENT ''可提供资源JSON数组'' AFTER `bio`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'needs_json'),
  'SELECT 1',
  'ALTER TABLE `ch_member_profile` ADD COLUMN `needs_json` text COMMENT ''资源需求JSON数组'' AFTER `resources_json`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'interests_json'),
  'SELECT 1',
  'ALTER TABLE `ch_member_profile` ADD COLUMN `interests_json` text COMMENT ''兴趣标签JSON数组'' AFTER `needs_json`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `COLUMN_NAME` = 'current_slot'),
  'SELECT 1',
  'ALTER TABLE `ch_graduate_verification` ADD COLUMN `current_slot` tinyint(1) UNSIGNED NULL COMMENT ''当前申请占位：当前状态为1，历史为NULL'' AFTER `status`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `COLUMN_NAME` = 'previous_application_id'),
  'SELECT 1',
  'ALTER TABLE `ch_graduate_verification` ADD COLUMN `previous_application_id` int(10) UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''退回或拒绝后新申请引用的上一申请ID'' AFTER `apply_no`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `COLUMN_NAME` = 'graduation_time'),
  'SELECT 1',
  'ALTER TABLE `ch_graduate_verification` ADD COLUMN `graduation_time` int(10) UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''毕业时间，Unix秒；仅年份时可为0'' AFTER `graduation_year`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_duplicate_current = (
  SELECT COUNT(*)
  FROM (
    SELECT `tenant_id`, `member_id`
    FROM `ch_graduate_verification`
    WHERE `status` IN (0, 1, 2)
    GROUP BY `tenant_id`, `member_id`
    HAVING COUNT(*) > 1
  ) AS duplicate_current
);
SET @ch_ddl = IF(
  @ch_duplicate_current = 0,
  'SELECT 1',
  'SELECT * FROM `__ch_migration_blocked_duplicate_current_verification`'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

UPDATE `ch_graduate_verification`
SET `current_slot` = IF(`status` IN (0, 1, 2), 1, NULL);

UPDATE `ch_tenant_member`
SET `current_verification_id` = 0,
    `verification_status` = IF(`verification_status` IN (1, 2), 0, `verification_status`);

UPDATE `ch_tenant_member` AS member
INNER JOIN `ch_graduate_verification` AS verification
  ON verification.`tenant_id` = member.`tenant_id`
 AND verification.`member_id` = member.`id`
 AND verification.`uid` = member.`uid`
 AND verification.`current_slot` = 1
SET member.`current_verification_id` = verification.`id`,
    member.`verification_status` = verification.`status`;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `INDEX_NAME` = 'uk_verification_current_slot'),
  'SELECT 1',
  'ALTER TABLE `ch_graduate_verification` ADD UNIQUE KEY `uk_verification_current_slot` (`tenant_id`,`member_id`,`current_slot`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `INDEX_NAME` = 'idx_verification_previous'),
  'SELECT 1',
  'ALTER TABLE `ch_graduate_verification` ADD KEY `idx_verification_previous` (`tenant_id`,`previous_application_id`) USING BTREE'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

CREATE TABLE IF NOT EXISTS `ch_member_consent` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '会员协议接受事件ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '接受协议时渠道ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `consent_event_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '确定性协议事件ID',
  `document_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '协议稳定编码',
  `document_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '协议版本',
  `content_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '用户接受内容SHA-256',
  `decision` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'accepted' COMMENT 'accepted/withdrawn',
  `source` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'chamber_api' COMMENT '接受来源',
  `ip_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '加盐IP摘要，不保存原IP',
  `user_agent_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'User-Agent摘要，不保存原文',
  `correlation_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '请求关联ID',
  `occurred_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户决定时间',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '写入时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_member_consent_event` (`tenant_id`,`consent_event_id`) USING BTREE,
  KEY `idx_member_consent_member` (`tenant_id`,`member_id`,`document_code`,`occurred_time`) USING BTREE,
  KEY `idx_member_consent_uid` (`tenant_id`,`uid`,`occurred_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='追加式会员协议接受与撤回事件';
