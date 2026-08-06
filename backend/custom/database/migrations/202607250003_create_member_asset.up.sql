-- Private member material registry for MEM-002.
-- Object bytes live outside public/; this table stores opaque object keys and integrity metadata only.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_member_asset` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '私有会员材料ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '上传渠道ID',
  `member_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_tenant_member.id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'CRMEB eb_user.uid',
  `purpose` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '受控用途，如graduate_verification_proof',
  `object_key` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '服务端生成的不可猜私有对象键，不是URL或物理路径',
  `storage_driver` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'local' COMMENT '私有存储驱动，首版仅local',
  `original_name` varchar(180) NOT NULL DEFAULT '' COMMENT '净化后的受控原文件名',
  `mime_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '服务端探测的实际MIME',
  `byte_size` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '文件字节数，最大10MiB',
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '文件内容SHA-256',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '状态：1待使用，2已使用，3不可用',
  `used_business_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '使用业务类型',
  `used_business_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '使用业务记录ID',
  `used_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '首次绑定业务时间',
  `last_access_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最近授权读取时间',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uk_member_asset_object_key` (`object_key`) USING BTREE,
  KEY `idx_member_asset_owner` (`tenant_id`,`channel_id`,`member_id`,`uid`,`status`,`id`) USING BTREE,
  KEY `idx_member_asset_purpose` (`tenant_id`,`channel_id`,`purpose`,`status`,`id`) USING BTREE,
  KEY `idx_member_asset_business` (`tenant_id`,`used_business_type`,`used_business_id`,`id`) USING BTREE,
  KEY `idx_member_asset_hash` (`tenant_id`,`sha256`,`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会员私有材料与对象存储元数据';
