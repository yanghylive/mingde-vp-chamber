-- Normalize legacy profile JSON before the strict MEM-003 reader is enabled.
-- Target: MySQL 5.7-8.0.

SET NAMES utf8mb4;

UPDATE `ch_member_profile` SET `resources_json` = '[]'
WHERE `resources_json` IS NOT NULL AND JSON_VALID(`resources_json`) = 0;
UPDATE `ch_member_profile` SET `needs_json` = '[]'
WHERE `needs_json` IS NOT NULL AND JSON_VALID(`needs_json`) = 0;
UPDATE `ch_member_profile` SET `interests_json` = '[]'
WHERE `interests_json` IS NOT NULL AND JSON_VALID(`interests_json`) = 0;
UPDATE `ch_member_profile` SET `expertise_json` = '[]'
WHERE `expertise_json` IS NOT NULL AND JSON_VALID(`expertise_json`) = 0;

UPDATE `ch_member_profile` SET `resources_json` = '[]'
WHERE `resources_json` IS NOT NULL AND JSON_TYPE(`resources_json`) <> 'ARRAY';
UPDATE `ch_member_profile` SET `needs_json` = '[]'
WHERE `needs_json` IS NOT NULL AND JSON_TYPE(`needs_json`) <> 'ARRAY';
UPDATE `ch_member_profile` SET `interests_json` = '[]'
WHERE `interests_json` IS NOT NULL AND JSON_TYPE(`interests_json`) <> 'ARRAY';
UPDATE `ch_member_profile` SET `expertise_json` = '[]'
WHERE `expertise_json` IS NOT NULL AND JSON_TYPE(`expertise_json`) <> 'ARRAY';

CREATE TEMPORARY TABLE `ch_profile_json_invalid` (
  `profile_id` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`profile_id`)
) ENGINE=MEMORY;

INSERT IGNORE INTO `ch_profile_json_invalid` (`profile_id`)
SELECT profile.`id`
FROM `ch_member_profile` AS profile
INNER JOIN (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
  UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
  UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
) AS numbers ON numbers.n < JSON_LENGTH(profile.`resources_json`)
WHERE JSON_LENGTH(profile.`resources_json`) > 30
   OR JSON_TYPE(JSON_EXTRACT(profile.`resources_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
   OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`resources_json`, CONCAT('$[', numbers.n, ']'))))) = 0
   OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`resources_json`, CONCAT('$[', numbers.n, ']')))) > 100;
UPDATE `ch_member_profile` AS profile
INNER JOIN `ch_profile_json_invalid` AS invalid ON invalid.`profile_id` = profile.`id`
SET profile.`resources_json` = '[]';
TRUNCATE TABLE `ch_profile_json_invalid`;

INSERT IGNORE INTO `ch_profile_json_invalid` (`profile_id`)
SELECT profile.`id`
FROM `ch_member_profile` AS profile
INNER JOIN (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
  UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
  UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
) AS numbers ON numbers.n < JSON_LENGTH(profile.`needs_json`)
WHERE JSON_LENGTH(profile.`needs_json`) > 30
   OR JSON_TYPE(JSON_EXTRACT(profile.`needs_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
   OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`needs_json`, CONCAT('$[', numbers.n, ']'))))) = 0
   OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`needs_json`, CONCAT('$[', numbers.n, ']')))) > 100;
UPDATE `ch_member_profile` AS profile
INNER JOIN `ch_profile_json_invalid` AS invalid ON invalid.`profile_id` = profile.`id`
SET profile.`needs_json` = '[]';
TRUNCATE TABLE `ch_profile_json_invalid`;

INSERT IGNORE INTO `ch_profile_json_invalid` (`profile_id`)
SELECT profile.`id`
FROM `ch_member_profile` AS profile
INNER JOIN (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
  UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
  UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
) AS numbers ON numbers.n < JSON_LENGTH(profile.`interests_json`)
WHERE JSON_LENGTH(profile.`interests_json`) > 30
   OR JSON_TYPE(JSON_EXTRACT(profile.`interests_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
   OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`interests_json`, CONCAT('$[', numbers.n, ']'))))) = 0
   OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`interests_json`, CONCAT('$[', numbers.n, ']')))) > 60;
UPDATE `ch_member_profile` AS profile
INNER JOIN `ch_profile_json_invalid` AS invalid ON invalid.`profile_id` = profile.`id`
SET profile.`interests_json` = '[]';
TRUNCATE TABLE `ch_profile_json_invalid`;

INSERT IGNORE INTO `ch_profile_json_invalid` (`profile_id`)
SELECT profile.`id`
FROM `ch_member_profile` AS profile
INNER JOIN (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
  UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
  UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
) AS numbers ON numbers.n < JSON_LENGTH(profile.`expertise_json`)
WHERE JSON_LENGTH(profile.`expertise_json`) > 30
   OR JSON_TYPE(JSON_EXTRACT(profile.`expertise_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
   OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`expertise_json`, CONCAT('$[', numbers.n, ']'))))) = 0
   OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`expertise_json`, CONCAT('$[', numbers.n, ']')))) > 60;
UPDATE `ch_member_profile` AS profile
INNER JOIN `ch_profile_json_invalid` AS invalid ON invalid.`profile_id` = profile.`id`
SET profile.`expertise_json` = '[]';

DROP TEMPORARY TABLE `ch_profile_json_invalid`;

UPDATE `ch_member_profile` SET `privacy_json` = '{}'
WHERE `privacy_json` IS NULL OR JSON_VALID(`privacy_json`) = 0;
UPDATE `ch_member_profile` SET `privacy_json` = '{}'
WHERE JSON_TYPE(`privacy_json`) <> 'OBJECT';

UPDATE `ch_member_profile`
SET `privacy_json` = JSON_OBJECT(
  'avatar_object_key', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.avatar_object_key')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.avatar_object_key')), 'private'),
  'real_name', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.real_name')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.real_name')), 'private'),
  'class_name', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.class_name')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.class_name')), 'private'),
  'graduation_year', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.graduation_year')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.graduation_year')), 'private'),
  'industry', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.industry')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.industry')), 'private'),
  'company_name', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.company_name')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.company_name')), 'private'),
  'job_title', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.job_title')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.job_title')), 'private'),
  'main_business', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.main_business')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.main_business')), 'private'),
  'province', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.province')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.province')), 'private'),
  'city', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.city')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.city')), 'private'),
  'bio', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.bio')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.bio')), 'private'),
  'resources', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.resources')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.resources')), 'private'),
  'needs', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.needs')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.needs')), 'private'),
  'interests', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.interests')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.interests')), 'private'),
  'expertise', IF(JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.expertise')) IN ('private','members','friends','public'), JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.expertise')), 'private')
);
