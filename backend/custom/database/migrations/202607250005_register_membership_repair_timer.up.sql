-- Register the G1-01D order-context repair loop in CRMEB's native timer runtime.

SET NAMES utf8mb4;

SET @ch_timer_name = 'Chamber membership order context repair';
SET @ch_timer_code = 'app()->make(\\app\\chamber\\jobs\\MembershipOrderContextRepairJob::class)->doJob(50);';

START TRANSACTION;

DELETE FROM `eb_system_timer`
WHERE `name` = @ch_timer_name;

INSERT INTO `eb_system_timer` (
  `name`, `mark`, `content`, `type`, `month`, `week`, `day`, `hour`, `minute`, `second`,
  `last_execution_time`, `next_execution_time`, `add_time`, `update_time`, `is_del`, `is_open`,
  `customCode`, `timeStr`
) VALUES (
  @ch_timer_name,
  'customTimer',
  'Every minute, bind committed CRMEB membership orders to pending Chamber contexts',
  2, 0, 0, 0, 0, 1, 0,
  0, UNIX_TIMESTAMP() + 60, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 1,
  JSON_QUOTE(@ch_timer_code),
  '0 */1 * * * *'
);

COMMIT;

SET @ch_timer_name = NULL;
SET @ch_timer_code = NULL;
