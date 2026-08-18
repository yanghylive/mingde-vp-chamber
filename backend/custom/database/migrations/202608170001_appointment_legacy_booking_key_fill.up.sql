-- 历史预约 booking_key 回填（幂等迁移历史数据安全）
--
-- 背景：202608160012 建唯一键 uk_booking_key (tenant_id, member_id, booking_key) 时，
-- 旧数据 booking_key 全为 DEFAULT ''，同一会员存在多条历史预约时唯一键冲突 → 迁移失败。
-- 该迁移对已应用 202608160012 的环境做回填，确保唯一键不变量成立。
-- （未来 reset 重新放时，202608160012 新版 up.sql 会在建键前先回填，本迁移幂等跳过。）
UPDATE ch_appointment
  SET booking_key = CONCAT('legacy:', LEFT(SHA2(CONCAT(id, ':', tenant_id), 256), 56))
  WHERE booking_key = '';
