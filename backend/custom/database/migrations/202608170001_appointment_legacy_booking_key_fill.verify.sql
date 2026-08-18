-- 回填后不应残留空 booking_key（唯一键不变量成立的前提）
SET NAMES utf8mb4;

SELECT 'ch_appointment.booking_key.filled' AS check_name,
       IF(COUNT(*) = 0, 'PASS', 'FAIL') AS result,
       CONCAT('empty_booking_key=', COUNT(*)) AS details
FROM ch_appointment
WHERE booking_key = '';
