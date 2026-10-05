-- Add remark column to ch_point_ledger for admin manual point adjustments (audit reason)
SET NAMES utf8mb4;

-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_point_ledger' AND column_name = 'remark') = 0,
  'ALTER TABLE `ch_point_ledger`
  ADD COLUMN `remark` varchar(255) NOT NULL DEFAULT '''' COMMENT ''调整原因/备注（后台手动调积分必填）'' AFTER `source_id`;',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

