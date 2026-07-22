-- Structural postconditions for membership commerce facts.
-- Compatible with MySQL 5.7 and 8.0; does not change application data.

SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_table`;
CREATE TEMPORARY TABLE `_ch_membership_expected_table` (
  `table_name` varchar(64) NOT NULL,
  `required_column_count` smallint(5) UNSIGNED NOT NULL,
  `required_columns` text NOT NULL,
  PRIMARY KEY (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_membership_expected_table` (`table_name`, `required_column_count`, `required_columns`) VALUES
  ('ch_membership_plan', 22, 'id,tenant_id,channel_id,plan_code,name,tier,purchase_enabled,price,currency,term_months,product_id,product_attr_unique,benefits_json,renewal_policy_json,upgrade_policy_json,refund_policy_json,config_version,status,effective_time,end_time,add_time,update_time'),
  ('ch_order_context', 27, 'id,tenant_id,channel_id,member_id,uid,context_no,order_pk,order_no,business_type,business_id,currency,list_amount,payable_amount,paid_amount,refunded_amount,integral_amount,price_snapshot_json,entitlement_snapshot_json,refund_policy_snapshot_json,settlement_snapshot_json,pay_status,completion_kind,refund_status,paid_time,version,add_time,update_time'),
  ('ch_membership_term', 28, 'id,tenant_id,channel_id,member_id,uid,term_no,plan_id,plan_code,tier,order_context_id,order_pk,order_no,source_type,currency,paid_amount,refunded_amount,original_start_time,original_end_time,effective_start_time,effective_end_time,state,grant_event_id,plan_snapshot_json,benefits_snapshot_json,refund_policy_snapshot_json,version,add_time,update_time'),
  ('ch_membership_term_effect', 18, 'id,tenant_id,term_id,order_context_id,effect_key,effect_hash,event_id,completion_id,effect_type,refund_delta,before_state,after_state,before_end_time,after_end_time,reason_code,operator_type,operator_id,add_time');

SELECT
  CONCAT('table.', expected.`table_name`) AS `check_name`,
  IF(
    actual.`TABLE_NAME` IS NOT NULL
    AND UPPER(actual.`ENGINE`) = 'INNODB'
    AND actual.`TABLE_COLLATION` = 'utf8mb4_unicode_ci'
    AND (
      SELECT COUNT(*)
      FROM information_schema.`COLUMNS` AS present_column
      WHERE present_column.`TABLE_SCHEMA` = DATABASE()
        AND present_column.`TABLE_NAME` = expected.`table_name`
        AND FIND_IN_SET(present_column.`COLUMN_NAME`, expected.`required_columns`) > 0
    ) = expected.`required_column_count`,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'columns=',
    (
      SELECT COUNT(*)
      FROM information_schema.`COLUMNS` AS present_column
      WHERE present_column.`TABLE_SCHEMA` = DATABASE()
        AND present_column.`TABLE_NAME` = expected.`table_name`
        AND FIND_IN_SET(present_column.`COLUMN_NAME`, expected.`required_columns`) > 0
    ), '/', expected.`required_column_count`,
    '; engine=', COALESCE(actual.`ENGINE`, 'missing'),
    '; collation=', COALESCE(actual.`TABLE_COLLATION`, 'missing')
  ) AS `details`
FROM `_ch_membership_expected_table` AS expected
LEFT JOIN information_schema.`TABLES` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
ORDER BY expected.`table_name`;

DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_column`;
CREATE TEMPORARY TABLE `_ch_membership_expected_column` (
  `table_name` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `data_type` varchar(64) NOT NULL,
  `is_nullable` char(3) NOT NULL,
  `is_unsigned` tinyint(1) NOT NULL DEFAULT '-1',
  `character_length` bigint(20) UNSIGNED NULL,
  `numeric_precision` smallint(5) UNSIGNED NULL,
  `numeric_scale` smallint(5) UNSIGNED NULL,
  `character_set` varchar(32) NULL,
  `collation_name` varchar(64) NULL,
  PRIMARY KEY (`table_name`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_membership_expected_column`
  (`table_name`, `column_name`, `data_type`, `is_nullable`, `is_unsigned`, `character_length`, `numeric_precision`, `numeric_scale`, `character_set`, `collation_name`)
