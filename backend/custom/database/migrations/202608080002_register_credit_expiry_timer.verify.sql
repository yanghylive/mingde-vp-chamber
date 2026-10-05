-- 格林迈「课程课时到期作废」定时器注册校验。
-- 校验对象：202608080002_register_credit_expiry_timer.up.sql 向 eb_system_timer 注册的定时器行。

SELECT 'credit_expiry_timer.row' AS check_name,
  IF(COUNT(*)=1,'PASS','FAIL') AS check_status
FROM eb_system_timer
WHERE `name`='Chamber course credit expiry'
  AND `mark`='customTimer'
  AND `type`=2
  AND `is_open`=1
  AND `is_del`=0
  AND `timeStr`='0 */6 * * * *';

SELECT 'credit_expiry_timer.custom_code' AS check_name,
  IF(
    (SELECT COUNT(*) FROM eb_system_timer WHERE `name`='Chamber course credit expiry'
      AND `customCode` LIKE '%CreditExpiryJob%' AND `customCode` LIKE '%doJob%')=1,
    'PASS','FAIL'
  ) AS check_status;