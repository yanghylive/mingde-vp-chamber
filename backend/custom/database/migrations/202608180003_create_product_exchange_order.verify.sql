SET NAMES utf8mb4;

SELECT 'ch_product_exchange_order.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_product_exchange_order';

SELECT 'ch_product_exchange_order.status_default' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('defaults=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_product_exchange_order'
  AND column_name = 'status'
  AND column_default = 'pending';
