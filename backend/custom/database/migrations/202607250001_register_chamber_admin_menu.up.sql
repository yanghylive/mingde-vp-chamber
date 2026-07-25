-- Register the Chamber operations entry in the pinned CRMEB admin menu.

SET NAMES utf8mb4;

INSERT INTO `eb_system_menus` (
  `pid`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`,
  `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`,
  `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`, `mark`
)
SELECT
  0, 's-custom', '商会运营', 'admin', 'chamber', 'index', '', '',
  '[]', 124, 1, 1, 1, '/chamber', '',
  1, 'chamber', 1, 'chamber', 0, '商会运营'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `unique_auth` = 'chamber' AND `is_del` = 0
);

SET @ch_chamber_menu_id = (
  SELECT MIN(`id`)
  FROM `eb_system_menus`
  WHERE `unique_auth` = 'chamber' AND `is_del` = 0
);

UPDATE `eb_system_menus`
SET `pid` = 0,
    `icon` = 's-custom',
    `menu_name` = '商会运营',
    `module` = 'admin',
    `controller` = 'chamber',
    `action` = 'index',
    `api_url` = '',
    `methods` = '',
    `params` = '[]',
    `sort` = 124,
    `is_show` = 1,
    `is_show_path` = 1,
    `access` = 1,
    `menu_path` = '/chamber',
    `path` = '',
    `auth_type` = 1,
    `header` = 'chamber',
    `is_header` = 1,
    `is_del` = 0,
    `mark` = '商会运营'
WHERE `id` = @ch_chamber_menu_id;

INSERT INTO `eb_system_menus` (
  `pid`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`,
  `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`,
  `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`, `mark`
)
SELECT
  @ch_chamber_menu_id, '', '毕业认证审核', 'admin', 'chamber.graduate_verification', 'index', '', '',
  '[]', 10, 1, 1, 1, '/chamber/graduate-verifications', CAST(@ch_chamber_menu_id AS CHAR),
  1, 'chamber', 0, 'chamber.graduate_verification.review', 0, '毕业认证申请审核'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `eb_system_menus`
  WHERE `unique_auth` = 'chamber.graduate_verification.review' AND `is_del` = 0
);

UPDATE `eb_system_menus`
SET `pid` = @ch_chamber_menu_id,
    `icon` = '',
    `menu_name` = '毕业认证审核',
    `module` = 'admin',
    `controller` = 'chamber.graduate_verification',
    `action` = 'index',
    `api_url` = '',
    `methods` = '',
    `params` = '[]',
    `sort` = 10,
    `is_show` = 1,
    `is_show_path` = 1,
    `access` = 1,
    `menu_path` = '/chamber/graduate-verifications',
    `path` = CAST(@ch_chamber_menu_id AS CHAR),
    `auth_type` = 1,
    `header` = 'chamber',
    `is_header` = 0,
    `is_del` = 0,
    `mark` = '毕业认证申请审核'
WHERE `unique_auth` = 'chamber.graduate_verification.review';
