-- Remove the Chamber 积分商品管理 admin menu entry.

SET NAMES utf8mb4;

UPDATE `eb_system_menus`
SET `is_del` = 1
WHERE `unique_auth` = 'chamber.product.manage';
