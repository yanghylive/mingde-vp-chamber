-- Create ch_exchange_product: maps CRMEB store products to chamber points-exchange products
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_exchange_product` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `channel_id` int unsigned NOT NULL DEFAULT 0,
  `product_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'CRMEB eb_store_product.id',
  `category` varchar(32) NOT NULL DEFAULT 'product' COMMENT 'course/salon/product/service 等（小程序分类图标）',
  `points_cost` int unsigned NOT NULL DEFAULT 0 COMMENT '兑换所需积分',
  `cash_cost` decimal(16,2) unsigned NOT NULL DEFAULT 0.00 COMMENT '现金补差价（可 0）',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1 上架 0 下架',
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_product` (`tenant_id`, `product_id`),
  KEY `idx_tenant_status` (`tenant_id`, `status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分兑换商品（关联 CRMEB 商城商品）';
