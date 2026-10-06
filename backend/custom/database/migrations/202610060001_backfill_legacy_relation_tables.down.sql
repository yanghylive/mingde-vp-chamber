-- Local/CI rollback only. These tables back live relations (friends, membership
-- orders, distribution records); dropping them discards that history.
DROP TABLE IF EXISTS `ch_member_friend`;
DROP TABLE IF EXISTS `ch_membership_order`;
DROP TABLE IF EXISTS `ch_distribution_record`;
