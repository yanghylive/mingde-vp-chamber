-- 课时包可售库存（M03 课程体系页「售罄置灰」展示，对应 Issue #3）。
-- stock = 0 表示已售罄；stock = -1 表示不限量（默认，避免既有课时包被判为售罄）；>0 表示剩余可售数量。
-- 与 CoursePackageSnapshot::fromArray() 的 `?? -1` 兜底保持一致：列缺失时按不限量处理，不阻断列表接口。
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_course_package' AND column_name = 'stock') = 0,
  'ALTER TABLE `ch_course_package`
  ADD COLUMN `stock` int(11) NOT NULL DEFAULT ''-1''
  COMMENT ''可售库存，-1表示不限量，0表示已售罄'' AFTER `price`;',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

