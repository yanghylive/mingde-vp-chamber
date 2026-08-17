-- ch_appointment 建表迁移（补历史欠账：该表原为手动创建，无 CREATE 迁移，
-- 导致本地迁移 ALTER 时报「表不存在」）
CREATE TABLE IF NOT EXISTS `ch_appointment` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `expert_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ch_expert.id',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '预约会员 ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '预约会员 eb_user.uid',
  `slot_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ch_expert_slot.id',
  `mode` varchar(20) NOT NULL DEFAULT 'online' COMMENT 'online/offline',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/confirmed/cancelled',
  `points_cost` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '扣除积分',
  `cash_cost` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '现金（暂不收，商户号开通后补）',
  `created_at` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_member` (`tenant_id`, `member_id`),
  KEY `idx_slot` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='大咖预约';
