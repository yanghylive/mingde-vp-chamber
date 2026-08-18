-- AI 分身上架标记：人人可训练个人分身，但只有 is_listed=1 的分身可被其他会员
-- 搜索/查看/对话（对外商品维度），存量分身默认 0 由 admin 审核上架。
ALTER TABLE ch_expert_ai
  ADD COLUMN is_listed TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 未上架（仅本人可训练） 1 已上架（可被其他会员对话）' AFTER training_progress;

-- 存量 13 个分身保持未上架（不破坏现状，对外不可对话）
UPDATE ch_expert_ai SET is_listed = 0 WHERE is_listed IS NULL OR is_listed NOT IN (0,1);
