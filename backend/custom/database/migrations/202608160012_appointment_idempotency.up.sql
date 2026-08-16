-- 预约客户端幂等：ch_appointment 加 booking_key 唯一键
ALTER TABLE ch_appointment
  ADD COLUMN booking_key VARCHAR(64) NOT NULL DEFAULT '',
  ADD UNIQUE KEY uk_booking_key (tenant_id, member_id, booking_key);
