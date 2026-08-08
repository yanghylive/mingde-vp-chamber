-- Rollback: 删除 seed 数据 + 回滚 extra 字段
SET NAMES utf8mb4;
DELETE FROM `ch_discern_config` WHERE `tenant_id` = 1;
ALTER TABLE `ch_discern_config` DROP COLUMN `extra`;
