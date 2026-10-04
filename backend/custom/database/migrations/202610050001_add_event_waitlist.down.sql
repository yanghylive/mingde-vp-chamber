-- Local/CI rollback only. The waitlist feature is a product capability; reverting
-- drops promotion state. Members already converted keep their registrations.
DROP TABLE IF EXISTS `ch_event_waitlist`;

ALTER TABLE `ch_event_ticket`
  DROP COLUMN `waitlist_enabled`;
