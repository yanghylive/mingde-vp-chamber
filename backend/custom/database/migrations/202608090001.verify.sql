-- Verify: ch_exchange_product table exists
SELECT COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ch_exchange_product';
