SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='crmeb' AND TABLE_NAME='ch_appointment'
AND COLUMN_NAME IN ('slot_start_time','slot_end_time','location');
