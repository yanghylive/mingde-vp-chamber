-- 校验 EXP-001/002 迁移结果
SELECT IF(COUNT(*) = 2, 'OK: ch_expert has role + profile_json', CONCAT('FAIL: ch_expert missing columns, found ', COUNT(*))) AS `result`
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'crmeb' AND TABLE_NAME = 'ch_expert' AND COLUMN_NAME IN ('role', 'profile_json');

SELECT IF(COUNT(*) = 4, 'OK: 4 showcase tables exist', CONCAT('FAIL: showcase tables = ', COUNT(*))) AS `result`
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'crmeb' AND TABLE_NAME IN ('ch_expert_role_field', 'ch_expert_case', 'ch_expert_credential', 'ch_expert_course');

SELECT IF(COUNT(*) = 12, 'OK: 12 role fields seeded', CONCAT('FAIL: role fields = ', COUNT(*))) AS `result`
FROM ch_expert_role_field WHERE tenant_id = 1 AND status = 1;
