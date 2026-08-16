-- 档期软删除：物理删除改软删除，保留历史展示
ALTER TABLE ch_expert_slot
  ADD COLUMN deleted_at INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '软删除时间（0=未删）';
