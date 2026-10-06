-- 补齐 3 张历史表：本地代码在用，但早期迁移从未创建。
--
-- 背景（2026-10-06 核查）：这三张表在生产是手工建的，本地迁移链里缺失，
-- 导致「本地/新环境按迁移重建库」后，好友列表、分销记录、后台手动开通会籍
-- 会直接 Unknown table。本迁移按生产真实结构补齐，CREATE TABLE IF NOT EXISTS
-- 保证生产（已有表）直接跳过。
--
-- 涉及代码：
--   ch_member_friend      MemberFriendController（好友申请/列表）
--   ch_membership_order   MemberAdminController（后台手动开通会籍的订单流水）
--   ch_distribution_record MemberDistributionController（邀请/分销记录）

CREATE TABLE IF NOT EXISTS `ch_member_friend` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `tenant_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '租户ID',
  `member_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发起方 ch_tenant_member.id',
  `friend_member_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '好友 ch_tenant_member.id',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|accepted',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `add_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '记录时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_friend_pair` (`tenant_id`,`member_id`,`friend_member_id`) USING BTREE,
  KEY `idx_friend_status` (`tenant_id`,`friend_member_id`,`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会员好友关系';

CREATE TABLE IF NOT EXISTS `ch_membership_order` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `tenant_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '租户ID',
  `member_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `order_no` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '业务订单号',
  `tier` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '购买目标会籍 2/3',
  `amount` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '金额（分）',
  `pay_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '支付方式 wechat/ali/manual',
  `transaction_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '支付平台单号',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0未支付 1已支付 2已过期 3已退款',
  `expire_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '购买后会籍到期',
  `idempotency_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '幂等键（防重复下单）',
  `paid_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '支付时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注（如 admin 手动开通）',
  `add_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_member` (`tenant_id`,`member_id`,`status`) USING BTREE,
  KEY `idx_order_no` (`order_no`) USING BTREE,
  KEY `idx_idem` (`tenant_id`,`idempotency_key`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会籍续费/续订订单';

CREATE TABLE IF NOT EXISTS `ch_distribution_record` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `tenant_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '租户ID',
  `member_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '邀请人 ch_tenant_member.id',
  `code` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '邀请码',
  `invited_member_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '被邀请人',
  `points_earned` int(11) NOT NULL DEFAULT '0' COMMENT '获得积分',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|credited',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `add_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '记录时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_invited` (`tenant_id`,`invited_member_id`) USING BTREE,
  KEY `idx_dist_code` (`tenant_id`,`code`) USING BTREE,
  KEY `idx_dist_member` (`tenant_id`,`member_id`,`created_at`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分销记录';
