SET NAMES utf8mb4;

START TRANSACTION;
DELETE FROM `eb_system_timer` WHERE `name` = 'Chamber course credit expiry';
COMMIT;
