SELECT COUNT(*) AS knowledge_tbl FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ch_expert_ai_knowledge';
