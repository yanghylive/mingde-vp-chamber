-- 前端行为埋点表（P0 数据可观测）
CREATE TABLE IF NOT EXISTS `ch_client_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `uid` int unsigned NOT NULL DEFAULT 0 COMMENT '0=匿名',
  `event` varchar(64) NOT NULL DEFAULT '',
  `page` varchar(120) NOT NULL DEFAULT '',
  `data` text COMMENT '事件附加数据(JSON)',
  `add_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_event_time` (`tenant_id`, `event`, `add_time`),
  KEY `idx_uid_time` (`uid`, `add_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前端行为埋点';
