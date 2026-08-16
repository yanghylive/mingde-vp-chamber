-- 分账系统数据模型（5 张表）
-- 场景：① 会员费 → 三公司 4:4:2 对公分账；② 平台收入 → 给大咖（个人）结算

-- 1. 分账规则（配置化，比例可改）
CREATE TABLE IF NOT EXISTS ch_settlement_rule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    business_type VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'membership_fee/appointment/event/expert_service',
    receiver_type VARCHAR(16) NOT NULL DEFAULT 'company' COMMENT 'company/expert/individual',
    receiver_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '公司主体 id 或大咖 member_id',
    receiver_name VARCHAR(64) NOT NULL DEFAULT '' COMMENT '接收方名称（对账冗余）',
    ratio TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分账比例（整数，40=40%）',
    channel VARCHAR(24) NOT NULL DEFAULT 'merchant_transfer' COMMENT 'wechat_split/merchant_transfer/bank',
    status TINYINT NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    is_del TINYINT NOT NULL DEFAULT 0,
    add_time INT UNSIGNED NOT NULL DEFAULT 0,
    update_time INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_tenant_biz (tenant_id, business_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分账规则';

-- 2. 分账单（每笔订单一张）
CREATE TABLE IF NOT EXISTS ch_settlement (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    business_type VARCHAR(32) NOT NULL DEFAULT '',
    order_no VARCHAR(64) NOT NULL DEFAULT '' COMMENT '关联订单号',
    order_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '分账基数（订单金额）',
    total_ratio SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending/processing/done/partial/failed',
    settle_time INT UNSIGNED NOT NULL DEFAULT 0,
    add_time INT UNSIGNED NOT NULL DEFAULT 0,
    update_time INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_order (tenant_id, business_type, order_no),
    KEY idx_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分账单';

-- 3. 分账明细（每个接收方一行）
CREATE TABLE IF NOT EXISTS ch_settlement_detail (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    settlement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    receiver_type VARCHAR(16) NOT NULL DEFAULT 'company',
    receiver_id INT UNSIGNED NOT NULL DEFAULT 0,
    receiver_name VARCHAR(64) NOT NULL DEFAULT '',
    ratio TINYINT UNSIGNED NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    channel VARCHAR(24) NOT NULL DEFAULT 'merchant_transfer',
    channel_ref VARCHAR(64) NOT NULL DEFAULT '' COMMENT '通道返回单号',
    status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending/success/failed/reversed',
    fail_reason VARCHAR(255) NOT NULL DEFAULT '',
    retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    settled_time INT UNSIGNED NOT NULL DEFAULT 0,
    add_time INT UNSIGNED NOT NULL DEFAULT 0,
    update_time INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_settlement (settlement_id),
    KEY idx_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分账明细';

-- 4. 打款记录（通道级幂等 + 对账）
CREATE TABLE IF NOT EXISTS ch_payout_record (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    settlement_detail_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    channel VARCHAR(24) NOT NULL DEFAULT '',
    channel_order_no VARCHAR(64) NOT NULL DEFAULT '',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending/success/failed',
    idempotency_key VARCHAR(64) NOT NULL DEFAULT '',
    raw_response TEXT,
    add_time INT UNSIGNED NOT NULL DEFAULT 0,
    update_time INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_idem (idempotency_key),
    KEY idx_detail (settlement_detail_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='打款记录';

-- 5. 抵扣余额（退款下期抵扣：退款不追回已分账，记负余额下期少分）
CREATE TABLE IF NOT EXISTS ch_settlement_balance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
    receiver_type VARCHAR(16) NOT NULL DEFAULT 'company',
    receiver_id INT UNSIGNED NOT NULL DEFAULT 0,
    receiver_name VARCHAR(64) NOT NULL DEFAULT '',
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '待抵扣余额（正=需抵扣，负=平台欠）',
    add_time INT UNSIGNED NOT NULL DEFAULT 0,
    update_time INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_receiver (tenant_id, receiver_type, receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分账抵扣余额';
