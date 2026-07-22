-- Structural postconditions for G1 member and verification hardening.

SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS `_ch_member_expected_column`;
CREATE TEMPORARY TABLE `_ch_member_expected_column` (
  `table_name` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `data_type` varchar(64) NOT NULL,
  `is_nullable` char(3) NOT NULL,
  `is_unsigned` tinyint(1) NOT NULL DEFAULT '-1',
  `character_length` bigint(20) UNSIGNED NULL,
  `character_set` varchar(32) NULL,
  `collation_name` varchar(64) NULL,
  PRIMARY KEY (`table_name`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_member_expected_column`
  (`table_name`, `column_name`, `data_type`, `is_nullable`, `is_unsigned`, `character_length`, `character_set`, `collation_name`)
VALUES
  ('ch_tenant_member', 'invite_code', 'varchar', 'YES', -1, 32, 'ascii', 'ascii_bin'),
  ('ch_tenant_member', 'attribution_locked_time', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_tenant_member', 'current_verification_id', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_tenant_member', 'current_membership_term_id', 'bigint', 'NO', 1, NULL, NULL, NULL),
  ('ch_tenant_member', 'membership_version', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_profile', 'avatar_object_key', 'varchar', 'NO', -1, 255, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_member_profile', 'main_business', 'varchar', 'NO', -1, 500, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_member_profile', 'resources_json', 'text', 'YES', -1, 65535, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_member_profile', 'needs_json', 'text', 'YES', -1, 65535, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_member_profile', 'interests_json', 'text', 'YES', -1, 65535, 'utf8mb4', 'utf8mb4_unicode_ci'),
  ('ch_graduate_verification', 'current_slot', 'tinyint', 'YES', 1, NULL, NULL, NULL),
  ('ch_graduate_verification', 'previous_application_id', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_graduate_verification', 'graduation_time', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_consent', 'id', 'bigint', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_consent', 'consent_event_id', 'char', 'NO', -1, 64, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'document_code', 'varchar', 'NO', -1, 32, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'document_version', 'varchar', 'NO', -1, 64, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'content_sha256', 'char', 'NO', -1, 64, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'decision', 'varchar', 'NO', -1, 16, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'ip_hash', 'char', 'NO', -1, 64, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'user_agent_hash', 'char', 'NO', -1, 64, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'correlation_id', 'varchar', 'NO', -1, 128, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'tenant_id', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_consent', 'channel_id', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_consent', 'member_id', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_consent', 'uid', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_consent', 'source', 'varchar', 'NO', -1, 24, 'ascii', 'ascii_bin'),
  ('ch_member_consent', 'occurred_time', 'int', 'NO', 1, NULL, NULL, NULL),
  ('ch_member_consent', 'add_time', 'int', 'NO', 1, NULL, NULL, NULL);

SELECT
  CONCAT('column.', expected.`table_name`, '.', expected.`column_name`) AS `check_name`,
  IF(
    actual.`COLUMN_NAME` IS NOT NULL
    AND actual.`DATA_TYPE` = expected.`data_type`
    AND actual.`IS_NULLABLE` = expected.`is_nullable`
    AND (expected.`is_unsigned` = -1 OR (actual.`COLUMN_TYPE` LIKE '%unsigned%') = expected.`is_unsigned`)
    AND (expected.`character_length` IS NULL OR actual.`CHARACTER_MAXIMUM_LENGTH` = expected.`character_length`)
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
FROM `_ch_member_expected_column` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = expected.`column_name`
ORDER BY expected.`table_name`, expected.`column_name`;

DROP TEMPORARY TABLE IF EXISTS `_ch_member_expected_default`;
CREATE TEMPORARY TABLE `_ch_member_expected_default` (
  `table_name` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `expects_null` tinyint(1) UNSIGNED NOT NULL DEFAULT '0',
  `expected_default` varchar(255) NULL,
  PRIMARY KEY (`table_name`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_member_expected_default` (`table_name`, `column_name`, `expects_null`, `expected_default`) VALUES
  ('ch_tenant_member', 'invite_code', 1, NULL),
  ('ch_tenant_member', 'attribution_locked_time', 0, '0'),
  ('ch_tenant_member', 'current_verification_id', 0, '0'),
  ('ch_tenant_member', 'current_membership_term_id', 0, '0'),
  ('ch_tenant_member', 'membership_version', 0, '0'),
  ('ch_member_profile', 'avatar_object_key', 0, ''),
  ('ch_member_profile', 'main_business', 0, ''),
  ('ch_member_profile', 'resources_json', 1, NULL),
  ('ch_member_profile', 'needs_json', 1, NULL),
  ('ch_member_profile', 'interests_json', 1, NULL),
  ('ch_graduate_verification', 'current_slot', 1, NULL),
  ('ch_graduate_verification', 'previous_application_id', 0, '0'),
  ('ch_graduate_verification', 'graduation_time', 0, '0'),
  ('ch_member_consent', 'tenant_id', 0, '0'),
  ('ch_member_consent', 'channel_id', 0, '0'),
  ('ch_member_consent', 'member_id', 0, '0'),
  ('ch_member_consent', 'uid', 0, '0'),
  ('ch_member_consent', 'decision', 0, 'accepted'),
  ('ch_member_consent', 'source', 0, 'chamber_api'),
  ('ch_member_consent', 'ip_hash', 0, ''),
  ('ch_member_consent', 'user_agent_hash', 0, ''),
  ('ch_member_consent', 'correlation_id', 0, ''),
  ('ch_member_consent', 'occurred_time', 0, '0'),
  ('ch_member_consent', 'add_time', 0, '0');

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
FROM `_ch_member_expected_default` AS expected
LEFT JOIN information_schema.`COLUMNS` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = expected.`table_name`
 AND actual.`COLUMN_NAME` = expected.`column_name`
ORDER BY expected.`table_name`, expected.`column_name`;

SELECT
  'table.ch_member_consent' AS `check_name`,
  IF(
    actual.`TABLE_NAME` IS NOT NULL
    AND UPPER(actual.`ENGINE`) = 'INNODB'
    AND actual.`TABLE_COLLATION` = 'utf8mb4_unicode_ci'
    AND (
      SELECT COUNT(*) FROM information_schema.`COLUMNS`
      WHERE `TABLE_SCHEMA` = DATABASE()
        AND `TABLE_NAME` = 'ch_member_consent'
        AND FIND_IN_SET(
          `COLUMN_NAME`,
          'id,tenant_id,channel_id,member_id,uid,consent_event_id,document_code,document_version,content_sha256,decision,source,ip_hash,user_agent_hash,correlation_id,occurred_time,add_time'
        ) > 0
    ) = 16,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('engine=', COALESCE(actual.`ENGINE`, 'missing'), '; collation=', COALESCE(actual.`TABLE_COLLATION`, 'missing')) AS `details`
FROM (SELECT 1 AS one) AS seed
LEFT JOIN information_schema.`TABLES` AS actual
  ON actual.`TABLE_SCHEMA` = DATABASE()
 AND actual.`TABLE_NAME` = 'ch_member_consent';

DROP TEMPORARY TABLE IF EXISTS `_ch_member_expected_index`;
CREATE TEMPORARY TABLE `_ch_member_expected_index` (
  `table_name` varchar(64) NOT NULL,
  `index_name` varchar(64) NOT NULL,
  `non_unique` tinyint(1) UNSIGNED NOT NULL,
  `index_columns` varchar(512) NOT NULL,
  PRIMARY KEY (`table_name`, `index_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `_ch_member_expected_index` (`table_name`, `index_name`, `non_unique`, `index_columns`) VALUES
  ('ch_tenant_member', 'uk_member_invite_code', 0, 'tenant_id,invite_code'),
  ('ch_tenant_member', 'idx_member_verification_projection', 1, 'tenant_id,current_verification_id'),
  ('ch_tenant_member', 'idx_member_term_projection', 1, 'tenant_id,current_membership_term_id,tier'),
  ('ch_tenant_member', 'idx_member_attribution', 1, 'tenant_id,first_channel_id,attribution_locked_time'),
  ('ch_graduate_verification', 'uk_verification_current_slot', 0, 'tenant_id,member_id,current_slot'),
  ('ch_graduate_verification', 'idx_verification_previous', 1, 'tenant_id,previous_application_id'),
  ('ch_member_consent', 'PRIMARY', 0, 'id'),
  ('ch_member_consent', 'uk_member_consent_event', 0, 'tenant_id,consent_event_id'),
  ('ch_member_consent', 'idx_member_consent_member', 1, 'tenant_id,member_id,document_code,occurred_time'),
  ('ch_member_consent', 'idx_member_consent_uid', 1, 'tenant_id,uid,occurred_time');

SELECT
  CONCAT('index.', expected.`table_name`, '.', expected.`index_name`) AS `check_name`,
  IF(
    actual.`INDEX_NAME` IS NOT NULL
    AND actual.`NON_UNIQUE` = expected.`non_unique`
    AND actual.`index_columns` = expected.`index_columns`
    AND UPPER(actual.`INDEX_TYPE`) = 'BTREE',
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('columns=', COALESCE(actual.`index_columns`, 'missing'), '; non_unique=', COALESCE(actual.`NON_UNIQUE`, 'missing')) AS `details`
FROM `_ch_member_expected_index` AS expected
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
  'verification.current_slot_consistency' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('inconsistent_rows=', COUNT(*)) AS `details`
FROM `ch_graduate_verification`
WHERE `status` NOT IN (0, 1, 2, 3, 4, 5)
   OR (`status` IN (0, 1, 2) AND NOT (`current_slot` <=> 1))
   OR (`status` IN (3, 4, 5) AND `current_slot` IS NOT NULL);

SELECT
  'verification.single_current_application' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('duplicate_members=', COUNT(*)) AS `details`
FROM (
  SELECT `tenant_id`, `member_id`
  FROM `ch_graduate_verification`
  WHERE `current_slot` = 1
  GROUP BY `tenant_id`, `member_id`
  HAVING COUNT(*) > 1
) AS duplicate_current;

SELECT
  'verification.member_projection_matches' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('inconsistent_rows=', COUNT(*)) AS `details`
FROM (
  SELECT verification.`id`
  FROM `ch_graduate_verification` AS verification
  LEFT JOIN `ch_tenant_member` AS member
    ON member.`tenant_id` = verification.`tenant_id`
   AND member.`id` = verification.`member_id`
   AND member.`uid` = verification.`uid`
   AND member.`current_verification_id` = verification.`id`
   AND member.`verification_status` = verification.`status`
  WHERE verification.`current_slot` = 1
    AND member.`id` IS NULL
  UNION ALL
  SELECT member.`id`
  FROM `ch_tenant_member` AS member
  LEFT JOIN `ch_graduate_verification` AS verification
    ON verification.`tenant_id` = member.`tenant_id`
   AND verification.`member_id` = member.`id`
   AND verification.`uid` = member.`uid`
   AND verification.`id` = member.`current_verification_id`
   AND verification.`current_slot` = 1
  WHERE (member.`current_verification_id` <> 0 AND verification.`id` IS NULL)
     OR (member.`current_verification_id` = 0 AND member.`verification_status` IN (1, 2))
     OR member.`verification_status` NOT IN (0, 1, 2, 3, 4, 5)
) AS inconsistent_projection;

SELECT
  'consent.data_invariants' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('invalid_rows=', COUNT(*)) AS `details`
FROM `ch_member_consent`
WHERE `decision` NOT IN ('accepted', 'withdrawn')
   OR `source` = ''
   OR `consent_event_id` NOT REGEXP '^[a-f0-9]{64}$'
   OR `content_sha256` NOT REGEXP '^[a-f0-9]{64}$'
   OR (`ip_hash` <> '' AND `ip_hash` NOT REGEXP '^[a-f0-9]{64}$')
   OR (`user_agent_hash` <> '' AND `user_agent_hash` NOT REGEXP '^[a-f0-9]{64}$')
   OR `occurred_time` = 0
   OR `add_time` = 0;

SELECT
  'profile.json_invariants' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('invalid_rows=', COUNT(*)) AS `details`
FROM `ch_member_profile`
WHERE (`resources_json` IS NOT NULL AND NOT JSON_VALID(`resources_json`))
   OR (`needs_json` IS NOT NULL AND NOT JSON_VALID(`needs_json`))
   OR (`interests_json` IS NOT NULL AND NOT JSON_VALID(`interests_json`));

SELECT
  'consent.append_only_and_privacy' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('forbidden_columns=', COUNT(*)) AS `details`
FROM information_schema.`COLUMNS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'ch_member_consent'
  AND `COLUMN_NAME` IN ('update_time', 'is_del', 'deleted_at', 'delete_time', 'ip', 'client_ip', 'user_agent');

SELECT
  'consent.no_foreign_keys' AS `check_name`,
  IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `result`,
  CONCAT('foreign_keys=', COUNT(*)) AS `details`
FROM information_schema.`TABLE_CONSTRAINTS`
WHERE `CONSTRAINT_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'ch_member_consent'
  AND `CONSTRAINT_TYPE` = 'FOREIGN KEY';

DROP TEMPORARY TABLE IF EXISTS `_ch_member_expected_index`;
DROP TEMPORARY TABLE IF EXISTS `_ch_member_expected_default`;
DROP TEMPORARY TABLE IF EXISTS `_ch_member_expected_column`;
