-- 前端错误表（S5 错误上报落库，保留 30 天）
CREATE TABLE IF NOT EXISTS `ch_client_error` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `uid` int unsigned NOT NULL DEFAULT 0,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `page` varchar(120) NOT NULL DEFAULT '',
  `platform` varchar(40) NOT NULL DEFAULT '',
  `msg` varchar(300) NOT NULL DEFAULT '',
  `stack` text,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_time` (`tenant_id`, `add_time`),
  KEY `idx_page_time` (`page`, `add_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前端错误日志(脱敏,保留30天)';
