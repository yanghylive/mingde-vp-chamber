-- Rollback for Mingde VP Chamber core schema v1.
-- WARNING: This removes business data. Use only for local/CI rollback before release.
-- MySQL DDL auto-commits; tables are dropped in reverse dependency order.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `ch_event_checkin`;
DROP TABLE IF EXISTS `ch_event_registration`;
DROP TABLE IF EXISTS `ch_event_ticket`;
DROP TABLE IF EXISTS `ch_event`;
DROP TABLE IF EXISTS `ch_member_role`;
DROP TABLE IF EXISTS `ch_role_application`;
DROP TABLE IF EXISTS `ch_persona_role`;
DROP TABLE IF EXISTS `ch_audit_record`;
DROP TABLE IF EXISTS `ch_graduate_verification`;
DROP TABLE IF EXISTS `ch_member_profile`;
DROP TABLE IF EXISTS `ch_tenant_member`;
DROP TABLE IF EXISTS `ch_channel`;
DROP TABLE IF EXISTS `ch_tenant`;
