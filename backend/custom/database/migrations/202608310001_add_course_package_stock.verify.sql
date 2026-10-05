-- 校验：ch_course_package 已新增 stock 列（默认 -1，不限量）。
SELECT 'course_package.stock_column' AS check_name,
  CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ch_course_package'
  AND COLUMN_NAME = 'stock'
  AND COLUMN_DEFAULT = '-1';
