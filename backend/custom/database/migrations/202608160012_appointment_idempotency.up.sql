-- 预约客户端幂等：ch_appointment 加 booking_key 唯一键
-- _ch_idempotent_add_column: 条件 DDL，列/键已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_appointment' AND column_name = 'booking_key') = 0,
  'ALTER TABLE ch_appointment ADD COLUMN booking_key VARCHAR(64) NOT NULL DEFAULT '''';',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'ch_appointment' AND index_name = 'uk_booking_key') = 0,
  'ALTER TABLE ch_appointment ADD UNIQUE KEY uk_booking_key (tenant_id, member_id, booking_key);',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
