-- 档期软删除：物理删除改软删除，保留历史展示
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_expert_slot' AND column_name = 'deleted_at') = 0,
  'ALTER TABLE ch_expert_slot ADD COLUMN deleted_at INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''软删除时间（0=未删）'';',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
