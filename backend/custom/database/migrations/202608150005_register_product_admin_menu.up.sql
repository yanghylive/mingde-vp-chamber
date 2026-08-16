-- Register the Chamber 积分商品管理 admin menu entry.

SET NAMES utf8mb4;

SET @ch_chamber_menu_id = (
  SELECT MIN(`id`)
  FROM `eb_system_menus`
  WHERE `unique_auth` = 'chamber' AND `is_del` = 0
);

INSERT INTO `eb_system_menus` (
  `pid`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`,
  `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`,
  `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`, `mark`
)
SELECT
  @ch_chamber_menu_id, '', '积分商品管理', 'admin', 'chamber.product', 'index', '', '',
  '[]', 8, 1, 1, 1, '/chamber/products', CAST(@ch_chamber_menu_id AS CHAR),
  1, 'chamber', 0, 'chamber.product.manage', 0, '积分商品管理'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `eb_system_menus`
  WHERE `unique_auth` = 'chamber.product.manage' AND `is_del` = 0
);
