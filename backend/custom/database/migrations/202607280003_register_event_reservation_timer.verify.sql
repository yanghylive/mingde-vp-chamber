SET NAMES utf8mb4;

SELECT 'timer.event_reservation.runtime' AS check_name,
  IF(COUNT(*)=1 AND MAX(`is_open`)=1 AND MAX(`is_del`)=0
    AND MAX(`timeStr`)='0 */1 * * * *'
    AND MAX(JSON_UNQUOTE(`customCode`))='app()->make(\\app\\chamber\\jobs\\EventReservationRepairJob::class)->doJob(50);',
    'PASS','FAIL') AS result,
  CONCAT('matches=',COUNT(*)) AS details
FROM `eb_system_timer`
WHERE `name`='Chamber event reservation repair' AND `mark`='customTimer';
