SET NAMES utf8mb4;

SELECT 'profile_json.list_containers' AS `check_name`,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `status`, COUNT(*) AS `violations`
FROM `ch_member_profile`
WHERE (`resources_json` IS NOT NULL AND (JSON_VALID(`resources_json`) = 0 OR JSON_TYPE(`resources_json`) <> 'ARRAY'))
   OR (`needs_json` IS NOT NULL AND (JSON_VALID(`needs_json`) = 0 OR JSON_TYPE(`needs_json`) <> 'ARRAY'))
   OR (`interests_json` IS NOT NULL AND (JSON_VALID(`interests_json`) = 0 OR JSON_TYPE(`interests_json`) <> 'ARRAY'))
   OR (`expertise_json` IS NOT NULL AND (JSON_VALID(`expertise_json`) = 0 OR JSON_TYPE(`expertise_json`) <> 'ARRAY'));

SELECT 'profile_json.list_lengths' AS `check_name`,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `status`, COUNT(*) AS `violations`
FROM `ch_member_profile`
WHERE COALESCE(JSON_LENGTH(`resources_json`), 0) > 30
   OR COALESCE(JSON_LENGTH(`needs_json`), 0) > 30
   OR COALESCE(JSON_LENGTH(`interests_json`), 0) > 30
   OR COALESCE(JSON_LENGTH(`expertise_json`), 0) > 30;

SELECT 'profile_json.list_items' AS `check_name`,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `status`, COUNT(*) AS `violations`
FROM (
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
  WHERE JSON_TYPE(JSON_EXTRACT(profile.`resources_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
     OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`resources_json`, CONCAT('$[', numbers.n, ']'))))) = 0
     OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`resources_json`, CONCAT('$[', numbers.n, ']')))) > 100
  UNION ALL
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
  WHERE JSON_TYPE(JSON_EXTRACT(profile.`needs_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
     OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`needs_json`, CONCAT('$[', numbers.n, ']'))))) = 0
     OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`needs_json`, CONCAT('$[', numbers.n, ']')))) > 100
  UNION ALL
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
  WHERE JSON_TYPE(JSON_EXTRACT(profile.`interests_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
     OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`interests_json`, CONCAT('$[', numbers.n, ']'))))) = 0
     OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`interests_json`, CONCAT('$[', numbers.n, ']')))) > 60
  UNION ALL
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
  WHERE JSON_TYPE(JSON_EXTRACT(profile.`expertise_json`, CONCAT('$[', numbers.n, ']'))) <> 'STRING'
     OR CHAR_LENGTH(TRIM(JSON_UNQUOTE(JSON_EXTRACT(profile.`expertise_json`, CONCAT('$[', numbers.n, ']'))))) = 0
     OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(profile.`expertise_json`, CONCAT('$[', numbers.n, ']')))) > 60
) AS invalid_items;

SELECT 'profile_json.privacy_object' AS `check_name`,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `status`, COUNT(*) AS `violations`
FROM `ch_member_profile`
WHERE `privacy_json` IS NULL OR JSON_VALID(`privacy_json`) = 0
   OR JSON_TYPE(`privacy_json`) <> 'OBJECT' OR JSON_LENGTH(`privacy_json`) <> 15
   OR JSON_CONTAINS_PATH(
        `privacy_json`, 'all',
        '$.avatar_object_key', '$.real_name', '$.class_name', '$.graduation_year',
        '$.industry', '$.company_name', '$.job_title', '$.main_business',
        '$.province', '$.city', '$.bio', '$.resources', '$.needs', '$.interests', '$.expertise'
      ) <> 1;

SELECT 'profile_json.privacy_scopes' AS `check_name`,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS `status`, COUNT(*) AS `violations`
FROM `ch_member_profile`
WHERE JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.avatar_object_key')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.real_name')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.class_name')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.graduation_year')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.industry')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.company_name')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.job_title')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.main_business')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.province')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.city')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.bio')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.resources')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.needs')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.interests')) NOT IN ('private','members','friends','public')
   OR JSON_UNQUOTE(JSON_EXTRACT(`privacy_json`, '$.expertise')) NOT IN ('private','members','friends','public');
