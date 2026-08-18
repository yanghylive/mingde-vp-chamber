SET NAMES utf8mb4;

SELECT 'ch_wechat_pay_order.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_wechat_pay_order';

SELECT 'ch_wechat_pay_order.uk_out_trade_no' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('unique_keys=', COUNT(*)) AS details
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'ch_wechat_pay_order'
  AND index_name = 'uk_out_trade_no';

SELECT 'ch_wechat_pay_order.status_pending' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('defaults=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_wechat_pay_order'
  AND column_name = 'status'
  AND column_default = 'pending';
