-- 小薇 AI 助理 · 四大维度 config（隔离）— 每日追问与复盘分类锚点
-- 对应需求：2.2 三大支柱/四大维度（事业/家庭/健康/成长，前三为三大支柱）
-- pillars JSON 结构：
-- { "career": { "goals": { "weekly": "…", "monthly": "…" }, "metrics": [...], "categories": [...], "status": "…" },
--   "family": {...}, "health": {...}, "growth": {...} }

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_coaching_config` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '配置ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `pillars` json NOT NULL COMMENT '四大维度 JSON（事业/家庭/健康/成长）',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '状态：1启用 0停用',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_coaching_config_owner` (`tenant_id`,`channel_id`,`member_id`,`uid`) USING BTREE,
  KEY `idx_coaching_config_member` (`tenant_id`,`member_id`,`status`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小薇四大维度配置（分类锚点）';
