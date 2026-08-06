-- Structural and stored-data postconditions for private member materials.
-- Compatible with MySQL 5.7 and 8.0.

SET NAMES utf8mb4;

SELECT
  'table.ch_member_asset' AS `check_name`,
  IF(
    actual.`TABLE_NAME` IS NOT NULL
    AND UPPER(actual.`ENGINE`) = 'INNODB'
    AND actual.`TABLE_COLLATION` = 'utf8mb4_unicode_ci'
    AND (
      SELECT COUNT(*)
      FROM information_schema.`COLUMNS` AS column_info
      WHERE column_info.`TABLE_SCHEMA` = DATABASE()
        AND column_info.`TABLE_NAME` = 'ch_member_asset'
        AND FIND_IN_SET(
          column_info.`COLUMN_NAME`,
          'id,tenant_id,channel_id,member_id,uid,purpose,object_key,storage_driver,original_name,mime_type,byte_size,sha256,status,used_business_type,used_business_id,used_time,last_access_time,add_time,update_time'
        ) > 0
    ) = 19,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'columns=', (
      SELECT COUNT(*)
      FROM information_schema.`COLUMNS` AS column_info
      WHERE column_info.`TABLE_SCHEMA` = DATABASE()
        AND column_info.`TABLE_NAME` = 'ch_member_asset'
    ),
    '; engine=', COALESCE(actual.`ENGINE`, 'missing'),
    '; collation=', COALESCE(actual.`TABLE_COLLATION`, 'missing')
  ) AS `details`
FROM (SELECT 1) AS expected
LEFT JOIN information_schema.`TABLES` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = 'ch_member_asset';

