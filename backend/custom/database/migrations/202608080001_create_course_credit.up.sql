-- 格林迈「课程·课时」域：教练、课时包（SKU）、家庭、课时账户、课时授予（含有效期）、
-- 课时账本（追加式）、可约课次、约课记录、课程签到码。
-- 设计依据：格林迈体育课程体系与定价方案.docx
--   2.1 一对一私教（教练等级 初580/中680/高880 元·时；课时包 10/20/40 节，6/12 月有效期）
--   2.2 小班课（2-4人，10/20/40 节，2h/次，每周1次，6/12 月）
--   2.3 家庭卡（30/60/100 节，1h/次，6/12/18 月；单人1.5/多人各1 课时；家庭共享；不退不换，到期作废）
--   9洞下场：1500元或划扣3课时
-- 课时账本沿用 ch_point_account / ch_point_ledger 的「账户 + 追加账本 + 乐观锁 + 幂等键」范式；
-- 课时授予（ch_credit_grant）单独建表以支持「按包有效期 FIFO 划扣 + 到期未用自动作废」。

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_coach` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '教练ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `name` varchar(40) NOT NULL COMMENT '教练姓名',
  `tier` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '教练等级 1初级 2中级 3高级',
  `title` varchar(60) NOT NULL DEFAULT '' COMMENT '头衔/简介标签',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像URL',
  `bio` text COMMENT '教练简介',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1在岗 2停用',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_coach_tenant` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='教练档案';

CREATE TABLE IF NOT EXISTS `ch_course_package` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '课时包ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道ID',
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '课时包编码',
  `version` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '版本号',
  `name` varchar(80) NOT NULL COMMENT '课时包名称',
  `course_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '课程类型 private私教 group小班 family家庭 oncourse下场',
  `total_hours` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '总课时',
  `validity_months` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '有效期（月），0表示无期限',
  `session_duration_hours` decimal(4,2) NOT NULL DEFAULT '1.00' COMMENT '单次时长（小时）',
  `frequency` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '上课频率 weekly/every2weeks，空表示不限',
  `min_participants` tinyint(3) UNSIGNED NOT NULL DEFAULT '1' COMMENT '最少参与人数',
  `max_participants` tinyint(3) UNSIGNED NOT NULL DEFAULT '1' COMMENT '最多参与人数',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '总价（小班/家庭/下场为固定总价；私教为初级基准总价）',
  `currency` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CNY' COMMENT '币种',
  `product_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB商品ID（现金购买时使用，0表示纯课时划拨）',
  `product_attr_unique` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'CRMEB商品规格',
  `benefits_json` text COMMENT '权益说明JSON数组',
  `refund_policy_json` text COMMENT '退款规则JSON（课时包默认不退不换）',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '0停用 1在售 2隐藏',
  `effective_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '上架时间',
  `end_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '下架时间，0表示长期',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_package_code` (`tenant_id`,`code`,`version`) USING BTREE,
  KEY `idx_package_list` (`tenant_id`,`course_type`,`status`,`effective_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课时包SKU';

