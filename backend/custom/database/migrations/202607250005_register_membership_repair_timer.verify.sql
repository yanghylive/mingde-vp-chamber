-- Structural and runtime contract checks for the G1-01D repair timer.

SET NAMES utf8mb4;

SET @ch_timer_name = 'Chamber membership order context repair';
SET @ch_timer_code = 'app()->make(\\app\\chamber\\jobs\\MembershipOrderContextRepairJob::class)->doJob(50);';

SELECT
  'timer.membership_order_context.unique' AS `check_name`,
  IF(COUNT(*) = 1, 'PASS', 'FAIL') AS `result`,
  CONCAT('matches=', COUNT(*)) AS `details`
FROM `eb_system_timer`
WHERE `name` = @ch_timer_name;

SELECT
  'timer.membership_order_context.schedule' AS `check_name`,
  IF(
    COUNT(*) = 1
    AND MAX(`mark`) = 'customTimer'
    AND MAX(`type`) = 2
    AND MAX(`minute`) = 1
    AND MAX(`is_open`) = 1
    AND MAX(`is_del`) = 0
    AND MAX(`timeStr`) = '0 */1 * * * *',
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('matches=', COUNT(*)) AS `details`
FROM `eb_system_timer`
WHERE `name` = @ch_timer_name;

SELECT
  'timer.membership_order_context.code' AS `check_name`,
  IF(
    COUNT(*) = 1
    AND MIN(JSON_VALID(`customCode`)) = 1
    AND MAX(JSON_UNQUOTE(`customCode`)) = @ch_timer_code,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT('matches=', COUNT(*)) AS `details`
FROM `eb_system_timer`
WHERE `name` = @ch_timer_name;

SET @ch_timer_name = NULL;
SET @ch_timer_code = NULL;