DROP TEMPORARY TABLE IF EXISTS `_ch_member_asset_expected_column`;
CREATE TEMPORARY TABLE `_ch_member_asset_expected_column` (
  `column_name` varchar(64) NOT NULL,
  `data_type` varchar(64) NOT NULL,
  `character_length` bigint(20) UNSIGNED NULL,
  `is_unsigned` tinyint(1) NOT NULL DEFAULT '-1',
  `is_nullable` char(3) NOT NULL,
  `character_set` varchar(32) NULL,
  `collation_name` varchar(64) NULL,
  PRIMARY KEY (`column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_member_asset_expected_column`
  (`column_name`,`data_type`,`character_length`,`is_unsigned`,`is_nullable`,`character_set`,`collation_name`)
VALUES
  ('id', 'bigint', NULL, 1, 'NO', NULL, NULL),
  ('tenant_id', 'int', NULL, 1, 'NO', NULL, NULL),
  ('channel_id', 'int', NULL, 1, 'NO', NULL, NULL),
  ('member_id', 'int', NULL, 1, 'NO', NULL, NULL),
  ('uid', 'int', NULL, 1, 'NO', NULL, NULL),
  ('purpose', 'varchar', 48, -1, 'NO', 'ascii', 'ascii_bin'),
  ('object_key', 'varchar', 255, -1, 'NO', 'ascii', 'ascii_bin'),
  ('storage_driver', 'varchar', 16, -1, 'NO', 'ascii', 'ascii_bin'),
  ('original_name', 'varchar', 180, -1, 'NO', 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('mime_type', 'varchar', 64, -1, 'NO', 'ascii', 'ascii_bin'),
  ('byte_size', 'int', NULL, 1, 'NO', NULL, NULL),
  ('sha256', 'char', 64, -1, 'NO', 'ascii', 'ascii_bin'),
  ('status', 'tinyint', NULL, 1, 'NO', NULL, NULL),
  ('used_business_type', 'varchar', 32, -1, 'NO', 'ascii', 'ascii_bin'),
  ('used_business_id', 'bigint', NULL, 1, 'NO', NULL, NULL),
  ('used_time', 'int', NULL, 1, 'NO', NULL, NULL),
  ('last_access_time', 'int', NULL, 1, 'NO', NULL, NULL),
  ('add_time', 'int', NULL, 1, 'NO', NULL, NULL),
  ('update_time', 'int', NULL, 1, 'NO', NULL, NULL);

SELECT
  CONCAT('column.ch_member_asset.', expected.`column_name`) AS `check_name`,
  IF(
    actual.`COLUMN_NAME` IS NOT NULL
    AND actual.`DATA_TYPE` = expected.`data_type`
    AND (expected.`character_length` IS NULL OR actual.`CHARACTER_MAXIMUM_LENGTH` = expected.`character_length`)
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
FROM `_ch_member_asset_expected_column` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = 'ch_member_asset'
 AND actual.`COLUMN_NAME` = expected.`column_name`
ORDER BY expected.`column_name`;

DROP TEMPORARY TABLE IF EXISTS `_ch_member_asset_expected_index`;
CREATE TEMPORARY TABLE `_ch_member_asset_expected_index` (
  `index_name` varchar(64) NOT NULL,
  `non_unique` tinyint(1) UNSIGNED NOT NULL,
  `index_columns` varchar(512) NOT NULL,
  PRIMARY KEY (`index_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_member_asset_expected_index` (`index_name`,`non_unique`,`index_columns`) VALUES
  ('PRIMARY', 0, 'id'),
  ('uk_member_asset_object_key', 0, 'object_key'),
  ('idx_member_asset_owner', 1, 'tenant_id,channel_id,member_id,uid,status,id'),
  ('idx_member_asset_purpose', 1, 'tenant_id,channel_id,purpose,status,id'),
  ('idx_member_asset_business', 1, 'tenant_id,used_business_type,used_business_id,id'),
  ('idx_member_asset_hash', 1, 'tenant_id,sha256,id');

SELECT
  CONCAT('index.ch_member_asset.', expected.`index_name`) AS `check_name`,
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
FROM `_ch_member_asset_expected_index` AS expected
LEFT JOIN (
  SELECT
    `TABLE_SCHEMA`, `TABLE_NAME`, `INDEX_NAME`, `NON_UNIQUE`,
    MAX(`INDEX_TYPE`) AS `INDEX_TYPE`,
    GROUP_CONCAT(`COLUMN_NAME` ORDER BY `SEQ_IN_INDEX` SEPARATOR ',') AS `index_columns`
  FROM information_schema.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_asset'
  GROUP BY `TABLE_SCHEMA`, `TABLE_NAME`, `INDEX_NAME`, `NON_UNIQUE`
) AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = 'ch_member_asset'
 AND actual.`INDEX_NAME` = expected.`index_name`
ORDER BY expected.`index_name`;

SELECT
  'data.ch_member_asset.scope_and_format' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('violations=', COUNT(*)) AS `details`
FROM `ch_member_asset`
WHERE `tenant_id` = 0 OR `channel_id` = 0 OR `member_id` = 0 OR `uid` = 0
   OR `purpose` <> 'graduate_verification_proof'
   OR `storage_driver` <> 'local'
   OR `object_key` NOT REGEXP '^member-assets/v1/t[1-9][0-9]*/[0-9a-f]{32}\\.(jpg|png|pdf)$'
   OR `mime_type` NOT IN ('image/jpeg','image/png','application/pdf')
   OR `byte_size` < 1 OR `byte_size` > 10485760
   OR `sha256` NOT REGEXP '^[0-9a-f]{64}$'
   OR `status` NOT IN (1,2,3)
   OR `add_time` = 0 OR `update_time` = 0;

SELECT
  'data.ch_member_asset.use_state' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('violations=', COUNT(*)) AS `details`
FROM `ch_member_asset`
WHERE (`status` = 1 AND (`used_business_type` <> '' OR `used_business_id` <> 0 OR `used_time` <> 0))
   OR (`status` = 2 AND (`used_business_type` = '' OR `used_business_id` = 0 OR `used_time` = 0));

DROP TEMPORARY TABLE IF EXISTS `_ch_member_asset_expected_index`;
DROP TEMPORARY TABLE IF EXISTS `_ch_member_asset_expected_column`;
