-- Structural postconditions for the Chamber 积分商品管理 menu entry.

SET NAMES utf8mb4;

SELECT
  'admin_menu.product.unique' AS `check_name`,
  IF(COUNT(*) = 1, 'PASS', 'FAIL') AS `result`,
  CONCAT('rows=', COUNT(*)) AS `details`
FROM `eb_system_menus`
WHERE `unique_auth` = 'chamber.product.manage' AND `is_del` = 0;

SELECT
  'admin_menu.product.contract' AS `check_name`,
  IF(
    COUNT(*) = 1
    AND MIN(child.`pid`) = MIN(parent.`id`)
    AND MIN(child.`menu_path`) = '/chamber/products'
    AND MIN(child.`path`) = CAST(MIN(parent.`id`) AS CHAR)
    AND MIN(child.`auth_type`) = 1
    AND MIN(child.`is_show_path`) = 1,
    'PASS', 'FAIL'
  ) AS `result`,
  CONCAT(
    'rows=', COUNT(*),
    '; pid=', COALESCE(MIN(child.`pid`), 0),
    '; parent=', COALESCE(MIN(parent.`id`), 0)
  ) AS `details`
FROM `eb_system_menus` AS child
LEFT JOIN `eb_system_menus` AS parent
  ON parent.`unique_auth` = 'chamber' AND parent.`is_del` = 0
WHERE child.`unique_auth` = 'chamber.product.manage'
  AND child.`is_del` = 0;
