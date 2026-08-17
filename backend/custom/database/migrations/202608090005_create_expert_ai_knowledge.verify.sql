-- Structural checks (auto-generated).
SET NAMES utf8mb4;

SELECT 'ch_expert_ai_knowledge.exists' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('tables=', COUNT(*)) AS details
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'ch_expert_ai_knowledge';

SELECT 'ch_expert_ai_knowledge.expert_id' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_expert_ai_knowledge'
  AND column_name = 'expert_id';

SELECT 'ch_expert_ai_knowledge.title' AS check_name,
       IF(COUNT(*) = 1, 'PASS', 'FAIL') AS result,
       CONCAT('columns=', COUNT(*)) AS details
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ch_expert_ai_knowledge'
  AND column_name = 'title';
