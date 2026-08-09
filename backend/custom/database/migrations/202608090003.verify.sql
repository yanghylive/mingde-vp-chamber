SELECT COUNT(*) AS expert_table FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ch_expert';
