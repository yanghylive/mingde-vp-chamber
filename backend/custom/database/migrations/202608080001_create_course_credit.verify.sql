-- 格林迈「课程·课时」域结构校验。
-- 校验对象：202608080001_create_course_credit.up.sql 建立的 11 张表。
-- 输出契约与既有 verify.sql 一致：每行一个 check_name + PASS/FAIL。

SELECT 'course_credit.all_tables' AS check_name,
  IF(COUNT(*)=11,'PASS','FAIL') AS check_status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
  'ch_coach','ch_course_package','ch_course_package_tier','ch_family','ch_family_member',
  'ch_credit_account','ch_credit_grant','ch_credit_ledger','ch_course_session',
  'ch_course_booking','ch_course_checkin_token'
);

SELECT 'course_credit.coach' AS check_name,
  IF(COUNT(*)=10,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_coach'
  AND COLUMN_NAME IN (
    'id','tenant_id','name','tier','title','avatar','bio','status','add_time','update_time'
  );

SELECT 'course_credit.coach_indexes' AS check_name,
  IF(COUNT(*)=3,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_coach'
  AND INDEX_NAME IN ('PRIMARY','idx_coach_tenant');

SELECT 'course_credit.course_package' AS check_name,
  IF(COUNT(*)=24,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_package'
  AND COLUMN_NAME IN (
    'id','tenant_id','channel_id','code','version','name','course_type','total_hours',
    'validity_months','session_duration_hours','frequency','min_participants','max_participants',
    'price','currency','product_id','product_attr_unique','benefits_json','refund_policy_json',
    'status','effective_time','end_time','add_time','update_time'
  );

SELECT 'course_credit.course_package_indexes' AS check_name,
  IF(COUNT(*)=8,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_package'
  AND INDEX_NAME IN ('PRIMARY','uk_package_code','idx_package_list');

SELECT 'course_credit.course_package_tier' AS check_name,
  IF(COUNT(*)=7,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_package_tier'
  AND COLUMN_NAME IN (
    'id','tenant_id','package_id','coach_tier','price_per_hour','total_price','add_time'
  );

SELECT 'course_credit.course_package_tier_indexes' AS check_name,
  IF(COUNT(*)=4,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_package_tier'
  AND INDEX_NAME IN ('PRIMARY','uk_pkg_tier');

SELECT 'course_credit.family' AS check_name,
  IF(COUNT(*)=8,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_family'
  AND COLUMN_NAME IN (
    'id','tenant_id','name','owner_member_id','owner_uid','status','add_time','update_time'
  );

SELECT 'course_credit.family_indexes' AS check_name,
  IF(COUNT(*)=3,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_family'
  AND INDEX_NAME IN ('PRIMARY','idx_family_owner');

SELECT 'course_credit.family_member' AS check_name,
  IF(COUNT(*)=9,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_family_member'
  AND COLUMN_NAME IN (
    'id','tenant_id','family_id','member_id','uid','relation','status','joined_time','add_time'
  );

SELECT 'course_credit.family_member_indexes' AS check_name,
  IF(COUNT(*)=6,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_family_member'
  AND INDEX_NAME IN ('PRIMARY','uk_family_member','idx_family_member_uid');

SELECT 'course_credit.credit_account' AS check_name,
  IF(COUNT(*)=10,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_credit_account'
  AND COLUMN_NAME IN (
    'id','tenant_id','owner_type','owner_id','uid','balance_hours','frozen_hours','version',
    'add_time','update_time'
  );

SELECT 'course_credit.credit_account_indexes' AS check_name,
  IF(COUNT(*)=6,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_credit_account'
  AND INDEX_NAME IN ('PRIMARY','uk_credit_account_owner','idx_credit_account_uid');

SELECT 'course_credit.credit_grant' AS check_name,
  IF(COUNT(*)=13,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_credit_grant'
  AND COLUMN_NAME IN (
    'id','tenant_id','account_id','package_id','member_id','uid','hours_total','hours_remaining',
    'expire_time','source_order_context_id','status','add_time','update_time'
  );

SELECT 'course_credit.credit_grant_indexes' AS check_name,
  IF(COUNT(*)=8,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_credit_grant'
  AND INDEX_NAME IN ('PRIMARY','idx_grant_account','idx_grant_expire');

SELECT 'course_credit.credit_ledger' AS check_name,
  IF(COUNT(*)=13,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_credit_ledger'
  AND COLUMN_NAME IN (
    'id','tenant_id','account_id','member_id','uid','delta_hours','balance_after','source_type',
    'source_id','idempotency_key','status','reversal_id','add_time'
  );

SELECT 'course_credit.credit_ledger_indexes' AS check_name,
  IF(COUNT(*)=6,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_credit_ledger'
  AND INDEX_NAME IN ('PRIMARY','uk_credit_ledger_key','idx_credit_ledger_account');

SELECT 'course_credit.course_session' AS check_name,
  IF(COUNT(*)=17,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_session'
  AND COLUMN_NAME IN (
    'id','tenant_id','course_type','coach_id','title','start_time','end_time','duration_hours',
    'capacity','booked_count','location_json','price_per_session','applicable_package_id',
    'product_id','status','add_time','update_time'
  );

SELECT 'course_credit.course_session_indexes' AS check_name,
  IF(COUNT(*)=5,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_session'
  AND INDEX_NAME IN ('PRIMARY','idx_session_list');

SELECT 'course_credit.course_booking' AS check_name,
  IF(COUNT(*)=15,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_booking'
  AND COLUMN_NAME IN (
    'id','tenant_id','session_id','member_id','uid','account_id','credit_cost_hours',
    'consume_rule','participants','status','idempotency_key','booking_time','attended_time',
    'add_time','update_time'
  );

SELECT 'course_credit.course_booking_indexes' AS check_name,
  IF(COUNT(*)=10,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_booking'
  AND INDEX_NAME IN ('PRIMARY','uk_booking_dedup','uk_booking_key','idx_booking_member');

SELECT 'course_credit.course_checkin_token' AS check_name,
  IF(COUNT(*)=10,'PASS','FAIL') AS check_status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_checkin_token'
  AND COLUMN_NAME IN (
    'id','tenant_id','session_id','token_digest','issued_by_admin_id','valid_from',
    'expires_time','status','add_time','update_time'
  );

SELECT 'course_credit.course_checkin_token_indexes' AS check_name,
  IF(COUNT(*)=8,'PASS','FAIL') AS check_status
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ch_course_checkin_token'
  AND INDEX_NAME IN ('PRIMARY','uk_course_checkin_token','idx_course_checkin_active');

-- 关键 ASCII 业务列应保持 ascii_bin 排序规则（保证唯一键大小写/二进制语义）。
SELECT 'course_credit.ascii_binary_collation' AS check_name,
  IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
      AND COLUMN_NAME IN ('code','course_type','source_type','source_id','idempotency_key')
      AND COLLATION_NAME='ascii_bin')>=5,
    'PASS','FAIL'
  ) AS check_status;

-- 课时余额/授予累计列应为 10,2 小数列。
SELECT 'course_credit.hours_decimal_10_2' AS check_name,
  IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
      AND COLUMN_NAME IN ('balance_hours','frozen_hours','hours_total','hours_remaining','delta_hours','balance_after')
      AND DATA_TYPE='decimal' AND NUMERIC_PRECISION=10 AND NUMERIC_SCALE=2)>=6,
    'PASS','FAIL'
  ) AS check_status;