-- 回滚：mode 恢复为 tinyint（注意：会丢失字符串语义，仅在回滚场景使用）
ALTER TABLE ch_appointment MODIFY COLUMN mode tinyint NOT NULL DEFAULT 0 COMMENT '预约方式';
