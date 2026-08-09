-- Verify: table exists with expected columns
SELECT COUNT(*) AS col_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ch_chamber_ai_usage';
