-- 预约时段快照：历史预约展示不依赖 join 时段表
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_appointment' AND column_name = 'slot_start_time') = 0,
  'ALTER TABLE ch_appointment ADD COLUMN slot_start_time INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''时段开始快照'';',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_appointment' AND column_name = 'slot_end_time') = 0,
  'ALTER TABLE ch_appointment ADD COLUMN slot_end_time INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''时段结束快照'';',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_appointment' AND column_name = 'location') = 0,
  'ALTER TABLE ch_appointment ADD COLUMN location TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''时段形式快照（0线上/1线下）'';',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
