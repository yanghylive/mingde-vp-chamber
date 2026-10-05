-- AI 智能分身知识库：向量化升级（kaypal-embedding，384 维）
-- 检索升级为 BM25 + 向量余弦 的 RRF 融合（借鉴 TencentDB Agent Memory 检索策略）
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_expert_ai_knowledge' AND column_name = 'embedding') = 0,
  'ALTER TABLE `ch_expert_ai_knowledge` ADD COLUMN `embedding` mediumtext NULL COMMENT ''384维向量(JSON数组,kaypal-embedding)'' AFTER `content`;',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_expert_ai_knowledge' AND column_name = 'embed_dim') = 0,
  'ALTER TABLE `ch_expert_ai_knowledge` ADD COLUMN `embed_dim` smallint unsigned NOT NULL DEFAULT 0 COMMENT ''向量维度(0=未向量化)'' AFTER `embedding`;',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
