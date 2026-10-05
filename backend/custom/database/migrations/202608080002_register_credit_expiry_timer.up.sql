SET NAMES utf8mb4;

SET @ch_timer_name = 'Chamber course credit expiry';
SET @ch_timer_code = 'app()->make(\\app\\chamber\\jobs\\CreditExpiryJob::class)->doJob(200);';

START TRANSACTION;
DELETE FROM `eb_system_timer` WHERE `name` = @ch_timer_name;
INSERT INTO `eb_system_timer` (
  `name`,`mark`,`content`,`type`,`month`,`week`,`day`,`hour`,`minute`,`second`,
  `last_execution_time`,`next_execution_time`,`add_time`,`update_time`,`is_del`,`is_open`,`customCode`,`timeStr`
) VALUES (
  @ch_timer_name,'customTimer','作废到期未用的课程课时授予并冲减账户余额',
  2,0,0,0,0,0,0,0,UNIX_TIMESTAMP()+60,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),0,1,
  JSON_QUOTE(@ch_timer_code),'0 */6 * * * *'
);
COMMIT;

SET @ch_timer_name = NULL;
SET @ch_timer_code = NULL;
