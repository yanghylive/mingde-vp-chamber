-- Remove only G2 activity runtime additions.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `ch_contribution_ledger`;
DROP TABLE IF EXISTS `ch_point_ledger`;
DROP TABLE IF EXISTS `ch_point_account`;
DROP TABLE IF EXISTS `ch_event_reward`;
DROP TABLE IF EXISTS `ch_event_checkin_token`;
ALTER TABLE `ch_event_ticket`
  DROP COLUMN `refund_policy_json`;
ALTER TABLE `ch_event`
  DROP COLUMN `refund_policy_json`,
  DROP COLUMN `speakers_json`,
  DROP COLUMN `tags_json`;
ALTER TABLE `ch_event_registration`
  DROP INDEX `idx_registration_context`,
  DROP COLUMN `order_context_id`;
