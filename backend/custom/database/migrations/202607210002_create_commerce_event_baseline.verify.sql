-- Structural postcondition checks for the commerce event reliability baseline.
-- Compatible with MySQL 5.7 and 8.0; temporary tables do not alter application data.

SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS `_ch_commerce_expected_table`;
CREATE TEMPORARY TABLE `_ch_commerce_expected_table` (
  `table_name` varchar(64) NOT NULL,
  `required_column_count` smallint(5) UNSIGNED NOT NULL,
  `required_columns` text NOT NULL,
  PRIMARY KEY (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_commerce_expected_table` (`table_name`, `required_column_count`, `required_columns`) VALUES
  ('ch_commerce_event_inbox', 26, 'id,tenant_id,event_id,source,event_type,source_event_id,schema_version,channel_id,order_pk,order_no,business_type,context_id,correlation_id,payload_hash,payload_json,status,attempt_count,lease_token,lease_expire_time,next_retry_time,last_error_code,occurred_time,received_time,processed_time,add_time,update_time'),
  ('ch_idempotency_record', 17, 'id,tenant_id,idempotency_key,operation,request_hash,status,lease_token,lease_expire_time,attempt_count,result_http_status,result_code,result_hash,result_json,completed_time,expire_time,add_time,update_time'),
  ('ch_refund_attempt', 33, 'id,tenant_id,refund_no,idempotency_record_id,commerce_event_id,source_type,source_id,crmeb_order_id,crmeb_order_no,crmeb_refund_id,provider,provider_trade_no,provider_refund_no,provider_refund_id,provider_status,currency,amount,status,request_hash,last_response_hash,query_retry_count,next_query_time,last_query_time,final_confirmed,final_confirm_source,final_confirm_time,failure_code,manual_operator_id,manual_reference,request_time,processing_time,add_time,update_time');

SELECT
  CONCAT('table.', expected.`table_name`) AS `check_name`,
  CASE
    WHEN actual.`TABLE_NAME` IS NULL THEN 'FAIL'
    WHEN UPPER(actual.`ENGINE`) <> 'INNODB' THEN 'FAIL'
    WHEN actual.`TABLE_COLLATION` <> 'utf8mb4_unicode_ci' THEN 'FAIL'
    WHEN (
      SELECT COUNT(*)
      FROM information_schema.`COLUMNS` AS present_column
      WHERE present_column.`TABLE_SCHEMA` = DATABASE()
        AND present_column.`TABLE_NAME` = expected.`table_name`
        AND FIND_IN_SET(present_column.`COLUMN_NAME`, expected.`required_columns`) > 0
    ) <> expected.`required_column_count` THEN 'FAIL'
    ELSE 'PASS'
  END AS `result`,
  CONCAT(
    'required_columns=',
    (
      SELECT COUNT(*)
      FROM information_schema.`COLUMNS` AS present_column
      WHERE present_column.`TABLE_SCHEMA` = DATABASE()
        AND present_column.`TABLE_NAME` = expected.`table_name`
        AND FIND_IN_SET(present_column.`COLUMN_NAME`, expected.`required_columns`) > 0
    ),
    '/', expected.`required_column_count`,
    '; engine=', COALESCE(actual.`ENGINE`, 'missing'),
    '; collation=', COALESCE(actual.`TABLE_COLLATION`, 'missing')
  ) AS `details`
FROM `_ch_commerce_expected_table` AS expected
LEFT JOIN information_schema.`TABLES` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
ORDER BY expected.`table_name`;

SELECT
  'columns.id_contract' AS `check_name`,
  IF(
    COUNT(actual.`COLUMN_NAME`) = 3
    AND SUM(
      actual.`DATA_TYPE` = 'bigint'
      AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
      AND actual.`IS_NULLABLE` = 'NO'
      AND actual.`COLUMN_KEY` = 'PRI'
      AND actual.`EXTRA` LIKE '%auto_increment%'
    ) = 3,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('valid=', COALESCE(SUM(
    actual.`DATA_TYPE` = 'bigint'
    AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
    AND actual.`IS_NULLABLE` = 'NO'
    AND actual.`COLUMN_KEY` = 'PRI'
    AND actual.`EXTRA` LIKE '%auto_increment%'
  ), 0), '/3') AS `details`
FROM `_ch_commerce_expected_table` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = 'id';

SELECT
  'columns.tenant_scope_contract' AS `check_name`,
  IF(
    COUNT(actual.`COLUMN_NAME`) = 3
    AND SUM(
      actual.`DATA_TYPE` = 'int'
      AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
      AND actual.`IS_NULLABLE` = 'NO'
      AND COALESCE(actual.`COLUMN_DEFAULT`, '') = '0'
    ) = 3,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('valid=', COALESCE(SUM(
    actual.`DATA_TYPE` = 'int'
    AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
    AND actual.`IS_NULLABLE` = 'NO'
    AND COALESCE(actual.`COLUMN_DEFAULT`, '') = '0'
  ), 0), '/3') AS `details`
FROM `_ch_commerce_expected_table` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = 'tenant_id';

DROP TEMPORARY TABLE IF EXISTS `_ch_commerce_expected_column`;
CREATE TEMPORARY TABLE `_ch_commerce_expected_column` (
  `table_name` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `data_type` varchar(64) NOT NULL,
  `character_length` bigint(20) UNSIGNED NULL,
  `numeric_precision` smallint(5) UNSIGNED NULL,
  `numeric_scale` smallint(5) UNSIGNED NULL,
  `is_unsigned` tinyint(1) NOT NULL DEFAULT '-1',
  `is_nullable` char(3) NOT NULL,
  `character_set` varchar(32) NULL,
  `collation_name` varchar(64) NULL,
  PRIMARY KEY (`table_name`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_commerce_expected_column`
  (`table_name`, `column_name`, `data_type`, `character_length`, `numeric_precision`, `numeric_scale`, `is_unsigned`, `is_nullable`, `character_set`, `collation_name`)
VALUES
  ('ch_commerce_event_inbox', 'event_id', 'char', 64, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'source', 'varchar', 32, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'event_type', 'varchar', 48, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'source_event_id', 'varchar', 128, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'schema_version', 'tinyint', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_commerce_event_inbox', 'channel_id', 'int', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_commerce_event_inbox', 'order_pk', 'int', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_commerce_event_inbox', 'order_no', 'varchar', 64, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'business_type', 'varchar', 32, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'context_id', 'bigint', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_commerce_event_inbox', 'correlation_id', 'varchar', 128, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'payload_hash', 'char', 64, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_commerce_event_inbox', 'payload_json', 'longtext', NULL, NULL, NULL, -1, 'NO', 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_commerce_event_inbox', 'status', 'varchar', 16, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_idempotency_record', 'idempotency_key', 'varchar', 128, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_idempotency_record', 'operation', 'varchar', 64, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_idempotency_record', 'request_hash', 'char', 64, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_idempotency_record', 'status', 'varchar', 16, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_idempotency_record', 'lease_expire_time', 'int', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_idempotency_record', 'result_http_status', 'smallint', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_idempotency_record', 'result_json', 'longtext', NULL, NULL, NULL, -1, 'YES', 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_refund_attempt', 'refund_no', 'varchar', 64, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'provider', 'varchar', 24, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'provider_refund_no', 'varchar', 96, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'provider_refund_id', 'varchar', 128, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'amount', 'decimal', NULL, 16, 2, 1, 'NO', NULL, NULL),
  ('ch_refund_attempt', 'currency', 'char', 3, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'status', 'varchar', 16, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'request_hash', 'char', 64, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'query_retry_count', 'smallint', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_refund_attempt', 'final_confirmed', 'tinyint', NULL, NULL, NULL, 1, 'NO', NULL, NULL),
  ('ch_refund_attempt', 'final_confirm_source', 'varchar', 32, NULL, NULL, -1, 'NO', 'ascii', 'ascii_bin'),
  ('ch_refund_attempt', 'final_confirm_time', 'int', NULL, NULL, NULL, 1, 'NO', NULL, NULL);

SELECT
  CONCAT('column.', expected.`table_name`, '.', expected.`column_name`) AS `check_name`,
  IF(
    actual.`COLUMN_NAME` IS NOT NULL
    AND actual.`DATA_TYPE` = expected.`data_type`
    AND (expected.`character_length` IS NULL OR actual.`CHARACTER_MAXIMUM_LENGTH` = expected.`character_length`)
    AND (expected.`numeric_precision` IS NULL OR actual.`NUMERIC_PRECISION` = expected.`numeric_precision`)
    AND (expected.`numeric_scale` IS NULL OR actual.`NUMERIC_SCALE` = expected.`numeric_scale`)
    AND (expected.`is_unsigned` = -1 OR (actual.`COLUMN_TYPE` LIKE '%unsigned%') = expected.`is_unsigned`)
    AND actual.`IS_NULLABLE` = expected.`is_nullable`
    AND (expected.`character_set` IS NULL OR actual.`CHARACTER_SET_NAME` = expected.`character_set`)
    AND (expected.`collation_name` IS NULL OR actual.`COLLATION_NAME` = expected.`collation_name`),
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'type=', COALESCE(actual.`COLUMN_TYPE`, 'missing'),
    '; nullable=', COALESCE(actual.`IS_NULLABLE`, 'missing'),
    '; charset=', COALESCE(actual.`CHARACTER_SET_NAME`, 'n/a'),
    '; collation=', COALESCE(actual.`COLLATION_NAME`, 'n/a')
  ) AS `details`
FROM `_ch_commerce_expected_column` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = expected.`column_name`
ORDER BY expected.`table_name`, expected.`column_name`;

SELECT
  'columns.append_only_contract' AS `check_name`,
  IF(COUNT(actual.`COLUMN_NAME`) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('forbidden_delete_columns=', COUNT(actual.`COLUMN_NAME`)) AS `details`
FROM information_schema.`COLUMNS` AS actual
WHERE actual.`TABLE_SCHEMA` = DATABASE()
  AND actual.`TABLE_NAME` IN ('ch_commerce_event_inbox', 'ch_idempotency_record', 'ch_refund_attempt')
  AND actual.`COLUMN_NAME` IN ('is_del', 'deleted_at', 'delete_time');

SELECT
  'inbox.no_pii_columns' AS `check_name`,
  IF(COUNT(actual.`COLUMN_NAME`) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('forbidden_pii_columns=', COUNT(actual.`COLUMN_NAME`)) AS `details`
FROM information_schema.`COLUMNS` AS actual
WHERE actual.`TABLE_SCHEMA` = DATABASE()
  AND actual.`TABLE_NAME` = 'ch_commerce_event_inbox'
  AND actual.`COLUMN_NAME` IN ('uid', 'real_name', 'nickname', 'phone', 'mobile', 'email', 'address', 'openid', 'id_card');

DROP TEMPORARY TABLE IF EXISTS `_ch_commerce_expected_index`;
CREATE TEMPORARY TABLE `_ch_commerce_expected_index` (
  `table_name` varchar(64) NOT NULL,
  `index_name` varchar(64) NOT NULL,
  `non_unique` tinyint(1) UNSIGNED NOT NULL,
  `index_columns` varchar(512) NOT NULL,
  PRIMARY KEY (`table_name`, `index_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_commerce_expected_index` (`table_name`, `index_name`, `non_unique`, `index_columns`) VALUES
  ('ch_commerce_event_inbox', 'PRIMARY', 0, 'id'),
  ('ch_commerce_event_inbox', 'uk_commerce_event_id', 0, 'event_id'),
  ('ch_commerce_event_inbox', 'uk_commerce_event_source', 0, 'tenant_id,source,event_type,source_event_id'),
  ('ch_commerce_event_inbox', 'idx_commerce_event_work', 1, 'tenant_id,status,next_retry_time,id'),
  ('ch_commerce_event_inbox', 'idx_commerce_event_order', 1, 'tenant_id,order_pk,order_no,id'),
  ('ch_commerce_event_inbox', 'idx_commerce_event_business', 1, 'tenant_id,business_type,context_id,id'),
  ('ch_commerce_event_inbox', 'idx_commerce_event_hash', 1, 'tenant_id,payload_hash'),
  ('ch_commerce_event_inbox', 'idx_commerce_event_received', 1, 'tenant_id,received_time,id'),
  ('ch_idempotency_record', 'PRIMARY', 0, 'id'),
  ('ch_idempotency_record', 'uk_idempotency_tenant_key', 0, 'tenant_id,idempotency_key'),
  ('ch_idempotency_record', 'idx_idempotency_lease', 1, 'tenant_id,status,lease_expire_time,id'),
  ('ch_idempotency_record', 'idx_idempotency_operation', 1, 'tenant_id,operation,status,add_time'),
  ('ch_idempotency_record', 'idx_idempotency_expire', 1, 'tenant_id,expire_time,id'),
  ('ch_refund_attempt', 'PRIMARY', 0, 'id'),
  ('ch_refund_attempt', 'uk_refund_attempt_no', 0, 'tenant_id,refund_no'),
  ('ch_refund_attempt', 'uk_refund_provider_no', 0, 'tenant_id,provider,provider_refund_no'),
  ('ch_refund_attempt', 'idx_refund_source', 1, 'tenant_id,source_type,source_id,id'),
  ('ch_refund_attempt', 'idx_refund_order', 1, 'tenant_id,crmeb_order_id,status,id'),
  ('ch_refund_attempt', 'idx_refund_query', 1, 'tenant_id,status,next_query_time,id'),
  ('ch_refund_attempt', 'idx_refund_provider_id', 1, 'tenant_id,provider,provider_refund_id'),
  ('ch_refund_attempt', 'idx_refund_final', 1, 'tenant_id,final_confirmed,status,update_time');

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
FROM `_ch_commerce_expected_index` AS expected
LEFT JOIN (
  SELECT
    `TABLE_SCHEMA`,
    `TABLE_NAME`,
    `INDEX_NAME`,
    `NON_UNIQUE`,
    MAX(`INDEX_TYPE`) AS `INDEX_TYPE`,
    GROUP_CONCAT(`COLUMN_NAME` ORDER BY `SEQ_IN_INDEX` SEPARATOR ',') AS `index_columns`
  FROM information_schema.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
  GROUP BY `TABLE_SCHEMA`, `TABLE_NAME`, `INDEX_NAME`, `NON_UNIQUE`
) AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`INDEX_NAME` = expected.`index_name`
ORDER BY expected.`table_name`, expected.`index_name`;

DROP TEMPORARY TABLE IF EXISTS `_ch_commerce_expected_index`;
DROP TEMPORARY TABLE IF EXISTS `_ch_commerce_expected_column`;
DROP TEMPORARY TABLE IF EXISTS `_ch_commerce_expected_table`;
