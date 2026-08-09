-- AI 智能分身：知识库（L2 知识层，借鉴 TencentDB Agent Memory 分层记忆思路）
-- 大咖/管理员把文档、经验、方法论沉淀为知识条目，训练与对话时按相关性注入
CREATE TABLE IF NOT EXISTS `ch_expert_ai_knowledge` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `expert_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'ch_expert_ai.id',
  `member_id` int unsigned NOT NULL DEFAULT 0 COMMENT '大咖会员ID',
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '知识条目标题',
  `content` text COMMENT '知识内容（文档正文/要点）',
  `category` varchar(32) NOT NULL DEFAULT 'general' COMMENT 'general方法/general经验/industry行业/qa问答',
  `source` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual手动/train训练提炼',
  `source_file` varchar(255) NOT NULL DEFAULT '' COMMENT '来源文件名',
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_expert_status` (`tenant_id`, `expert_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI智能分身-知识库';
