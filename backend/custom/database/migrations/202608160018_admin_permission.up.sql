-- admin 动作级权限映射（细粒度权限，不侵入 CRMEB eb_system_role）
CREATE TABLE IF NOT EXISTS ch_admin_permission (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    admin_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'eb_system_admin.id',
    permission VARCHAR(64) NOT NULL DEFAULT '' COMMENT '权限点，如 settlement.rule.write',
    granted_by INT UNSIGNED NOT NULL DEFAULT 0,
    add_time INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_admin_perm (tenant_id, admin_id, permission),
    KEY idx_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='admin 动作级权限映射';
