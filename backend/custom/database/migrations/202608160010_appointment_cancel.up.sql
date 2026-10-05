-- 预约纯积分闭环：ch_appointment 加 cancel_time（取消时间，0=未取消）
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_appointment' AND column_name = 'cancel_time') = 0,
  'ALTER TABLE ch_appointment ADD COLUMN cancel_time INT NOT NULL DEFAULT 0 COMMENT ''取消时间（0=未取消）'' AFTER created_at;',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
