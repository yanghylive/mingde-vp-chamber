-- 大咖档期表（admin 档期管理 / 小程序端预约共用）
CREATE TABLE IF NOT EXISTS `ch_expert_slot` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `expert_id` int unsigned NOT NULL DEFAULT 0 COMMENT '大咖（会员ID）',
  `start_time` int unsigned NOT NULL DEFAULT 0 COMMENT '开始时间戳',
  `end_time` int unsigned NOT NULL DEFAULT 0 COMMENT '结束时间戳',
  `status` varchar(16) NOT NULL DEFAULT 'open' COMMENT 'open/closed/full',
  `location` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0=线上 1=线下',
  `add_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_expert` (`tenant_id`, `expert_id`, `start_time`),
  KEY `idx_tenant_status` (`tenant_id`, `status`, `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大咖档期';
