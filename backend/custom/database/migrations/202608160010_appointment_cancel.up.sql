-- 预约纯积分闭环：ch_appointment 加 cancel_time（取消时间，0=未取消）
ALTER TABLE ch_appointment
    ADD COLUMN cancel_time INT NOT NULL DEFAULT 0 COMMENT '取消时间（0=未取消）' AFTER created_at;
