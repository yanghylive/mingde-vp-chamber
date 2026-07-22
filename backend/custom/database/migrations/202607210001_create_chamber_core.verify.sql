-- Structural postcondition checks for the Chamber core schema migration.
-- Compatible with MySQL 5.7 and 8.0; temporary tables do not alter the application schema.

SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS `_ch_expected_table`;
CREATE TEMPORARY TABLE `_ch_expected_table` (
  `table_name` varchar(64) NOT NULL,
  `required_column_count` smallint(5) UNSIGNED NOT NULL,
  `required_columns` text NOT NULL,
  PRIMARY KEY (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_expected_table` (`table_name`, `required_column_count`, `required_columns`) VALUES
  ('ch_audit_record', 12, 'id,tenant_id,business_type,business_id,action,from_status,to_status,operator_type,operator_id,opinion,extra_json,add_time'),
  ('ch_channel', 10, 'id,tenant_id,name,code,entry_key,status,sort,add_time,update_time,is_del'),
  ('ch_event', 25, 'id,tenant_id,channel_id,event_no,event_type,title,cover_image,summary,detail,start_time,end_time,signup_start_time,signup_end_time,location_name,address,longitude,latitude,min_tier,eligibility_json,status,created_admin_id,publish_time,add_time,update_time,is_del'),
  ('ch_event_checkin', 12, 'id,tenant_id,event_id,registration_id,member_id,uid,checkin_type,token_digest,operator_admin_id,reason,checked_time,add_time'),
  ('ch_event_registration', 18, 'id,tenant_id,event_id,ticket_id,member_id,uid,registration_no,order_pk,order_no,amount,integral_amount,status,reserve_expire_time,paid_time,cancel_time,refund_time,add_time,update_time'),
  ('ch_event_ticket', 20, 'id,tenant_id,event_id,name,price,integral_price,product_id,product_attr_unique,capacity,reserved_count,paid_count,min_tier,eligibility_json,sale_start_time,sale_end_time,status,sort,add_time,update_time,is_del'),
  ('ch_graduate_verification', 16, 'id,tenant_id,member_id,uid,channel_id,apply_no,class_name,graduation_year,proof_json,status,reviewer_admin_id,review_note,submit_time,review_time,add_time,update_time'),
  ('ch_member_profile', 19, 'id,tenant_id,member_id,uid,real_name,class_name,graduation_year,industry,company_name,job_title,province,city,bio,expertise_json,privacy_json,profile_status,add_time,update_time,is_del'),
  ('ch_member_role', 14, 'id,tenant_id,member_id,uid,role_id,is_primary,grant_source,source_application_id,status,effective_time,expire_time,revoke_time,add_time,update_time'),
  ('ch_persona_role', 13, 'id,tenant_id,code,name,description,application_required,materials_schema_json,profile_template,status,sort,add_time,update_time,is_del'),
  ('ch_role_application', 15, 'id,tenant_id,member_id,uid,role_id,apply_no,materials_json,status,reviewer_admin_id,review_note,valid_until,submit_time,review_time,add_time,update_time'),
  ('ch_tenant', 10, 'id,name,slug,display_name,contact_name,contact_phone,status,add_time,update_time,is_del'),
  ('ch_tenant_member', 16, 'id,tenant_id,uid,first_channel_id,current_channel_id,referrer_uid,tier,verification_status,primary_role_id,status,join_time,certified_time,tier_expire_time,add_time,update_time,is_del');

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
FROM `_ch_expected_table` AS expected
LEFT JOIN information_schema.`TABLES` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
ORDER BY expected.`table_name`;

SELECT
  'columns.id_contract' AS `check_name`,
  IF(
    COUNT(actual.`COLUMN_NAME`) = 13
    AND SUM(
      actual.`DATA_TYPE` IN ('int', 'bigint')
      AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
      AND actual.`IS_NULLABLE` = 'NO'
      AND actual.`COLUMN_KEY` = 'PRI'
      AND actual.`EXTRA` LIKE '%auto_increment%'
    ) = 13,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'valid=',
    COALESCE(SUM(
      actual.`DATA_TYPE` IN ('int', 'bigint')
      AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
      AND actual.`IS_NULLABLE` = 'NO'
      AND actual.`COLUMN_KEY` = 'PRI'
      AND actual.`EXTRA` LIKE '%auto_increment%'
    ), 0),
    '/13'
  ) AS `details`
FROM `_ch_expected_table` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = 'id';

SELECT
  'columns.tenant_scope_contract' AS `check_name`,
  IF(
    COUNT(actual.`COLUMN_NAME`) = 12
    AND SUM(
      actual.`DATA_TYPE` = 'int'
      AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
      AND actual.`IS_NULLABLE` = 'NO'
      AND COALESCE(actual.`COLUMN_DEFAULT`, '') = '0'
    ) = 12,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'valid=',
    COALESCE(SUM(
      actual.`DATA_TYPE` = 'int'
      AND actual.`COLUMN_TYPE` LIKE '%unsigned%'
      AND actual.`IS_NULLABLE` = 'NO'
      AND COALESCE(actual.`COLUMN_DEFAULT`, '') = '0'
    ), 0),
    '/12'
  ) AS `details`
FROM `_ch_expected_table` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = 'tenant_id'
WHERE expected.`table_name` <> 'ch_tenant';

DROP TEMPORARY TABLE IF EXISTS `_ch_expected_column`;
CREATE TEMPORARY TABLE `_ch_expected_column` (
  `table_name` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `data_type` varchar(64) NOT NULL,
  `character_length` bigint(20) UNSIGNED NULL,
  `numeric_precision` smallint(5) UNSIGNED NULL,
  `numeric_scale` smallint(5) UNSIGNED NULL,
  `is_unsigned` tinyint(1) NOT NULL DEFAULT '-1',
  `is_nullable` char(3) NOT NULL,
  PRIMARY KEY (`table_name`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_expected_column`
  (`table_name`, `column_name`, `data_type`, `character_length`, `numeric_precision`, `numeric_scale`, `is_unsigned`, `is_nullable`)
VALUES
  ('ch_tenant', 'slug', 'varchar', 64, NULL, NULL, -1, 'NO'),
  ('ch_channel', 'code', 'varchar', 64, NULL, NULL, -1, 'NO'),
  ('ch_channel', 'entry_key', 'char', 32, NULL, NULL, -1, 'NO'),
  ('ch_tenant_member', 'uid', 'int', NULL, NULL, NULL, 1, 'NO'),
  ('ch_tenant_member', 'tier', 'tinyint', NULL, NULL, NULL, 1, 'NO'),
  ('ch_tenant_member', 'verification_status', 'tinyint', NULL, NULL, NULL, 1, 'NO'),
  ('ch_member_profile', 'expertise_json', 'text', NULL, NULL, NULL, -1, 'YES'),
  ('ch_member_profile', 'privacy_json', 'text', NULL, NULL, NULL, -1, 'YES'),
  ('ch_graduate_verification', 'apply_no', 'char', 32, NULL, NULL, -1, 'NO'),
  ('ch_graduate_verification', 'proof_json', 'text', NULL, NULL, NULL, -1, 'YES'),
  ('ch_audit_record', 'id', 'bigint', NULL, NULL, NULL, 1, 'NO'),
  ('ch_audit_record', 'business_type', 'varchar', 32, NULL, NULL, -1, 'NO'),
  ('ch_persona_role', 'code', 'varchar', 32, NULL, NULL, -1, 'NO'),
  ('ch_persona_role', 'materials_schema_json', 'text', NULL, NULL, NULL, -1, 'YES'),
  ('ch_role_application', 'apply_no', 'char', 32, NULL, NULL, -1, 'NO'),
  ('ch_role_application', 'materials_json', 'text', NULL, NULL, NULL, -1, 'YES'),
  ('ch_member_role', 'is_primary', 'tinyint', NULL, NULL, NULL, 1, 'NO'),
  ('ch_event', 'event_no', 'char', 32, NULL, NULL, -1, 'NO'),
  ('ch_event', 'detail', 'longtext', NULL, NULL, NULL, -1, 'YES'),
  ('ch_event', 'longitude', 'decimal', NULL, 10, 6, 0, 'NO'),
  ('ch_event', 'latitude', 'decimal', NULL, 10, 6, 0, 'NO'),
  ('ch_event_ticket', 'price', 'decimal', NULL, 12, 2, 1, 'NO'),
  ('ch_event_ticket', 'capacity', 'int', NULL, NULL, NULL, 1, 'NO'),
  ('ch_event_ticket', 'reserved_count', 'int', NULL, NULL, NULL, 1, 'NO'),
  ('ch_event_ticket', 'paid_count', 'int', NULL, NULL, NULL, 1, 'NO'),
  ('ch_event_registration', 'registration_no', 'char', 32, NULL, NULL, -1, 'NO'),
  ('ch_event_registration', 'order_no', 'varchar', 32, NULL, NULL, -1, 'NO'),
  ('ch_event_registration', 'amount', 'decimal', NULL, 12, 2, 1, 'NO'),
  ('ch_event_checkin', 'token_digest', 'char', 64, NULL, NULL, -1, 'NO'),
  ('ch_event_checkin', 'checked_time', 'int', NULL, NULL, NULL, 1, 'NO');

SELECT
  CONCAT('column.', expected.`table_name`, '.', expected.`column_name`) AS `check_name`,
  IF(
    actual.`COLUMN_NAME` IS NOT NULL
    AND actual.`DATA_TYPE` = expected.`data_type`
    AND (expected.`character_length` IS NULL OR actual.`CHARACTER_MAXIMUM_LENGTH` = expected.`character_length`)
    AND (expected.`numeric_precision` IS NULL OR actual.`NUMERIC_PRECISION` = expected.`numeric_precision`)
    AND (expected.`numeric_scale` IS NULL OR actual.`NUMERIC_SCALE` = expected.`numeric_scale`)
    AND (expected.`is_unsigned` = -1 OR (actual.`COLUMN_TYPE` LIKE '%unsigned%') = expected.`is_unsigned`)
    AND actual.`IS_NULLABLE` = expected.`is_nullable`,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'type=', COALESCE(actual.`COLUMN_TYPE`, 'missing'),
    '; nullable=', COALESCE(actual.`IS_NULLABLE`, 'missing')
  ) AS `details`
FROM `_ch_expected_column` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = expected.`column_name`
ORDER BY expected.`table_name`, expected.`column_name`;

DROP TEMPORARY TABLE IF EXISTS `_ch_expected_index`;
CREATE TEMPORARY TABLE `_ch_expected_index` (
  `table_name` varchar(64) NOT NULL,
  `index_name` varchar(64) NOT NULL,
  `non_unique` tinyint(1) UNSIGNED NOT NULL,
  `index_columns` varchar(512) NOT NULL,
  PRIMARY KEY (`table_name`, `index_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_expected_index` (`table_name`, `index_name`, `non_unique`, `index_columns`) VALUES
  ('ch_audit_record', 'PRIMARY', 0, 'id'),
  ('ch_audit_record', 'idx_audit_business', 1, 'tenant_id,business_type,business_id,id'),
  ('ch_audit_record', 'idx_audit_operator', 1, 'tenant_id,operator_type,operator_id,add_time'),
  ('ch_audit_record', 'idx_audit_time', 1, 'tenant_id,add_time'),
  ('ch_channel', 'PRIMARY', 0, 'id'),
  ('ch_channel', 'uk_channel_entry_key', 0, 'entry_key'),
  ('ch_channel', 'uk_channel_tenant_code', 0, 'tenant_id,code'),
  ('ch_channel', 'idx_channel_tenant_status', 1, 'tenant_id,status,is_del'),
  ('ch_event', 'PRIMARY', 0, 'id'),
  ('ch_event', 'uk_event_no', 0, 'tenant_id,event_no'),
  ('ch_event', 'idx_event_channel', 1, 'tenant_id,channel_id,status,start_time'),
  ('ch_event', 'idx_event_list', 1, 'tenant_id,status,is_del,start_time'),
  ('ch_event', 'idx_event_type', 1, 'tenant_id,event_type,status,start_time'),
  ('ch_event_checkin', 'PRIMARY', 0, 'id'),
  ('ch_event_checkin', 'uk_checkin_member', 0, 'tenant_id,event_id,uid'),
  ('ch_event_checkin', 'uk_checkin_registration', 0, 'tenant_id,registration_id'),
  ('ch_event_checkin', 'idx_checkin_event_time', 1, 'tenant_id,event_id,checked_time'),
  ('ch_event_checkin', 'idx_checkin_operator', 1, 'tenant_id,operator_admin_id,checked_time'),
  ('ch_event_registration', 'PRIMARY', 0, 'id'),
  ('ch_event_registration', 'uk_registration_member', 0, 'tenant_id,event_id,uid'),
  ('ch_event_registration', 'uk_registration_no', 0, 'tenant_id,registration_no'),
  ('ch_event_registration', 'idx_registration_member', 1, 'tenant_id,member_id,status,add_time'),
  ('ch_event_registration', 'idx_registration_order', 1, 'tenant_id,order_pk,order_no'),
  ('ch_event_registration', 'idx_registration_ticket', 1, 'tenant_id,ticket_id,status,reserve_expire_time'),
  ('ch_event_ticket', 'PRIMARY', 0, 'id'),
  ('ch_event_ticket', 'idx_ticket_event', 1, 'tenant_id,event_id,status,is_del,sort'),
  ('ch_event_ticket', 'idx_ticket_product', 1, 'tenant_id,product_id,product_attr_unique'),
  ('ch_event_ticket', 'idx_ticket_sale', 1, 'tenant_id,status,sale_start_time,sale_end_time'),
  ('ch_graduate_verification', 'PRIMARY', 0, 'id'),
  ('ch_graduate_verification', 'uk_verification_apply_no', 0, 'tenant_id,apply_no'),
  ('ch_graduate_verification', 'idx_verification_channel', 1, 'tenant_id,channel_id,status'),
  ('ch_graduate_verification', 'idx_verification_member', 1, 'tenant_id,member_id,status'),
  ('ch_graduate_verification', 'idx_verification_review', 1, 'tenant_id,status,submit_time'),
  ('ch_member_profile', 'PRIMARY', 0, 'id'),
  ('ch_member_profile', 'uk_profile_tenant_member', 0, 'tenant_id,member_id'),
  ('ch_member_profile', 'uk_profile_tenant_uid', 0, 'tenant_id,uid'),
  ('ch_member_profile', 'idx_profile_city', 1, 'tenant_id,city,is_del'),
  ('ch_member_profile', 'idx_profile_directory', 1, 'tenant_id,profile_status,is_del,graduation_year'),
  ('ch_member_profile', 'idx_profile_industry', 1, 'tenant_id,industry,is_del'),
  ('ch_member_role', 'PRIMARY', 0, 'id'),
  ('ch_member_role', 'uk_member_role', 0, 'tenant_id,member_id,role_id'),
  ('ch_member_role', 'idx_member_role_primary', 1, 'tenant_id,member_id,is_primary,status'),
  ('ch_member_role', 'idx_member_role_role', 1, 'tenant_id,role_id,status,expire_time'),
  ('ch_member_role', 'idx_member_role_uid', 1, 'tenant_id,uid,status'),
  ('ch_persona_role', 'PRIMARY', 0, 'id'),
  ('ch_persona_role', 'uk_persona_role_code', 0, 'tenant_id,code'),
  ('ch_persona_role', 'idx_persona_role_status', 1, 'tenant_id,status,is_del,sort'),
  ('ch_role_application', 'PRIMARY', 0, 'id'),
  ('ch_role_application', 'uk_role_application_no', 0, 'tenant_id,apply_no'),
  ('ch_role_application', 'idx_role_application_member', 1, 'tenant_id,member_id,role_id,status'),
  ('ch_role_application', 'idx_role_application_review', 1, 'tenant_id,status,submit_time'),
  ('ch_tenant', 'PRIMARY', 0, 'id'),
  ('ch_tenant', 'uk_tenant_slug', 0, 'slug'),
  ('ch_tenant', 'idx_tenant_status', 1, 'status,is_del'),
  ('ch_tenant_member', 'PRIMARY', 0, 'id'),
  ('ch_tenant_member', 'uk_tenant_member_uid', 0, 'tenant_id,uid'),
  ('ch_tenant_member', 'idx_member_referrer', 1, 'tenant_id,referrer_uid'),
  ('ch_tenant_member', 'idx_member_tenant_channel', 1, 'tenant_id,current_channel_id,status,is_del'),
  ('ch_tenant_member', 'idx_member_tenant_tier', 1, 'tenant_id,tier,verification_status,status');

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
FROM `_ch_expected_index` AS expected
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

DROP TEMPORARY TABLE IF EXISTS `_ch_expected_index`;
DROP TEMPORARY TABLE IF EXISTS `_ch_expected_column`;
DROP TEMPORARY TABLE IF EXISTS `_ch_expected_table`;
