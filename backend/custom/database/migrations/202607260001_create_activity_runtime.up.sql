-- G2 activity runtime facts: payment context linkage, rotating check-in
-- tokens, and idempotent attendance rewards.

SET NAMES utf8mb4;

ALTER TABLE `ch_event_registration`
  ADD COLUMN `order_context_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_order_context.id，现金报名使用' AFTER `order_no`,
  ADD KEY `idx_registration_context` (`tenant_id`,`order_context_id`,`status`);

ALTER TABLE `ch_event`
  ADD COLUMN `tags_json` text COMMENT '受控活动标签JSON' AFTER `summary`,
  ADD COLUMN `speakers_json` text COMMENT '嘉宾快照JSON' AFTER `tags_json`,
  ADD COLUMN `refund_policy_json` text COMMENT '退款规则JSON' AFTER `eligibility_json`;

ALTER TABLE `ch_event_ticket`
  ADD COLUMN `refund_policy_json` text COMMENT '票种退款规则JSON' AFTER `eligibility_json`;

CREATE TABLE IF NOT EXISTS `ch_event_checkin_token` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '动态签到码ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `event_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_event.id',
  `token_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '签到令牌SHA-256摘要',
  `issued_by_admin_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '签发管理员ID',
  `valid_from` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '生效时间',
  `expires_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '过期时间',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1有效，2撤销',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_event_checkin_token` (`tenant_id`,`event_id`,`token_digest`) USING BTREE,
  KEY `idx_event_checkin_token_active` (`tenant_id`,`event_id`,`status`,`expires_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动动态签到码';

CREATE TABLE IF NOT EXISTS `ch_event_reward` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '活动奖励ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `event_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_event.id',
  `registration_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_event_registration.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员eb_user.uid',
  `reward_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '奖励类型',
  `points` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '积分奖励',
  `contribution` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '贡献值奖励',
  `idempotency_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '奖励幂等键',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1已发放，2已冲正',
  `reversal_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '冲正奖励ID',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '发生时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_event_reward_key` (`tenant_id`,`idempotency_key`) USING BTREE,
  KEY `idx_event_reward_registration` (`tenant_id`,`registration_id`,`status`),
  KEY `idx_event_reward_member` (`tenant_id`,`uid`,`add_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动积分与贡献奖励账本';

CREATE TABLE IF NOT EXISTS `ch_point_account` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '租户积分账户ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员eb_user.uid',
  `balance` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '当前可用积分',
  `version` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '乐观锁版本',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_point_account_member` (`tenant_id`,`member_id`) USING BTREE,
  UNIQUE KEY `uk_point_account_uid` (`tenant_id`,`uid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='租户积分账户';

CREATE TABLE IF NOT EXISTS `ch_point_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '积分账本ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `account_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_point_account.id',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员ID',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员uid',
  `delta` int(11) NOT NULL DEFAULT '0' COMMENT '积分变化，可为负数',
  `balance_after` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '变更后余额',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '来源类型',
  `source_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '来源业务ID',
  `idempotency_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '账本幂等键',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1生效，2冲正',
  `reversal_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '冲正账本ID',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '发生时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_point_ledger_key` (`tenant_id`,`idempotency_key`) USING BTREE,
  KEY `idx_point_ledger_account` (`tenant_id`,`account_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='租户积分追加账本';

CREATE TABLE IF NOT EXISTS `ch_contribution_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '贡献值账本ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员ID',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员uid',
  `delta` int(11) NOT NULL DEFAULT '0' COMMENT '贡献值变化，可为负数',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '来源类型',
  `source_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '来源业务ID',
  `idempotency_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '账本幂等键',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1生效，2冲正',
  `reversal_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '冲正账本ID',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '发生时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_contribution_ledger_key` (`tenant_id`,`idempotency_key`) USING BTREE,
  KEY `idx_contribution_ledger_member` (`tenant_id`,`member_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='租户贡献值追加账本';
