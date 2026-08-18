-- 回滚：legacy: 前缀的 booking_key 恢复为空串（显式回滚操作，用户自行承担唯一键冲突风险）
UPDATE ch_appointment
  SET booking_key = ''
  WHERE booking_key LIKE 'legacy:%';
