-- 监控告警冷却状态（去重 + 冷却）
CREATE TABLE IF NOT EXISTS ch_alert_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    component VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'db/cron/settlement/ai/pay',
    error_code VARCHAR(64) NOT NULL DEFAULT '',
    last_fired_at INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_alert (tenant_id, component, error_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='告警冷却状态（冷却期内不重复告警）';
