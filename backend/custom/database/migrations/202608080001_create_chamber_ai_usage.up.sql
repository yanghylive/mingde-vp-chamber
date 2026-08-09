-- AI 调用 usage 计费流水（服务端权威）—— 对应 P2-9
-- 来源：CoachingService 每次调用 Kaypal 网关后记录（服务端解析网关响应 usage，不信任客户端）
-- 用途：AI 成本核算 / 对账 / 计量分析（配合 CoachingService 每日生成限流）

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_chamber_ai_usage` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '流水ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `scene` varchar(32) NOT NULL DEFAULT '' COMMENT '场景：morning|evening',
  `model` varchar(64) NOT NULL DEFAULT '' COMMENT '模型名 kaypal-fast 等',
  `prompt_tokens` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '输入 token 数（网关权威）',
  `completion_tokens` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '输出 token 数（网关权威）',
  `total_tokens` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '合计 token 数',
  `fallback_used` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '是否走了兜底模板（网关调用失败降级）',
  `latency_ms` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '网关调用耗时（毫秒）',
  `request_id` varchar(64) NOT NULL DEFAULT '' COMMENT '网关上下文 request_id（排查/对账关联）',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '记录时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_usage_tenant_time` (`tenant_id`,`add_time`) USING BTREE,
  KEY `idx_usage_member` (`tenant_id`,`member_id`,`add_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI 调用 usage 计费流水（服务端权威）';
