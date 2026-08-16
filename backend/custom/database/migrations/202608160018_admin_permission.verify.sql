SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA='crmeb' AND TABLE_NAME='ch_admin_permission';
SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='crmeb' AND TABLE_NAME='ch_admin_permission' AND INDEX_NAME='uk_admin_perm';
