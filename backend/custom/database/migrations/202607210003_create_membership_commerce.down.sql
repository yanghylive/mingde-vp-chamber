-- Destructive local rollback for membership commerce facts.
-- Production rollback must preserve financial records and use a forward migration.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `ch_membership_term_effect`;
DROP TABLE IF EXISTS `ch_membership_term`;
DROP TABLE IF EXISTS `ch_order_context`;
DROP TABLE IF EXISTS `ch_membership_plan`;
