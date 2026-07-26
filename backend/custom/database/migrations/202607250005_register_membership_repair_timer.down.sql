-- Remove only the Chamber-owned G1-01D repair timer.

SET NAMES utf8mb4;

DELETE FROM `eb_system_timer`
WHERE `name` = 'Chamber membership order context repair'
  AND `mark` = 'customTimer';
