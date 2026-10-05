-- 格林迈「成长改变」域：教练每课填报（体态三视图 / 性格标签 / 里程碑 / 见证语）
-- 解锁 M10 成长改变：体态前后对比图 + 性格标签对比卡（续费钩子主力）。
-- 多租户：tenant_id + channel_id；add_time/update_time/record_date 为秒级时间戳。
-- 依赖：ch_tenant_member（学员）、ch_course_session（课次）、ch_coach（教练）。

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ch_coach_session_report` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '填报记录ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `channel_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道ID',
  `student_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '学员 ch_tenant_member.id',
  `session_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '课次 ch_course_session.id',
  `coach_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '填报教练 ch_coach.id',
  `comment` varchar(500) NOT NULL DEFAULT '' COMMENT '教练见证语',
  `record_date` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '本次记录对应训练日期（秒级），作为基线/当前排序键',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_report_student` (`tenant_id`,`student_id`,`record_date`),
  KEY `idx_report_session` (`tenant_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='教练每课填报主表';

CREATE TABLE IF NOT EXISTS `ch_coach_session_report_photo` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '体态照片ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `report_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_coach_session_report.id',
  `photo_type` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '视图 front正面 back背面 side侧面',
  `url` varchar(512) NOT NULL DEFAULT '' COMMENT '照片URL（CDN）',
  `sort_order` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '排序',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_photo_report` (`tenant_id`,`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='教练每课填报-体态三视图照片';

CREATE TABLE IF NOT EXISTS `ch_coach_session_report_tag` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '性格标签ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `report_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_coach_session_report.id',
  `tag_key` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '标签键（稳定标识）',
  `tag_label` varchar(48) NOT NULL DEFAULT '' COMMENT '标签展示名',
  `score` tinyint(4) NOT NULL DEFAULT '0' COMMENT '分值（0-100，具体口径待张洪艳定档 Issue #7）',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_tag_report` (`tenant_id`,`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='教练每课填报-性格标签（每次填报快照）';

CREATE TABLE IF NOT EXISTS `ch_coach_session_report_milestone` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '里程碑ID',
  `tenant_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '租户ID',
  `report_id` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'ch_coach_session_report.id',
  `milestone_key` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '里程碑键',
  `milestone_label` varchar(64) NOT NULL DEFAULT '' COMMENT '里程碑展示名',
  `achieved` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '0未达成 1已达成',
  `add_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idx_milestone_report` (`tenant_id`,`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='教练每课填报-里程碑标记';
