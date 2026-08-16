-- 回滚 EXP-001/002：删除案例/资质/课程表 + 角色字段模板表 + ch_expert 新字段
DROP TABLE IF EXISTS ch_expert_course;
DROP TABLE IF EXISTS ch_expert_credential;
DROP TABLE IF EXISTS ch_expert_case;
DROP TABLE IF EXISTS ch_expert_role_field;
ALTER TABLE ch_expert
  DROP COLUMN profile_json,
  DROP COLUMN role;
