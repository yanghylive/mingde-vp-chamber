-- Rollback for the commerce event reliability baseline.
-- WARNING: This permanently removes inbox, idempotency, and refund-attempt history.
-- Use only for local/CI rollback before release; MySQL DDL auto-commits.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `ch_refund_attempt`;
DROP TABLE IF EXISTS `ch_idempotency_record`;
DROP TABLE IF EXISTS `ch_commerce_event_inbox`;
