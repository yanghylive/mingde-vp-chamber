-- Verify: remark column exists on ch_point_ledger
SELECT COUNT(*) AS col_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ch_point_ledger' AND COLUMN_NAME = 'remark';
