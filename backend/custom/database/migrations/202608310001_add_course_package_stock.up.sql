-- 课时包可售库存（M03 课程体系页「售罄置灰」展示，对应 Issue #3）。
-- stock = 0 表示已售罄；stock = -1 表示不限量（默认，避免既有课时包被判为售罄）；>0 表示剩余可售数量。
-- 与 CoursePackageSnapshot::fromArray() 的 `?? -1` 兜底保持一致：列缺失时按不限量处理，不阻断列表接口。
ALTER TABLE `ch_course_package`
  ADD COLUMN `stock` int(11) NOT NULL DEFAULT '-1'
  COMMENT '可售库存，-1表示不限量，0表示已售罄' AFTER `price`;
