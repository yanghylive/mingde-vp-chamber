-- 小薇 AI 助理 · DISCERN 教练文化宪法（租户级共享）— 价值观锚点
-- 对应需求：2.3 教练文化宪法 DISCERN（四特质·五原则·六信念，全平台共享）
-- 首版由平台配置（运营后台可改），全文待杨总提供后填充。

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_discern_config` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '配置ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `brand_name` varchar(32) NOT NULL DEFAULT '小薇' COMMENT '助理品牌名',
  `voice_style` varchar(255) NOT NULL DEFAULT '知性温柔、精简、有温度、带点可爱灵气' COMMENT '语气风格（可后台配置）',
  `four_traits` json DEFAULT NULL COMMENT '四特质 JSON',
  `five_principles` json DEFAULT NULL COMMENT '五原则 JSON',
  `six_beliefs` json DEFAULT NULL COMMENT '六信念 JSON',
  `push_time` varchar(8) NOT NULL DEFAULT '09:00' COMMENT '早间推送时间（默认09:00，可配置）',
  `evening_time` varchar(8) NOT NULL DEFAULT '21:00' COMMENT '晚间复盘时间',
  `streak_threshold` tinyint(1) UNSIGNED NOT NULL DEFAULT '3' COMMENT '控速阈值：连续N天零回传降门槛（默认3）',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '状态：1启用 0停用',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_discern_config_tenant` (`tenant_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小薇教练文化宪法 DISCERN（共享）';
