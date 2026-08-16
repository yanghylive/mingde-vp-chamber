-- EXP-001/002 大咖角色主页 + 案例/资质/课程（方案 B：结构化多表 + 表驱动角色模板）
-- 1. ch_expert 加 role + profile_json
-- 2. ch_expert_role_field 角色字段模板表（表驱动，定义每种角色展示哪些字段）
-- 3. ch_expert_case / ch_expert_credential / ch_expert_course 独立表（可检索/排序/运营）

ALTER TABLE ch_expert
  ADD COLUMN role varchar(20) NOT NULL DEFAULT 'mentor' COMMENT '角色 mentor/coach/industry_leader' AFTER industry,
  ADD COLUMN profile_json text COMMENT '角色化资料 key-value JSON（key 对应 ch_expert_role_field.field_key）' AFTER bio;

CREATE TABLE IF NOT EXISTS ch_expert_role_field (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id int NOT NULL DEFAULT 0,
  role varchar(20) NOT NULL DEFAULT '' COMMENT 'mentor/coach/industry_leader',
  field_key varchar(40) NOT NULL DEFAULT '' COMMENT '字段 key（对应 profile_json 的键）',
  field_label varchar(40) NOT NULL DEFAULT '' COMMENT '展示名',
  field_type varchar(20) NOT NULL DEFAULT 'text' COMMENT 'text/textarea/number/tags',
  placeholder varchar(100) NOT NULL DEFAULT '',
  sort int NOT NULL DEFAULT 0,
  status tinyint NOT NULL DEFAULT 1,
  add_time int NOT NULL DEFAULT 0,
  update_time int NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tenant_role_key (tenant_id, role, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大咖角色字段模板（表驱动）';

CREATE TABLE IF NOT EXISTS ch_expert_case (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id int NOT NULL DEFAULT 0,
  expert_id int NOT NULL DEFAULT 0,
  title varchar(120) NOT NULL DEFAULT '',
  description varchar(500) NOT NULL DEFAULT '',
  industry varchar(60) NOT NULL DEFAULT '',
  year smallint NOT NULL DEFAULT 0,
  sort int NOT NULL DEFAULT 0,
  status tinyint NOT NULL DEFAULT 1,
  is_del tinyint NOT NULL DEFAULT 0,
  add_time int NOT NULL DEFAULT 0,
  update_time int NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_tenant_expert (tenant_id, expert_id, is_del, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大咖案例';

CREATE TABLE IF NOT EXISTS ch_expert_credential (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id int NOT NULL DEFAULT 0,
  expert_id int NOT NULL DEFAULT 0,
  name varchar(120) NOT NULL DEFAULT '',
  issuer varchar(120) NOT NULL DEFAULT '',
  year smallint NOT NULL DEFAULT 0,
  sort int NOT NULL DEFAULT 0,
  status tinyint NOT NULL DEFAULT 1,
  is_del tinyint NOT NULL DEFAULT 0,
  add_time int NOT NULL DEFAULT 0,
  update_time int NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_tenant_expert (tenant_id, expert_id, is_del, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大咖资质';

CREATE TABLE IF NOT EXISTS ch_expert_course (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  tenant_id int NOT NULL DEFAULT 0,
  expert_id int NOT NULL DEFAULT 0,
  title varchar(120) NOT NULL DEFAULT '',
  summary varchar(500) NOT NULL DEFAULT '',
  sort int NOT NULL DEFAULT 0,
  status tinyint NOT NULL DEFAULT 1,
  is_del tinyint NOT NULL DEFAULT 0,
  add_time int NOT NULL DEFAULT 0,
  update_time int NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_tenant_expert (tenant_id, expert_id, is_del, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大咖课程';

-- 角色字段模板种子（3 种角色 × 4 字段）
INSERT INTO ch_expert_role_field (tenant_id, role, field_key, field_label, field_type, placeholder, sort, status, add_time, update_time) VALUES
  (1, 'mentor', 'expertise_tags', '擅长领域', 'tags', '如：精益生产、降本增效', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'mentor', 'coaching_cases_count', '辅导案例数', 'number', '如：30', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'mentor', 'companies_served', '服务企业数', 'number', '如：30', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'mentor', 'mentor_message', '导师寄语', 'textarea', '一句话寄语', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'coach', 'certifications', '教练资质', 'tags', '如：ICF ACC、PCC', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'coach', 'style', '教练风格', 'text', '如：结果导向、循循善诱', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'coach', 'hours', '累计辅导时长', 'number', '如：1200（小时）', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'coach', 'clients_count', '辅导人数', 'number', '如：80', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'industry_leader', 'achievements', '行业成就', 'tags', '如：省部级科技进步奖', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'industry_leader', 'signature_projects', '代表项目', 'tags', '如：某集团数字化转型', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'industry_leader', 'titles', '社会职务', 'tags', '如：XX协会副会长', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 'industry_leader', 'influence', '行业影响力', 'textarea', '行业影响力描述', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE field_label = VALUES(field_label), field_type = VALUES(field_type), placeholder = VALUES(placeholder), sort = VALUES(sort), status = VALUES(status), update_time = UNIX_TIMESTAMP();

-- 阿曲（id=2）示例数据：角色=mentor + profile + 案例/资质/课程
UPDATE ch_expert SET role = 'mentor', profile_json = '{"expertise_tags":["精益生产","降本增效","工厂运营"],"coaching_cases_count":30,"companies_served":30,"mentor_message":"让每一家制造企业都能用数据说话"}' WHERE id = 2;

INSERT INTO ch_expert_case (tenant_id, expert_id, title, description, industry, year, sort, status, is_del, add_time, update_time) VALUES
  (1, 2, '某汽车零部件企业精益转型', '导入精益生产体系，库存周转率提升 40%，交付周期缩短 25%。', '智能制造', 2025, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 2, '某电子制造企业降本增效', '现场诊断 + 落地陪跑，年降本 1200 万元。', '电子制造', 2024, 2, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 2, '某装备制造企业产线升级', '重构产线布局与节拍，人均产出提升 35%。', '装备制造', 2024, 3, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO ch_expert_credential (tenant_id, expert_id, name, issuer, year, sort, status, is_del, add_time, update_time) VALUES
  (1, 2, '精益生产管理师', '中国管理科学研究院', 2020, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO ch_expert_course (tenant_id, expert_id, title, summary, sort, status, is_del, add_time, update_time) VALUES
  (1, 2, '精益生产实战营', '从 0 到 1 搭建精益生产体系，含现场诊断与落地陪跑。', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  (1, 2, '制造业降本增效工作坊', '聚焦八大浪费消除与成本优化，可带实际项目现场演练。', 2, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
