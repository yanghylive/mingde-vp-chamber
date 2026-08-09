-- 大咖库：专家资料 + 定价（P2 功能落地）
-- 资料页 GET/PATCH /experts/profile、/experts/:id/profile
-- 定价页 GET/PATCH /experts、/experts/:id/pricing
CREATE TABLE IF NOT EXISTS `ch_expert` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '大咖姓名',
  `title` varchar(64) NOT NULL DEFAULT '' COMMENT '头衔/标签',
  `company` varchar(128) NOT NULL DEFAULT '' COMMENT '机构/公司',
  `industry` varchar(64) NOT NULL DEFAULT '' COMMENT '行业',
  `bio` text COMMENT '简介',
  `online_points` int unsigned NOT NULL DEFAULT 0 COMMENT '线上预约-积分价',
  `online_cash` decimal(16,2) unsigned NOT NULL DEFAULT 0.00 COMMENT '线上预约-现金价',
  `offline_points` int unsigned NOT NULL DEFAULT 0 COMMENT '线下预约-积分价',
  `offline_cash` decimal(16,2) unsigned NOT NULL DEFAULT 0.00 COMMENT '线下预约-现金价',
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大咖库-专家资料与定价';
