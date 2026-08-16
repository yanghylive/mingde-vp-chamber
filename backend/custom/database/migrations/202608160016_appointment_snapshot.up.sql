-- 预约时段快照：历史预约展示不依赖 join 时段表
ALTER TABLE ch_appointment
  ADD COLUMN slot_start_time INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '时段开始快照',
  ADD COLUMN slot_end_time INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '时段结束快照',
  ADD COLUMN location TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '时段形式快照（0线上/1线下）';
