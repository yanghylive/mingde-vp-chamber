-- 微信支付单（APIv3 直连，对齐 ai-content wechatPayOrder 表结构）
-- 商户号 1116143786（与 3010 ai-content 同一套配置/逻辑）
CREATE TABLE IF NOT EXISTS `ch_wechat_pay_order` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'CRMEB eb_user.uid',
  `member_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'ch_tenant_member.id',
  `out_trade_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '本地单号（业务幂等键）',
  `mchid` varchar(32) NOT NULL DEFAULT '1116143786',
  `appid` varchar(64) NOT NULL DEFAULT '' COMMENT '下单使用的 AppID（JSAPI 需商户号已关联）',
  `description` varchar(128) NOT NULL DEFAULT '',
  `amount_cents` int unsigned NOT NULL DEFAULT 0 COMMENT '金额（分）',
  `currency` varchar(8) NOT NULL DEFAULT 'CNY',
  `business_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'membership/exchange',
  `business_ref` bigint unsigned NOT NULL DEFAULT 0 COMMENT '业务单号：membership=eb_store_order.id(order_pk)；exchange=ch_product_exchange_order.id',
  `trade_type` varchar(16) NOT NULL DEFAULT 'JSAPI' COMMENT 'JSAPI/NATIVE',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/closed',
  `transaction_id` varchar(64) NOT NULL DEFAULT '' COMMENT '微信支付单号',
  `paid_at` int unsigned NOT NULL DEFAULT 0,
  `notify_payload` text COMMENT '回调原始数据（审计）',
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_out_trade_no` (`out_trade_no`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_business` (`business_type`, `business_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信支付单（APIv3 直连，对齐 ai-content）';
