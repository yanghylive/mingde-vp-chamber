-- Append-only projection facts for trusted event-registration refunds.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_event_registration_effect` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `registration_id` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `order_context_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `effect_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effect_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `completion_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effect_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `refund_delta` decimal(16,2) UNSIGNED NOT NULL DEFAULT '0.00',
  `cumulative_refunded_amount` decimal(16,2) UNSIGNED NOT NULL DEFAULT '0.00',
  `points_delta` int(11) NOT NULL DEFAULT '0',
  `seat_delta` smallint(6) NOT NULL DEFAULT '0',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_event_registration_effect` (`tenant_id`,`effect_key`) USING BTREE,
  KEY `idx_event_registration_effect_registration` (`tenant_id`,`registration_id`,`id`) USING BTREE,
  KEY `idx_event_registration_effect_context` (`tenant_id`,`order_context_id`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='活动报名支付退款追加效果';
