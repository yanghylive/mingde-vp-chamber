SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='crmeb' AND TABLE_NAME='ch_event_notification' AND COLUMN_NAME='is_del';
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA='crmeb' AND TABLE_NAME='ch_notification_read';
SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='crmeb' AND TABLE_NAME='ch_notification_read' AND INDEX_NAME='uk_notif_member';
