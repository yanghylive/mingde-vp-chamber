ALTER TABLE ch_settlement_detail
  DROP KEY idx_due,
  DROP KEY idx_claim,
  DROP COLUMN claim_token,
  DROP COLUMN claim_expire_time,
  DROP COLUMN next_retry_time;

ALTER TABLE ch_payout_record
  DROP COLUMN request_payload_hash,
  DROP COLUMN query_count,
  DROP COLUMN last_query_time;
