-- AI 智能分身：配置 / 记忆 / 对话 三张表（大咖 AI 分身训练体系）
-- 大咖 = ch_tenant_member 中 mentor/coach/industry_leader 角色的会员

-- 1) 分身配置（一会员一条）
CREATE TABLE IF NOT EXISTS `ch_expert_ai` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `member_id` int unsigned NOT NULL DEFAULT 0 COMMENT '大咖会员ID（ch_tenant_member.id）',
  `persona_name` varchar(64) NOT NULL DEFAULT '' COMMENT '分身昵称',
  `persona_role` varchar(128) NOT NULL DEFAULT '' COMMENT '身份定位（如：私募投资导师）',
  `voice_style` varchar(128) NOT NULL DEFAULT '' COMMENT '语气风格（如：沉稳老练、一针见血）',
  `catchphrases` text COMMENT '口头禅（JSON 数组）',
  `knowledge_base` text COMMENT '知识库要点（AI 训练总结）',
  `training_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '0未训练 1训练中 2已就绪',
  `training_progress` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '训练进度 0-100',
  `chat_points_cost` int unsigned NOT NULL DEFAULT 20 COMMENT '会员对话积分价',
  `chat_count` int unsigned NOT NULL DEFAULT 0 COMMENT '累计会员对话次数',
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `is_del` tinyint unsigned NOT NULL DEFAULT 0,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_member` (`tenant_id`, `member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI智能分身-配置';

-- 2) 记忆条目（对话训练自动提炼，可人工删除）
CREATE TABLE IF NOT EXISTS `ch_expert_ai_memory` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `expert_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'ch_expert_ai.id',
  `member_id` int unsigned NOT NULL DEFAULT 0,
  `category` varchar(32) NOT NULL DEFAULT 'fact' COMMENT 'identity身份/style风格/fact事实/knowledge知识点/preference偏好',
  `content` text COMMENT '记忆内容',
  `source` varchar(16) NOT NULL DEFAULT 'train' COMMENT 'train训练/manual手动',
  `source_chat_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源对话ID',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1有效 0已删',
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_expert_status` (`tenant_id`, `expert_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI智能分身-记忆';

-- 3) 对话记录（训练对话 + 会员对话）
CREATE TABLE IF NOT EXISTS `ch_expert_ai_chat` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `expert_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'ch_expert_ai.id',
  `member_id` int unsigned NOT NULL DEFAULT 0 COMMENT '大咖会员ID',
  `chat_type` varchar(16) NOT NULL DEFAULT 'member' COMMENT 'train训练/member会员对话',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '对话人（训练=大咖自己；对话=会员）',
  `messages` text COMMENT '对话历史（JSON: [{role,content}]）',
  `message_count` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_expert_type` (`tenant_id`, `expert_id`, `chat_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI智能分身-对话';
