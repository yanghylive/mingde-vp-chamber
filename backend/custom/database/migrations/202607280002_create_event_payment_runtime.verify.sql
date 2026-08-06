SET NAMES utf8mb4;

SELECT 'event_payment_runtime.structure' AS check_name,
  IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_point_account' AND COLUMN_NAME='frozen_balance') = 1
    AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_point_hold') = 1
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_point_hold' AND INDEX_NAME IN ('uk_point_hold_registration','uk_point_hold_key','idx_point_hold_expiry','idx_point_hold_account')) = 11
    AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_event_registration' AND INDEX_NAME='idx_registration_expiry') = 3,
    'PASS','FAIL'
  ) AS result,
  'point account freeze, hold facts and expiry index' AS details;
