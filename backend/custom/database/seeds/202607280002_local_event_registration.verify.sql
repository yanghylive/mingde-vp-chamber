SET NAMES utf8mb4;

SELECT 'event_fixture_product' AS check_name, 1 AS expected_count, COUNT(*) AS actual_count,
  IF(COUNT(*)=1,'PASS','FAIL') AS check_status
FROM `eb_store_product`
WHERE `spu`='CHEVENTLOCAL' AND `price`=10.00 AND `stock`>0 AND `is_show`=1
  AND `is_virtual`=1 AND `virtual_type`=3 AND `is_del`=0;

SELECT 'event_fixture_sku' AS check_name, 1 AS expected_count, COUNT(*) AS actual_count,
  IF(COUNT(*)=1,'PASS','FAIL') AS check_status
FROM `eb_store_product_attr_value` AS sku
JOIN `eb_store_product` AS product ON product.`id`=sku.`product_id`
WHERE product.`spu`='CHEVENTLOCAL' AND sku.`unique`='cevt0001' AND sku.`price`=10.00
  AND sku.`stock`>0 AND sku.`is_virtual`=1 AND sku.`coupon_id`=0 AND sku.`is_show`=1;
