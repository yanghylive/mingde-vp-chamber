-- 小薇 AI 助理 · 会员个人档案（隔离）— 个性化基调来源
-- 对应需求：2.1 会员个人档案 profile（姓名/称呼/生日/星座/八字命理/心理画像/人生阶段/事业主线）

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_coaching_profile` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '档案ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '姓名',
  `nickname` varchar(64) NOT NULL DEFAULT '' COMMENT '称呼（小薇用称呼唤起）',
  `birthday` varchar(24) NOT NULL DEFAULT '' COMMENT '生日 YYYY-MM-DD',
  `constellation` varchar(16) NOT NULL DEFAULT '' COMMENT '星座',
  `bazi` varchar(255) NOT NULL DEFAULT '' COMMENT '八字命理（如丙火身强火旺）',
  `psych_profile` text COMMENT '心理画像（核心驱动）JSON',
  `life_stage` varchar(128) NOT NULL DEFAULT '' COMMENT '当前人生阶段',
  `career_focus` varchar(255) NOT NULL DEFAULT '' COMMENT '事业主线（追问主航道）',
  `extra` json DEFAULT NULL COMMENT '扩展字段（灵活扩充）',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '状态：1启用 0停用',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_coaching_profile_owner` (`tenant_id`,`channel_id`,`member_id`,`uid`) USING BTREE,
  KEY `idx_coaching_profile_member` (`tenant_id`,`member_id`,`status`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小薇会员个人档案（认知教练个性化基调）';
