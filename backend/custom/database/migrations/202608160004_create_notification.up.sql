-- ch_event_notification 建表迁移（补历史欠账：该表原为手动创建，无 CREATE 迁移）
CREATE TABLE IF NOT EXISTS `ch_event_notification` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=广播全体，>0=指定会员',
  `event_id` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联活动，0=系统通知',
  `title` varchar(200) NOT NULL DEFAULT '',
  `body` varchar(1000) NOT NULL DEFAULT '',
  `read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '单行已读标记（已读隔离由 ch_notification_read 承担）',
  `created_at` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_member` (`tenant_id`, `member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知（活动通知 + 系统广播）';
