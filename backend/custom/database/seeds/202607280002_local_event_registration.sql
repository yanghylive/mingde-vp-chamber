-- LOCAL/CI ONLY: CRMEB virtual product used by the real event-registration HTTP gate.

SET NAMES utf8mb4;
SET @ch_seed_time = UNIX_TIMESTAMP();

INSERT INTO `eb_store_product`
  (`store_name`,`store_info`,`keyword`,`bar_code`,`cate_id`,`price`,`vip_price`,`ot_price`,`postage`,
   `unit_name`,`stock`,`is_show`,`is_virtual`,`virtual_type`,`give_integral`,`is_sub`,`add_time`,`is_postage`,`is_del`,`cost`,
   `is_vip`,`temp_id`,`spec_type`,`activity`,`spu`,`presale`,`logistics`,`freight`,`custom_form`,
   `is_limit`,`limit_type`,`limit_num`,`min_qty`,`default_sku`)
SELECT
  'Chamber Event Ticket','Local fixture for Chamber event registration','event,ticket','CHEVENTLOCAL','',
  10.00,0.00,10.00,0.00,'ticket',999999,1,1,3,0,0,@ch_seed_time,1,0,0.00,0,1,0,'','CHEVENTLOCAL',
  0,'1',2,'[]',0,0,0,1,'Standard'
WHERE NOT EXISTS (SELECT 1 FROM `eb_store_product` WHERE `spu`='CHEVENTLOCAL');

SET @ch_event_product_id = (SELECT `id` FROM `eb_store_product` WHERE `spu`='CHEVENTLOCAL' ORDER BY `id` LIMIT 1);

UPDATE `eb_store_product`
SET `price`=10.00,`ot_price`=10.00,`stock`=999999,`is_show`=1,`is_virtual`=1,
    `virtual_type`=3,`is_del`=0,`is_vip`=0,`give_integral`=0,`is_sub`=0,
    `is_limit`=0,`min_qty`=1,`custom_form`='[]',`presale`=0,`is_gift`=0
WHERE `id`=@ch_event_product_id;

INSERT INTO `eb_store_product_attr_value`
  (`product_id`,`suk`,`stock`,`sales`,`price`,`image`,`unique`,`cost`,`bar_code`,`bar_code_number`,
   `ot_price`,`vip_price`,`weight`,`volume`,`brokerage`,`brokerage_two`,`type`,`quota`,`quota_show`,
   `is_virtual`,`coupon_id`,`disk_info`,`is_show`,`is_default_select`)
SELECT @ch_event_product_id,'Standard',999999,0,10.00,'','cevt0001',0.00,'','',10.00,0.00,
       0.00,0.00,0.00,0.00,0,0,0,1,0,'',1,1
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_store_product_attr_value`
  WHERE `product_id`=@ch_event_product_id AND `unique`='cevt0001' AND `type`=0
);

UPDATE `eb_store_product_attr_value`
SET `suk`='Standard',`stock`=999999,`price`=10.00,`ot_price`=10.00,`vip_price`=0.00,
    `brokerage`=0.00,`brokerage_two`=0.00,`type`=0,`is_virtual`=1,`coupon_id`=0,
    `is_show`=1,`is_default_select`=1
WHERE `product_id`=@ch_event_product_id AND `unique`='cevt0001' AND `type`=0;

SET @ch_event_product_id = NULL;
SET @ch_seed_time = NULL;
