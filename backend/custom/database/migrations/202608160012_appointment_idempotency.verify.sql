SELECT COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'crmeb' AND TABLE_NAME = 'ch_appointment' AND COLUMN_NAME = 'booking_key';
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'crmeb' AND TABLE_NAME = 'ch_appointment' AND INDEX_NAME = 'uk_booking_key';
