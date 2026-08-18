-- Verification for the local G1-01D CRMEB membership products and plans.

SET NAMES utf8mb4;

SELECT 'membership_fixture_products' AS `check_name`, 2 AS `expected_count`, COUNT(*) AS `actual_count`,
       IF(COUNT(*) = 2, 'PASS', 'FAIL') AS `check_status`
FROM `eb_store_product`
WHERE `spu` IN ('CHMEML3LOCAL', 'CHMEML2LOCAL')
  AND `is_show` = 1 AND `is_del` = 0 AND `is_virtual` = 1 AND `virtual_type` = 3
  AND `give_integral` = 0 AND `is_sub` = 1

UNION ALL

SELECT 'membership_fixture_skus', 2, COUNT(*), IF(COUNT(*) = 2, 'PASS', 'FAIL')
FROM `eb_store_product_attr_value` AS attr
JOIN `eb_store_product` AS product ON product.`id` = attr.`product_id`
WHERE product.`spu` IN ('CHMEML3LOCAL', 'CHMEML2LOCAL')
  AND attr.`unique` IN ('c1a30001', 'c1a20001')
  AND attr.`stock` > 0 AND attr.`type` = 0 AND attr.`is_virtual` = 1 AND attr.`is_show` = 1
  AND attr.`brokerage` = 0 AND attr.`brokerage_two` = 0

UNION ALL

SELECT 'membership_fixture_plans', 4, COUNT(*), IF(COUNT(*) = 4, 'PASS', 'FAIL')
FROM `ch_membership_plan` AS plan_row
JOIN `ch_tenant` AS tenant_row ON tenant_row.`id` = plan_row.`tenant_id`
JOIN `ch_channel` AS channel_row
  ON channel_row.`id` = plan_row.`channel_id` AND channel_row.`tenant_id` = plan_row.`tenant_id`
WHERE tenant_row.`slug` IN ('local-primary', 'local-secondary')
  AND channel_row.`code` = 'default'
  AND plan_row.`plan_code` IN ('L3_ANNUAL', 'L2_ANNUAL')
  AND plan_row.`purchase_enabled` = 1 AND plan_row.`status` = 1
  AND plan_row.`currency` = 'CNY' AND plan_row.`term_months` = 12
  AND JSON_VALID(plan_row.`benefits_json`)
  AND JSON_VALID(plan_row.`renewal_policy_json`)
  AND JSON_VALID(plan_row.`upgrade_policy_json`)
  AND JSON_VALID(plan_row.`refund_policy_json`);
