-- LOCAL/CI ONLY: deterministic CRMEB virtual products and Chamber L3/L4 plans.

SET NAMES utf8mb4;
SET @ch_seed_time = UNIX_TIMESTAMP();

INSERT INTO `eb_store_product`
  (`store_name`,`store_info`,`keyword`,`bar_code`,`cate_id`,`price`,`vip_price`,`ot_price`,`postage`,
   `unit_name`,`stock`,`is_show`,`is_virtual`,`virtual_type`,`give_integral`,`is_sub`,`add_time`,`is_postage`,`is_del`,`cost`,
   `is_vip`,`temp_id`,`spec_type`,`activity`,`spu`,`presale`,`logistics`,`freight`,`custom_form`,
   `is_limit`,`limit_type`,`limit_num`,`min_qty`,`default_sku`)
SELECT
  'L3 Annual Membership','Local fixture for Chamber membership checkout','membership,l3','CHMEML3LOCAL','',
  1000.00,0.00,1000.00,0.00,'year',999999,1,1,3,0,1,@ch_seed_time,1,0,0.00,0,1,0,'','CHMEML3LOCAL',
  0,'1',2,'[]',0,0,0,1,'Annual'
WHERE NOT EXISTS (SELECT 1 FROM `eb_store_product` WHERE `spu` = 'CHMEML3LOCAL');

INSERT INTO `eb_store_product`
  (`store_name`,`store_info`,`keyword`,`bar_code`,`cate_id`,`price`,`vip_price`,`ot_price`,`postage`,
   `unit_name`,`stock`,`is_show`,`is_virtual`,`virtual_type`,`give_integral`,`is_sub`,`add_time`,`is_postage`,`is_del`,`cost`,
   `is_vip`,`temp_id`,`spec_type`,`activity`,`spu`,`presale`,`logistics`,`freight`,`custom_form`,
   `is_limit`,`limit_type`,`limit_num`,`min_qty`,`default_sku`)
SELECT
  'L4 Annual Membership','Local fixture for Chamber membership checkout','membership,l4','CHMEML4LOCAL','',
  5000.00,0.00,5000.00,0.00,'year',999999,1,1,3,0,1,@ch_seed_time,1,0,0.00,0,1,0,'','CHMEML4LOCAL',
  0,'1',2,'[]',0,0,0,1,'Annual'
WHERE NOT EXISTS (SELECT 1 FROM `eb_store_product` WHERE `spu` = 'CHMEML4LOCAL');

SET @ch_l3_product_id = (SELECT `id` FROM `eb_store_product` WHERE `spu` = 'CHMEML3LOCAL' ORDER BY `id` LIMIT 1);
SET @ch_l4_product_id = (SELECT `id` FROM `eb_store_product` WHERE `spu` = 'CHMEML4LOCAL' ORDER BY `id` LIMIT 1);

UPDATE `eb_store_product`
SET `price` = 1000.00, `ot_price` = 1000.00, `stock` = 999999, `is_show` = 1,
    `is_virtual` = 1, `virtual_type` = 3, `is_del` = 0, `is_vip` = 0,
    `give_integral` = 0, `is_sub` = 1, `is_limit` = 0, `min_qty` = 1, `custom_form` = '[]'
WHERE `id` = @ch_l3_product_id;

UPDATE `eb_store_product`
SET `price` = 5000.00, `ot_price` = 5000.00, `stock` = 999999, `is_show` = 1,
    `is_virtual` = 1, `virtual_type` = 3, `is_del` = 0, `is_vip` = 0,
    `give_integral` = 0, `is_sub` = 1, `is_limit` = 0, `min_qty` = 1, `custom_form` = '[]'
WHERE `id` = @ch_l4_product_id;

INSERT INTO `eb_store_product_attr_value`
  (`product_id`,`suk`,`stock`,`sales`,`price`,`image`,`unique`,`cost`,`bar_code`,`bar_code_number`,
   `ot_price`,`vip_price`,`weight`,`volume`,`brokerage`,`brokerage_two`,`type`,`quota`,`quota_show`,
   `is_virtual`,`coupon_id`,`disk_info`,`is_show`,`is_default_select`)
