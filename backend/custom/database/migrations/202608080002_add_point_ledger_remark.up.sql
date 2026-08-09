-- Add remark column to ch_point_ledger for admin manual point adjustments (audit reason)
SET NAMES utf8mb4;

ALTER TABLE `ch_point_ledger`
  ADD COLUMN `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '调整原因/备注（后台手动调积分必填）' AFTER `source_id`;
