-- 补 ch_product_exchange_order 建表迁移（此前为手工建表，无迁移导致新环境/本地缺失）
-- 结构对齐生产现存表（2026-08-18 从生产 SHOW CREATE TABLE 提取）
CREATE TABLE IF NOT EXISTS `ch_product_exchange_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT '0' COMMENT '租户ID',
  `member_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int unsigned NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `product_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'CRMEB 商品ID',
  `points_cost` int NOT NULL DEFAULT '0' COMMENT '消耗积分',
  `cash_cost` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '补差价现金',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|fulfilled|cancelled',
  `idempotency_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '幂等键',
  `created_at` int unsigned NOT NULL DEFAULT '0' COMMENT '下单时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_member` (`tenant_id`, `member_id`),
  KEY `idx_idempotency` (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='积分兑换订单（含现金补差）';
