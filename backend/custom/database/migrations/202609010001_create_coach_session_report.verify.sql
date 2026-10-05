-- Structural checks (normalized to the local PASS/FAIL format during the
-- 2026-10-05 production→main merge; the production original used a bare
-- single-row SELECT with an expected-value comment, which the local
-- manage-local-database.sh verifier cannot count).
SET NAMES utf8mb4;

SELECT 'ch_coach_session_report.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coach_session_report';

SELECT 'ch_coach_session_report.tenant_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coach_session_report'
  AND column_name = 'tenant_id';

SELECT 'ch_coach_session_report.photo.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coach_session_report_photo';

SELECT 'ch_coach_session_report.tag.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coach_session_report_tag';

SELECT 'ch_coach_session_report.milestone.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_coach_session_report_milestone';
