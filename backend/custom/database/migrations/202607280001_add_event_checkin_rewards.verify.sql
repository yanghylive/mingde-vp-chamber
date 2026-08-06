SET NAMES utf8mb4;

SELECT 'activity.event.checkin_reward_columns' AS check_name,
       IF(COUNT(*) = 2
          AND SUM(data_type = 'int') = 2
          AND SUM(is_nullable = 'NO') = 2
          AND SUM(column_default = '0') = 2,
          'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*), ', defaults=', SUM(column_default = '0')) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_event'
  AND column_name IN ('checkin_reward_points','checkin_reward_contribution');
