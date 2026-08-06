-- Data normalization is intentionally irreversible. Rolling application code back
-- does not require recreating malformed legacy JSON.
SELECT '202607250002_normalize_member_profile_json is an irreversible data repair' AS `notice`;
