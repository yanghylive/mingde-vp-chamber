-- 小薇 AI 助理 · 会员每日记录（隔离）— 行为数据与复盘对照
-- 对应需求：2.4 每日记录 daily/YYYY-MM-DD.json + 3.1 morning_challenge 回写 + 3.2 evening_review 回写 + 3.3 控速机制
-- morning_challenge JSON：{ "questions": ["…","…","…"], "micro_optimization": "…", "challenge": "…", "challenge_criteria": "…", "generated_at": int, "model": "kaypal-fast" }
-- responses JSON：{ "answers": ["…","…","…"], "challenge_result": "done|partial|none", "note": "…", "updated_at": int }
-- evening_review JSON：{ "content": "…", "reviewed_at": int, "model": "kaypal-fast" }

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_coaching_daily` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `record_date` char(10) NOT NULL DEFAULT '' COMMENT '记录日期 YYYY-MM-DD',
  `morning_challenge` json DEFAULT NULL COMMENT '早间 3问+微优化+挑战 存档（原文回写）',
  `responses` json DEFAULT NULL COMMENT '会员回传（回答+挑战结果）',
  `evening_review` json DEFAULT NULL COMMENT '晚间复盘存档',
  `respond_status` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '回传状态：0未回传 1最低门槛 2完整回传',
  `streak` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '连续回传天数（控速机制依据）',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '状态：1正常 0删除',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_coaching_daily_date` (`tenant_id`,`channel_id`,`member_id`,`uid`,`record_date`) USING BTREE,
  KEY `idx_coaching_daily_member` (`tenant_id`,`member_id`,`record_date`,`id`) USING BTREE,
  KEY `idx_coaching_daily_streak` (`tenant_id`,`member_id`,`respond_status`,`streak`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='小薇会员每日记录（早间挑战+回传+晚间复盘）';
