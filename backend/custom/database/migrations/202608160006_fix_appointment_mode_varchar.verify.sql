-- 校验 ch_appointment.mode 已改为 varchar(20)
SELECT IF(
  DATA_TYPE = 'varchar' AND CHARACTER_MAXIMUM_LENGTH = 20,
  'OK: ch_appointment.mode is varchar(20)',
  CONCAT('FAIL: ch_appointment.mode = ', DATA_TYPE, '(', CHARACTER_MAXIMUM_LENGTH, ')')
) AS `result`
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'crmeb' AND TABLE_NAME = 'ch_appointment' AND COLUMN_NAME = 'mode';
