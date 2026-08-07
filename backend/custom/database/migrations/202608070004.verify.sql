-- Verify: table exists and has expected columns
SELECT COUNT(*) AS col_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ch_discern_config';