VALUES
  ('ch_membership_plan', 'id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_plan', 'plan_code', 'varchar', 'NO', -1, 32, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_plan', 'tier', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_plan', 'price', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_membership_plan', 'currency', 'char', 'NO', -1, 3, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_plan', 'benefits_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_order_context', 'id', 'bigint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'context_no', 'char', 'NO', -1, 32, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_order_context', 'order_pk', 'int', 'YES', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'order_no', 'varchar', 'YES', -1, 64, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_order_context', 'payable_amount', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_order_context', 'completion_kind', 'varchar', 'NO', -1, 16, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_order_context', 'entitlement_snapshot_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_membership_term', 'id', 'bigint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'term_no', 'char', 'NO', -1, 32, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term', 'grant_event_id', 'char', 'NO', -1, 64, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term', 'paid_amount', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_membership_term', 'effective_end_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'id', 'bigint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'effect_key', 'char', 'NO', -1, 64, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term_effect', 'effect_hash', 'char', 'NO', -1, 64, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term_effect', 'event_id', 'char', 'YES', -1, 64, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term_effect', 'completion_id', 'varchar', 'YES', -1, 128, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term_effect', 'refund_delta', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_membership_plan', 'tenant_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_plan', 'channel_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_plan', 'purchase_enabled', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_plan', 'term_months', 'smallint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_plan', 'product_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_plan', 'product_attr_unique', 'varchar', 'NO', -1, 20, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_plan', 'renewal_policy_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_membership_plan', 'upgrade_policy_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_membership_plan', 'refund_policy_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_membership_plan', 'status', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'tenant_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'channel_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'member_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'uid', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'business_type', 'varchar', 'NO', -1, 32, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_order_context', 'business_id', 'bigint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'currency', 'char', 'NO', -1, 3, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_order_context', 'list_amount', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_order_context', 'paid_amount', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_order_context', 'refunded_amount', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_order_context', 'integral_amount', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_order_context', 'price_snapshot_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_order_context', 'refund_policy_snapshot_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_order_context', 'settlement_snapshot_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_order_context', 'pay_status', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'refund_status', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'paid_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_order_context', 'version', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'tenant_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'channel_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'member_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'uid', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'plan_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'plan_code', 'varchar', 'NO', -1, 32, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term', 'tier', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'order_context_id', 'bigint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'order_pk', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'order_no', 'varchar', 'NO', -1, 64, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term', 'source_type', 'varchar', 'NO', -1, 16, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term', 'currency', 'char', 'NO', -1, 3, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term', 'refunded_amount', 'decimal', 'NO', 1, NULL, 16, 2, NULL, NULL),
  ('ch_membership_term', 'original_start_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'original_end_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'effective_start_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'state', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term', 'plan_snapshot_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_membership_term', 'benefits_snapshot_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_membership_term', 'refund_policy_snapshot_json', 'longtext', 'NO', -1, NULL, NULL, NULL, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_membership_term', 'version', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'tenant_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'term_id', 'bigint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'order_context_id', 'bigint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'effect_type', 'varchar', 'NO', -1, 24, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term_effect', 'before_state', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'after_state', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'before_end_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'after_end_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'reason_code', 'varchar', 'NO', -1, 64, NULL, NULL, 'ascii', 'ascii_bin'),
  ('ch_membership_term_effect', 'operator_type', 'tinyint', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'operator_id', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL),
  ('ch_membership_term_effect', 'add_time', 'int', 'NO', 1, NULL, NULL, NULL, NULL, NULL);

SELECT
  CONCAT('column.', expected.`table_name`, '.', expected.`column_name`) AS `check_name`,
  IF(
    actual.`COLUMN_NAME` IS NOT NULL
    AND actual.`DATA_TYPE` = expected.`data_type`
    AND actual.`IS_NULLABLE` = expected.`is_nullable`
    AND (expected.`is_unsigned` = -1 OR (actual.`COLUMN_TYPE` LIKE '%unsigned%') = expected.`is_unsigned`)
    AND (expected.`character_length` IS NULL OR actual.`CHARACTER_MAXIMUM_LENGTH` = expected.`character_length`)
    AND (expected.`numeric_precision` IS NULL OR actual.`NUMERIC_PRECISION` = expected.`numeric_precision`)
    AND (expected.`numeric_scale` IS NULL OR actual.`NUMERIC_SCALE` = expected.`numeric_scale`)
    AND (expected.`character_set` IS NULL OR actual.`CHARACTER_SET_NAME` = expected.`character_set`)
    AND (expected.`collation_name` IS NULL OR actual.`COLLATION_NAME` = expected.`collation_name`)
    AND (expected.`column_name` <> 'id' OR actual.`EXTRA` LIKE '%auto_increment%'),
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'type=', COALESCE(actual.`COLUMN_TYPE`, 'missing'),
    '; nullable=', COALESCE(actual.`IS_NULLABLE`, 'missing'),
    '; extra=', COALESCE(actual.`EXTRA`, 'missing'),
    '; charset=', COALESCE(actual.`CHARACTER_SET_NAME`, 'n/a'),
    '; collation=', COALESCE(actual.`COLLATION_NAME`, 'n/a')
  ) AS `details`
FROM `_ch_membership_expected_column` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = expected.`column_name`
ORDER BY expected.`table_name`, expected.`column_name`;

DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_default`;
CREATE TEMPORARY TABLE `_ch_membership_expected_default` (
  `table_name` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `expects_null` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',
  `expected_default` varchar(255) NULL,
  PRIMARY KEY (`table_name`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_membership_expected_default` (`table_name`, `column_name`, `expects_null`, `expected_default`) VALUES
  ('ch_membership_plan', 'purchase_enabled', 0, '0'),
  ('ch_membership_plan', 'price', 0, '0.00'),
  ('ch_membership_plan', 'currency', 0, 'CNY'),
  ('ch_membership_plan', 'term_months', 0, '12'),
  ('ch_membership_plan', 'product_id', 0, '0'),
  ('ch_membership_plan', 'product_attr_unique', 0, ''),
  ('ch_membership_plan', 'config_version', 0, '1'),
  ('ch_membership_plan', 'status', 0, '0'),
  ('ch_order_context', 'order_pk', 1, NULL),
  ('ch_order_context', 'order_no', 1, NULL),
  ('ch_order_context', 'currency', 0, 'CNY'),
  ('ch_order_context', 'list_amount', 0, '0.00'),
  ('ch_order_context', 'payable_amount', 0, '0.00'),
  ('ch_order_context', 'paid_amount', 0, '0.00'),
  ('ch_order_context', 'refunded_amount', 0, '0.00'),
  ('ch_order_context', 'integral_amount', 0, '0.00'),
  ('ch_order_context', 'pay_status', 0, '0'),
  ('ch_order_context', 'completion_kind', 0, 'pending'),
  ('ch_order_context', 'refund_status', 0, '0'),
  ('ch_order_context', 'paid_time', 0, '0'),
  ('ch_order_context', 'version', 0, '1'),
  ('ch_membership_term', 'tier', 0, '3'),
  ('ch_membership_term', 'state', 0, '1'),
  ('ch_membership_term', 'currency', 0, 'CNY'),
  ('ch_membership_term', 'paid_amount', 0, '0.00'),
  ('ch_membership_term', 'refunded_amount', 0, '0.00'),
  ('ch_membership_term', 'source_type', 0, 'purchase'),
  ('ch_membership_term', 'version', 0, '1'),
  ('ch_membership_term_effect', 'event_id', 1, NULL),
  ('ch_membership_term_effect', 'completion_id', 1, NULL),
  ('ch_membership_term_effect', 'refund_delta', 0, '0.00'),
  ('ch_membership_term_effect', 'before_state', 0, '0'),
  ('ch_membership_term_effect', 'after_state', 0, '0'),
  ('ch_membership_term_effect', 'reason_code', 0, ''),
  ('ch_membership_term_effect', 'operator_type', 0, '0'),
  ('ch_membership_term_effect', 'operator_id', 0, '0');

SELECT
  CONCAT('default.', expected.`table_name`, '.', expected.`column_name`) AS `check_name`,
  IF(
    actual.`COLUMN_NAME` IS NOT NULL
    AND (
      (expected.`expects_null` = 1 AND actual.`COLUMN_DEFAULT` IS NULL)
      OR (expected.`expects_null` = 0 AND actual.`COLUMN_DEFAULT` = expected.`expected_default`)
    ),
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'expected=', IF(expected.`expects_null` = 1, 'NULL', CONCAT('"', expected.`expected_default`, '"')),
    '; actual=', IF(actual.`COLUMN_DEFAULT` IS NULL, 'NULL', CONCAT('"', actual.`COLUMN_DEFAULT`, '"'))
  ) AS `details`
FROM `_ch_membership_expected_default` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = expected.`column_name`
ORDER BY expected.`table_name`, expected.`column_name`;

DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_index`;
CREATE TEMPORARY TABLE `_ch_membership_expected_index` (
  `table_name` varchar(64) NOT NULL,
  `index_name` varchar(64) NOT NULL,
  `non_unique` tinyint(1) UNSIGNED NOT NULL,
  `index_columns` varchar(512) NOT NULL,
  PRIMARY KEY (`table_name`, `index_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_membership_expected_index` (`table_name`, `index_name`, `non_unique`, `index_columns`) VALUES
  ('ch_membership_plan', 'PRIMARY', 0, 'id'),
  ('ch_membership_plan', 'uk_membership_plan_code', 0, 'tenant_id,channel_id,plan_code'),
  ('ch_membership_plan', 'uk_membership_plan_tier', 0, 'tenant_id,channel_id,tier'),
  ('ch_membership_plan', 'idx_membership_plan_sale', 1, 'tenant_id,channel_id,status,purchase_enabled,tier'),
  ('ch_membership_plan', 'idx_membership_plan_product', 1, 'tenant_id,channel_id,product_id,product_attr_unique,status'),
  ('ch_order_context', 'PRIMARY', 0, 'id'),
  ('ch_order_context', 'uk_order_context_no', 0, 'tenant_id,context_no'),
  ('ch_order_context', 'uk_order_context_order_pk', 0, 'order_pk'),
  ('ch_order_context', 'uk_order_context_order_no', 0, 'tenant_id,order_no,uid'),
  ('ch_order_context', 'idx_order_context_member', 1, 'tenant_id,member_id,pay_status,add_time'),
  ('ch_order_context', 'idx_order_context_business', 1, 'tenant_id,business_type,business_id,pay_status'),
  ('ch_order_context', 'idx_order_context_refund', 1, 'tenant_id,refund_status,update_time'),
  ('ch_order_context', 'idx_order_context_channel', 1, 'tenant_id,channel_id,pay_status,add_time'),
  ('ch_membership_term', 'PRIMARY', 0, 'id'),
  ('ch_membership_term', 'uk_membership_term_no', 0, 'tenant_id,term_no'),
  ('ch_membership_term', 'uk_membership_term_context', 0, 'tenant_id,order_context_id'),
  ('ch_membership_term', 'uk_membership_term_grant_event', 0, 'tenant_id,grant_event_id'),
  ('ch_membership_term', 'idx_membership_term_member', 1, 'tenant_id,member_id,state,tier,effective_end_time'),
  ('ch_membership_term', 'idx_membership_term_expiry', 1, 'tenant_id,state,effective_end_time,id'),
  ('ch_membership_term', 'idx_membership_term_order', 1, 'tenant_id,order_pk,order_no'),
  ('ch_membership_term_effect', 'PRIMARY', 0, 'id'),
  ('ch_membership_term_effect', 'uk_membership_effect_key', 0, 'tenant_id,effect_key'),
  ('ch_membership_term_effect', 'uk_membership_effect_completion', 0, 'tenant_id,term_id,completion_id'),
  ('ch_membership_term_effect', 'idx_membership_effect_term', 1, 'tenant_id,term_id,id'),
  ('ch_membership_term_effect', 'idx_membership_effect_context', 1, 'tenant_id,order_context_id,id'),
  ('ch_membership_term_effect', 'idx_membership_effect_event', 1, 'tenant_id,event_id,id');

SELECT
  CONCAT('index.', expected.`table_name`, '.', expected.`index_name`) AS `check_name`,
  IF(
    actual.`INDEX_NAME` IS NOT NULL
    AND actual.`NON_UNIQUE` = expected.`non_unique`
    AND actual.`index_columns` = expected.`index_columns`
    AND UPPER(actual.`INDEX_TYPE`) = 'BTREE',
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'columns=', COALESCE(actual.`index_columns`, 'missing'),
    '; non_unique=', COALESCE(actual.`NON_UNIQUE`, 'missing'),
    '; type=', COALESCE(actual.`INDEX_TYPE`, 'missing')
  ) AS `details`
FROM `_ch_membership_expected_index` AS expected
LEFT JOIN (
  SELECT
    `TABLE_SCHEMA`, `TABLE_NAME`, `INDEX_NAME`, `NON_UNIQUE`,
    MAX(`INDEX_TYPE`) AS `INDEX_TYPE`,
    GROUP_CONCAT(
      IF(`SUB_PART` IS NULL, `COLUMN_NAME`, CONCAT(`COLUMN_NAME`, '(', `SUB_PART`, ')'))
      ORDER BY `SEQ_IN_INDEX` SEPARATOR ','
    ) AS `index_columns`
  FROM information_schema.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
  GROUP BY `TABLE_SCHEMA`, `TABLE_NAME`, `INDEX_NAME`, `NON_UNIQUE`
) AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`INDEX_NAME` = expected.`index_name`
ORDER BY expected.`table_name`, expected.`index_name`;

SELECT
  'tables.append_only_contract' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('forbidden_delete_columns=', COUNT(*)) AS `details`
FROM information_schema.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('ch_membership_plan', 'ch_order_context', 'ch_membership_term', 'ch_membership_term_effect')
  AND `COLUMN_NAME` IN ('is_del', 'deleted_at', 'delete_time');

SELECT
  'tables.no_foreign_keys' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('foreign_keys=', COUNT(*)) AS `details`
FROM information_schema.`TABLE_CONSTRAINTS`
WHERE `CONSTRAINT_SCHEMA` = DATABASE()
  AND `TABLE_NAME` IN ('ch_membership_plan', 'ch_order_context', 'ch_membership_term', 'ch_membership_term_effect')
  AND `CONSTRAINT_TYPE` = 'FOREIGN KEY';

SELECT
  'data.membership_plan_invariants' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('invalid_rows=', COUNT(*)) AS `details`
FROM `ch_membership_plan`
WHERE `tier` NOT IN (3, 4)
   OR `purchase_enabled` NOT IN (0, 1)
   OR `status` NOT IN (0, 1, 2, 3)
   OR `currency` <> 'CNY'
   OR `config_version` = 0
   OR (`purchase_enabled` = 1 AND (`price` = 0 OR `term_months` = 0 OR `product_id` = 0 OR `product_attr_unique` = ''))
   OR NOT JSON_VALID(`benefits_json`)
   OR NOT JSON_VALID(`renewal_policy_json`)
   OR NOT JSON_VALID(`upgrade_policy_json`)
   OR NOT JSON_VALID(`refund_policy_json`);

SELECT
  'data.order_context_invariants' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('invalid_rows=', COUNT(*)) AS `details`
FROM `ch_order_context`
WHERE `currency` <> 'CNY'
   OR `pay_status` NOT IN (0, 1, 2, 3)
   OR `refund_status` NOT IN (0, 1, 2, 3, 4, 5, 6)
   OR `completion_kind` NOT IN ('pending', 'paid', 'zero_amount')
   OR `refunded_amount` > `paid_amount`
   OR `list_amount` < `payable_amount`
   OR (`pay_status` = 1 AND `completion_kind` = 'paid' AND (`paid_amount` = 0 OR `paid_amount` <> `payable_amount`))
   OR (`pay_status` = 1 AND `completion_kind` = 'zero_amount' AND (`paid_amount` <> 0 OR `payable_amount` <> 0 OR `refund_status` <> 0))
   OR (`pay_status` = 1 AND `completion_kind` = 'pending')
   OR (`pay_status` <> 1 AND (`completion_kind` <> 'pending' OR `paid_amount` <> 0 OR `refunded_amount` <> 0 OR `refund_status` <> 0 OR `paid_time` <> 0))
   OR (`refund_status` = 3 AND (`refunded_amount` = 0 OR `refunded_amount` >= `paid_amount`))
   OR (`refund_status` = 4 AND (`paid_amount` = 0 OR `refunded_amount` <> `paid_amount`))
   OR (`refund_status` = 0 AND `refunded_amount` <> 0)
   OR (`refunded_amount` > 0 AND `refunded_amount` = `paid_amount` AND `refund_status` <> 4)
   OR `version` = 0
   OR NOT JSON_VALID(`price_snapshot_json`)
   OR NOT JSON_VALID(`entitlement_snapshot_json`)
   OR NOT JSON_VALID(`refund_policy_snapshot_json`)
   OR NOT JSON_VALID(`settlement_snapshot_json`);

SELECT
  'data.membership_term_invariants' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('invalid_rows=', COUNT(*)) AS `details`
FROM `ch_membership_term`
WHERE `tier` NOT IN (3, 4)
   OR `state` NOT IN (1, 2, 3)
   OR `currency` <> 'CNY'
   OR `refunded_amount` > `paid_amount`
   OR `original_start_time` = 0
   OR `original_end_time` <= `original_start_time`
   OR `effective_start_time` = 0
   OR `effective_end_time` <= `effective_start_time`
   OR (`state` = 3 AND `refunded_amount` <> `paid_amount`)
   OR `version` = 0
   OR NOT JSON_VALID(`plan_snapshot_json`)
   OR NOT JSON_VALID(`benefits_snapshot_json`)
   OR NOT JSON_VALID(`refund_policy_snapshot_json`);

SELECT
  'data.membership_effect_invariants' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('invalid_rows=', COUNT(*)) AS `details`
FROM `ch_membership_term_effect`
WHERE `before_state` NOT IN (0, 1, 2, 3)
   OR `after_state` NOT IN (1, 2, 3)
   OR `operator_type` NOT IN (0, 1, 2)
   OR `effect_type` = ''
   OR (`effect_type` IN ('partial_refund', 'full_refund') AND (`event_id` IS NULL OR `completion_id` IS NULL));

SELECT
  'database.strict_financial_writes' AS `check_name`,
  IF(
    FIND_IN_SET('STRICT_TRANS_TABLES', @@SESSION.sql_mode) > 0
    OR FIND_IN_SET('STRICT_ALL_TABLES', @@SESSION.sql_mode) > 0,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('sql_mode=', @@SESSION.sql_mode) AS `details`;

DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_index`;
DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_default`;
DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_column`;
DROP TEMPORARY TABLE IF EXISTS `_ch_membership_expected_table`;
