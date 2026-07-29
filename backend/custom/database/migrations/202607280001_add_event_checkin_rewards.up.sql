-- Configurable attendance rewards for the G2 activity check-in flow.

SET NAMES utf8mb4;

ALTER TABLE `ch_event`
  ADD COLUMN `checkin_reward_points` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '签到奖励租户积分' AFTER `refund_policy_json`,
  ADD COLUMN `checkin_reward_contribution` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '签到奖励贡献值' AFTER `checkin_reward_points`;
