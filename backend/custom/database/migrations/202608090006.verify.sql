-- 验证：ch_expert_ai_knowledge 存在 embedding/embed_dim 两列
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ch_expert_ai_knowledge'
  AND COLUMN_NAME IN ('embedding', 'embed_dim')
ORDER BY ORDINAL_POSITION;
