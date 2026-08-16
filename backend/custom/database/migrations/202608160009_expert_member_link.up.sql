-- 统一身份映射层第一步：ch_expert 增加 member_id，关联会员主键
-- 0 = 未关联会员（独立资料大咖，如当前阿曲）；>0 = 已关联 ch_tenant_member.id
ALTER TABLE ch_expert
    ADD COLUMN member_id INT NOT NULL DEFAULT 0 COMMENT '关联会员 ch_tenant_member.id（0=未关联）' AFTER id;
