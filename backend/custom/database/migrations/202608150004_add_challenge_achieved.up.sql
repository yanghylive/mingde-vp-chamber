-- 挑战达成标记（P1-3：连续达成挑战天数，区别于连续回应天数）
ALTER TABLE `ch_coaching_daily`
  ADD COLUMN `challenge_achieved` tinyint(1) UNSIGNED NOT NULL DEFAULT '0'
  COMMENT '当日挑战是否达成：0未达成/未回传 1达成(done)' AFTER `respond_status`;
