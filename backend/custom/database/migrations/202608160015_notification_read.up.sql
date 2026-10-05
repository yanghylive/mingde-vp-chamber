-- 通知已读按用户隔离 + 软删除
-- _ch_idempotent_add_column: 条件 DDL，列已存在时跳过（生产手工演进环境兼容）
SET @ch_ddl := IF(
  (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ch_event_notification' AND column_name = 'is_del') = 0,
  'ALTER TABLE ch_event_notification ADD COLUMN is_del TINYINT NOT NULL DEFAULT 0 COMMENT ''软删除（1=已撤销）'';',
  'DO 0'
);
PREPARE ch_stmt FROM @ch_ddl; EXECUTE ch_stmt; DEALLOCATE PREPARE ch_stmt;

CREATE TABLE IF NOT EXISTS ch_notification_read (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    member_id INT UNSIGNED NOT NULL DEFAULT 0,
    read_time INT UNSIGNED NOT NULL DEFAULT 0,
    add_time INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_notif_member (notification_id, member_id),
    KEY idx_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知已读状态（按用户隔离）';
