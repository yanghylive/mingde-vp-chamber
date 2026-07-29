SET NAMES utf8mb4;

SET @ch_timer_name = 'Chamber event reservation repair';
SET @ch_timer_code = 'app()->make(\\app\\chamber\\jobs\\EventReservationRepairJob::class)->doJob(50);';

START TRANSACTION;
DELETE FROM `eb_system_timer` WHERE `name` = @ch_timer_name;
INSERT INTO `eb_system_timer` (
  `name`,`mark`,`content`,`type`,`month`,`week`,`day`,`hour`,`minute`,`second`,
  `last_execution_time`,`next_execution_time`,`add_time`,`update_time`,`is_del`,`is_open`,`customCode`,`timeStr`
) VALUES (
  @ch_timer_name,'customTimer','Release expired Chamber event reservations and retry payment projection',
  2,0,0,0,0,1,0,0,UNIX_TIMESTAMP()+60,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),0,1,
  JSON_QUOTE(@ch_timer_code),'0 */1 * * * *'
);
COMMIT;

SET @ch_timer_name = NULL;
SET @ch_timer_code = NULL;
