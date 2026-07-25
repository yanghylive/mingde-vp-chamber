-- Destructive local/CI rollback for MEM-002 private member materials.
-- Stored object bytes must be removed separately from runtime/chamber-private.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `ch_member_asset`;
