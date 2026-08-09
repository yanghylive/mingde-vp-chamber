-- Rollback: drop remark column from ch_point_ledger
SET NAMES utf8mb4;
ALTER TABLE `ch_point_ledger` DROP COLUMN `remark`;
