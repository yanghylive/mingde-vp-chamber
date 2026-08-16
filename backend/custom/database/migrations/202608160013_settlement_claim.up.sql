-- 结算 claim/lease：防止并发重复打款 + 失败退避重试
ALTER TABLE ch_settlement_detail
  ADD COLUMN claim_token VARCHAR(64) NOT NULL DEFAULT '' COMMENT '认领令牌（lease）',
  ADD COLUMN claim_expire_time INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '认领过期时间',
  ADD COLUMN next_retry_time INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下次重试时间（失败退避）';

ALTER TABLE ch_settlement_detail
  ADD KEY idx_due (tenant_id, status, next_retry_time, id),
  ADD KEY idx_claim (status, claim_expire_time);

ALTER TABLE ch_payout_record
  ADD COLUMN request_payload_hash CHAR(64) NOT NULL DEFAULT '' COMMENT '请求体哈希（对账）',
  ADD COLUMN query_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道查询次数',
  ADD COLUMN last_query_time INT UNSIGNED NOT NULL DEFAULT 0;
