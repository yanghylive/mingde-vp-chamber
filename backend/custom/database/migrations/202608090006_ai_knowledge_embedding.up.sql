-- AI 智能分身知识库：向量化升级（kaypal-embedding，384 维）
-- 检索升级为 BM25 + 向量余弦 的 RRF 融合（借鉴 TencentDB Agent Memory 检索策略）
ALTER TABLE `ch_expert_ai_knowledge`
  ADD COLUMN `embedding` mediumtext NULL COMMENT '384维向量(JSON数组,kaypal-embedding)' AFTER `content`,
  ADD COLUMN `embed_dim` smallint unsigned NOT NULL DEFAULT 0 COMMENT '向量维度(0=未向量化)' AFTER `embedding`;