SELECT @ch_l3_product_id,'Annual',999999,0,1000.00,'','c1a30001',0.00,'','',1000.00,0.00,
       0.00,0.00,0.00,0.00,0,0,0,1,0,'',1,1
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_store_product_attr_value`
  WHERE `product_id` = @ch_l3_product_id AND `unique` = 'c1a30001' AND `type` = 0
);

INSERT INTO `eb_store_product_attr_value`
  (`product_id`,`suk`,`stock`,`sales`,`price`,`image`,`unique`,`cost`,`bar_code`,`bar_code_number`,
   `ot_price`,`vip_price`,`weight`,`volume`,`brokerage`,`brokerage_two`,`type`,`quota`,`quota_show`,
   `is_virtual`,`coupon_id`,`disk_info`,`is_show`,`is_default_select`)
SELECT @ch_l4_product_id,'Annual',999999,0,5000.00,'','c1a40001',0.00,'','',5000.00,0.00,
       0.00,0.00,0.00,0.00,0,0,0,1,0,'',1,1
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_store_product_attr_value`
  WHERE `product_id` = @ch_l4_product_id AND `unique` = 'c1a40001' AND `type` = 0
);

UPDATE `eb_store_product_attr_value`
SET `suk` = 'Annual', `stock` = 999999, `price` = 1000.00, `ot_price` = 1000.00,
    `vip_price` = 0.00, `brokerage` = 0.00, `brokerage_two` = 0.00, `type` = 0, `is_virtual` = 1, `is_show` = 1, `is_default_select` = 1
WHERE `product_id` = @ch_l3_product_id AND `unique` = 'c1a30001' AND `type` = 0;

UPDATE `eb_store_product_attr_value`
SET `suk` = 'Annual', `stock` = 999999, `price` = 5000.00, `ot_price` = 5000.00,
    `vip_price` = 0.00, `brokerage` = 0.00, `brokerage_two` = 0.00, `type` = 0, `is_virtual` = 1, `is_show` = 1, `is_default_select` = 1
WHERE `product_id` = @ch_l4_product_id AND `unique` = 'c1a40001' AND `type` = 0;

INSERT INTO `ch_membership_plan`
  (`tenant_id`,`channel_id`,`plan_code`,`name`,`tier`,`purchase_enabled`,`price`,`currency`,`term_months`,
   `product_id`,`product_attr_unique`,`benefits_json`,`renewal_policy_json`,`upgrade_policy_json`,
   `refund_policy_json`,`config_version`,`status`,`effective_time`,`end_time`,`add_time`,`update_time`)
SELECT tenant_row.`id`, channel_row.`id`, plan_seed.`plan_code`, plan_seed.`name`, plan_seed.`tier`, 1,
       plan_seed.`price`, 'CNY', 12, plan_seed.`product_id`, plan_seed.`attr_unique`,
       plan_seed.`benefits_json`, '{"mode":"append_same_tier"}',
       '{"mode":"full_price_no_proration"}', '{"mode":"manual_review_before_fulfillment"}',
       1, 1, @ch_seed_time, 0, @ch_seed_time, @ch_seed_time
FROM `ch_tenant` AS tenant_row
JOIN `ch_channel` AS channel_row
  ON channel_row.`tenant_id` = tenant_row.`id`
 AND channel_row.`code` = 'default'
 AND channel_row.`status` = 1
 AND channel_row.`is_del` = 0
JOIN (
  SELECT 'L3_ANNUAL' AS `plan_code`, 'L3 Annual Membership' AS `name`, 3 AS `tier`,
         1000.00 AS `price`, @ch_l3_product_id AS `product_id`, 'c1a30001' AS `attr_unique`,
         '["Member directory","Member activities","Standard services"]' AS `benefits_json`
  UNION ALL
  SELECT 'L4_ANNUAL', 'L4 Annual Membership', 4, 5000.00,
         @ch_l4_product_id, 'c1a40001',
         '["All L3 benefits","Priority services","Dedicated support"]'
) AS plan_seed
WHERE tenant_row.`slug` IN ('local-primary', 'local-secondary')
  AND tenant_row.`status` = 1
  AND tenant_row.`is_del` = 0
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `purchase_enabled` = VALUES(`purchase_enabled`),
  `price` = VALUES(`price`),
  `currency` = VALUES(`currency`),
  `term_months` = VALUES(`term_months`),
  `product_id` = VALUES(`product_id`),
  `product_attr_unique` = VALUES(`product_attr_unique`),
  `benefits_json` = VALUES(`benefits_json`),
  `renewal_policy_json` = VALUES(`renewal_policy_json`),
  `upgrade_policy_json` = VALUES(`upgrade_policy_json`),
  `refund_policy_json` = VALUES(`refund_policy_json`),
  `config_version` = VALUES(`config_version`),
  `status` = VALUES(`status`),
  `effective_time` = VALUES(`effective_time`),
  `end_time` = VALUES(`end_time`),
  `update_time` = VALUES(`update_time`);

SET @ch_l3_product_id = NULL;
SET @ch_l4_product_id = NULL;
SET @ch_seed_time = NULL;
