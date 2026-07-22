-- Destructive local rollback for G1 member and verification hardening.
-- Every operation is conditional so an interrupted rollback can be rerun.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `ch_member_consent`;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `INDEX_NAME` = 'uk_verification_current_slot'),
  'ALTER TABLE `ch_graduate_verification` DROP INDEX `uk_verification_current_slot`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `INDEX_NAME` = 'idx_verification_previous'),
  'ALTER TABLE `ch_graduate_verification` DROP INDEX `idx_verification_previous`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `COLUMN_NAME` = 'current_slot'),
  'ALTER TABLE `ch_graduate_verification` DROP COLUMN `current_slot`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `COLUMN_NAME` = 'previous_application_id'),
  'ALTER TABLE `ch_graduate_verification` DROP COLUMN `previous_application_id`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_graduate_verification' AND `COLUMN_NAME` = 'graduation_time'),
  'ALTER TABLE `ch_graduate_verification` DROP COLUMN `graduation_time`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'avatar_object_key'),
  'ALTER TABLE `ch_member_profile` DROP COLUMN `avatar_object_key`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'main_business'),
  'ALTER TABLE `ch_member_profile` DROP COLUMN `main_business`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'resources_json'),
  'ALTER TABLE `ch_member_profile` DROP COLUMN `resources_json`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'needs_json'),
  'ALTER TABLE `ch_member_profile` DROP COLUMN `needs_json`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_member_profile' AND `COLUMN_NAME` = 'interests_json'),
  'ALTER TABLE `ch_member_profile` DROP COLUMN `interests_json`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'uk_member_invite_code'),
  'ALTER TABLE `ch_tenant_member` DROP INDEX `uk_member_invite_code`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'idx_member_verification_projection'),
  'ALTER TABLE `ch_tenant_member` DROP INDEX `idx_member_verification_projection`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'idx_member_term_projection'),
  'ALTER TABLE `ch_tenant_member` DROP INDEX `idx_member_term_projection`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `INDEX_NAME` = 'idx_member_attribution'),
  'ALTER TABLE `ch_tenant_member` DROP INDEX `idx_member_attribution`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'invite_code'),
  'ALTER TABLE `ch_tenant_member` DROP COLUMN `invite_code`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'attribution_locked_time'),
  'ALTER TABLE `ch_tenant_member` DROP COLUMN `attribution_locked_time`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'current_verification_id'),
  'ALTER TABLE `ch_tenant_member` DROP COLUMN `current_verification_id`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'current_membership_term_id'),
  'ALTER TABLE `ch_tenant_member` DROP COLUMN `current_membership_term_id`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

SET @ch_ddl = IF(
  EXISTS (SELECT 1 FROM information_schema.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'ch_tenant_member' AND `COLUMN_NAME` = 'membership_version'),
  'ALTER TABLE `ch_tenant_member` DROP COLUMN `membership_version`',
  'SELECT 1'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