CREATE TABLE IF NOT EXISTS `ch_course_package_tier` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '私教分级价ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `package_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_course_package.id',
  `coach_tier` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '教练等级 1初级 2中级 3高级',
  `price_per_hour` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '该等级单课时价',
  `total_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '总价=单课时价×总课时',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_pkg_tier` (`tenant_id`,`package_id`,`coach_tier`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='私教课时包分级定价（初级基准 + 中/高级上浮）';

CREATE TABLE IF NOT EXISTS `ch_family` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '家庭ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `name` varchar(60) NOT NULL COMMENT '家庭名称',
  `owner_member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建家长 ch_tenant_member.id',
  `owner_uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建家长 uid',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1有效 2解散',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_family_owner` (`tenant_id`,`owner_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='家庭账户（家庭卡共享课时）';

CREATE TABLE IF NOT EXISTS `ch_family_member` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '家庭成员ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `family_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_family.id',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '成员 ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '成员 uid',
  `relation` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'child' COMMENT '关系 owner家长 child子女',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1有效 2移除',
  `joined_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '加入时间',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_family_member` (`tenant_id`,`family_id`,`member_id`) USING BTREE,
  KEY `idx_family_member_uid` (`tenant_id`,`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='家庭成员（共享家庭课时）';

CREATE TABLE IF NOT EXISTS `ch_credit_account` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '课时账户ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `owner_type` varchar(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '账户归属 member个人 family家庭',
  `owner_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'member_id 或 family_id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '主会员 uid（个人账户必填）',
  `balance_hours` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '当前可用课时',
  `frozen_hours` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '冻结课时（预约占用）',
  `version` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '乐观锁版本',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_credit_account_owner` (`tenant_id`,`owner_type`,`owner_id`) USING BTREE,
  KEY `idx_credit_account_uid` (`tenant_id`,`uid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课时账户（个人/家庭）';

CREATE TABLE IF NOT EXISTS `ch_credit_grant` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '课时授予ID（一次购包=一条授予）',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `account_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_credit_account.id',
  `package_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '来源课时包',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '归属会员',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '归属 uid',
  `hours_total` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '授予总课时',
  `hours_remaining` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '剩余课时',
  `expire_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '到期时间，0表示不过期',
  `source_order_context_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '来源购买订单上下文',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1有效 2已过期 3已耗尽 4作废',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_grant_account` (`tenant_id`,`account_id`,`status`,`expire_time`) USING BTREE,
  KEY `idx_grant_expire` (`tenant_id`,`status`,`expire_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课时授予（按包有效期，FIFO划扣，到期作废）';

CREATE TABLE IF NOT EXISTS `ch_credit_ledger` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '课时账本ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `account_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_credit_account.id',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员ID',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员 uid',
  `delta_hours` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '课时变化，可负（划扣/作废）',
  `balance_after` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '变更后余额',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '来源类型 package_purchase/session_consume/expire_forfeit/adjust/reversal',
  `source_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '来源业务ID',
  `idempotency_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '账本幂等键',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1生效 2冲正',
  `reversal_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '冲正账本ID',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '发生时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_credit_ledger_key` (`tenant_id`,`idempotency_key`) USING BTREE,
  KEY `idx_credit_ledger_account` (`tenant_id`,`account_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课时追加账本';

CREATE TABLE IF NOT EXISTS `ch_course_session` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '课次ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `course_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '课程类型 private/group/family/oncourse',
  `coach_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '教练ID，0表示未指定',
  `title` varchar(120) NOT NULL COMMENT '课次标题',
  `start_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '开始时间',
  `end_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '结束时间',
  `duration_hours` decimal(4,2) NOT NULL DEFAULT '1.00' COMMENT '时长（小时）',
  `capacity` int(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT '名额',
  `booked_count` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '已约人数',
  `location_json` text COMMENT '场地 {name,address,longitude,latitude}',
  `price_per_session` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '单次价（单次体验/下场付费；0表示仅课时划扣）',
  `applicable_package_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '可用课时包，0表示不限',
  `product_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB商品ID（现金单次购买）',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1可约 2已满 3已结束 4取消',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_session_list` (`tenant_id`,`course_type`,`status`,`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='可约课次/排课';

CREATE TABLE IF NOT EXISTS `ch_course_booking` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '约课记录ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `session_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_course_session.id',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员 ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '会员 uid',
  `account_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '划扣的课时账户（个人/家庭）',
  `credit_cost_hours` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '本次划扣课时（按规则：私教1/小班2/家庭1或1.5/下场3）',
  `consume_rule` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '划扣规则标记 family_single 等',
  `participants` tinyint(3) UNSIGNED NOT NULL DEFAULT '1' COMMENT '参与人数（家庭卡按人数划扣，单人1.5/多人各1）',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1已约 2已取消 3已签到 4爽约',
  `idempotency_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '约幂等键',
  `booking_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '预约时间',
  `attended_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '签到时间',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_booking_dedup` (`tenant_id`,`session_id`,`member_id`,`status`) USING BTREE,
  UNIQUE KEY `uk_booking_key` (`tenant_id`,`idempotency_key`) USING BTREE,
  KEY `idx_booking_member` (`tenant_id`,`uid`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='约课记录';

CREATE TABLE IF NOT EXISTS `ch_course_checkin_token` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '课程动态签到码ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `session_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_course_session.id',
  `token_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '签到令牌SHA-256摘要',
  `issued_by_admin_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '签发管理员ID',
  `valid_from` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '生效时间',
  `expires_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '过期时间',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '1有效 2撤销',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_course_checkin_token` (`tenant_id`,`session_id`,`token_digest`) USING BTREE,
  KEY `idx_course_checkin_active` (`tenant_id`,`session_id`,`status`,`expires_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='课程动态签到码';
