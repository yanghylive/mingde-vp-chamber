-- 候补队列：票种开关 + 候补表（席位释放后按时间顺序自动转正）
-- 语义：候补期间不冻结积分、不建订单；转正时为会员短时锁定一个席位并给支付窗口，
--       窗口内完成报名即转 registration，超时释放席位并顺延下一位。
-- 幂等：ADD COLUMN 使用 IF NOT EXISTS，允许在已加过列的库上重跑。
SET @ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_event_ticket' AND column_name = 'waitlist_enabled') = 0,
  'ALTER TABLE `ch_event_ticket` ADD COLUMN `waitlist_enabled` tinyint(1) unsigned NOT NULL DEFAULT ''0'' COMMENT ''满员时是否进入候补队列'' AFTER `status`',
  'DO 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `ch_event_waitlist` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '候补ID',
  `tenant_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '租户ID',
  `event_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '活动ID',
  `ticket_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '票种ID',
  `member_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `status` varchar(16) character set ascii collate ascii_bin NOT NULL DEFAULT 'waiting' COMMENT 'waiting/promoted/expired/cancelled/converted',
  `promote_expire_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '转正支付窗口截止时间',
  `promoted_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '转正时间',
  `promote_seq` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '第几次被转正，用于通知去重',
  `registration_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '转正后生成的报名ID',
  `notified_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '转正通知发送时间',
  `add_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '加入时间（候补排序依据）',
  `update_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_waitlist_member` (`tenant_id`,`ticket_id`,`member_id`) USING BTREE,
  KEY `idx_waitlist_promote` (`tenant_id`,`ticket_id`,`status`,`id`) USING BTREE,
  KEY `idx_waitlist_expire` (`tenant_id`,`status`,`promote_expire_time`,`id`) USING BTREE,
  KEY `idx_waitlist_member` (`tenant_id`,`member_id`,`status`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动候补队列与转正窗口';
