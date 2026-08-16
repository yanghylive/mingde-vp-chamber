-- 会员番号：后台管理员录入多个，前台会员选择一个作为展示番号（member_no）
CREATE TABLE IF NOT EXISTS ch_member_number (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id int NOT NULL DEFAULT 0,
  member_id int NOT NULL DEFAULT 0 COMMENT 'ch_tenant_member.id',
  number varchar(64) NOT NULL DEFAULT '' COMMENT '番号（如 MD-2024-001）',
  label varchar(40) NOT NULL DEFAULT '' COMMENT '番号标签（如 商会会员号/班级学号）',
  is_selected tinyint NOT NULL DEFAULT 0 COMMENT '会员选中的展示番号（1=当前展示）',
  sort int NOT NULL DEFAULT 0,
  status tinyint NOT NULL DEFAULT 1,
  is_del tinyint NOT NULL DEFAULT 0,
  add_time int NOT NULL DEFAULT 0,
  update_time int NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_member (tenant_id, member_id, is_del, sort),
  KEY idx_selected (tenant_id, member_id, is_selected)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员番号';
