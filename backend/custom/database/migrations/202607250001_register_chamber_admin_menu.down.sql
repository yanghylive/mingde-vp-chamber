-- Remove only the Chamber menu entries owned by this overlay.

SET NAMES utf8mb4;

DELETE FROM `eb_system_menus`
WHERE `unique_auth` = 'chamber.graduate_verification.review';

DELETE FROM `eb_system_menus`
WHERE `unique_auth` = 'chamber';
