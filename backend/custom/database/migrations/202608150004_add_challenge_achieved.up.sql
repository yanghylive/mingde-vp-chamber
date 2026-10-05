-- 挑战达成标记（P1-3：连续达成挑战天数，区别于连续回应天数）
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_coaching_daily' AND column_name = 'challenge_achieved') = 0,
  'ALTER TABLE `ch_coaching_daily` ADD COLUMN `challenge_achieved` tinyint(1) UNSIGNED NOT NULL DEFAULT ''0'' COMMENT ''当日挑战是否达成：0未达成/未回传 1达成(done)'' AFTER `respond_status`;',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;
