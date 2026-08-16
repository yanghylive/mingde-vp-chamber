-- 通知已读按用户隔离 + 软删除
ALTER TABLE ch_event_notification
  ADD COLUMN is_del TINYINT NOT NULL DEFAULT 0 COMMENT '软删除（1=已撤销）';

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
