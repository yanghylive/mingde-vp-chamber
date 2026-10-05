-- 统一身份映射层第一步：ch_expert 增加 member_id，关联会员主键
-- 0 = 未关联会员（独立资料大咖，如当前阿曲）；>0 = 已关联 ch_tenant_member.id
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_expert' AND column_name = 'member_id') = 0,
  'ALTER TABLE ch_expert ADD COLUMN member_id INT NOT NULL DEFAULT 0 COMMENT ''关联会员 ch_tenant_member.id（0=未关联）'' AFTER id;',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
