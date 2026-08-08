-- Verify: seed 数据存在且 extra 字段完整
SELECT COUNT(*) AS discern_rows FROM `ch_discern_config` WHERE `tenant_id` = 1;
SELECT COUNT(*) AS col_exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ch_discern_config' AND COLUMN_NAME = 'extra';
