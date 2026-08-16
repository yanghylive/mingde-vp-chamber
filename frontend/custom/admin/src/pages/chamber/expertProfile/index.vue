<template>
  <div class="expert-profile-workbench">
    <el-alert
      class="info-alert"
      title="维护大咖的公开资料：姓名、头衔、公司、行业、简介、角色、角色化专业档案、辅导案例、专业资质、主讲课程。保存后前端大咖主页与 AI 智能分身同步生效。"
      type="info"
      show-icon
      :closable="false"
    />

    <el-alert v-if="loadError" class="load-alert" type="error" :title="loadError" show-icon :closable="false">
      <el-button slot="description" size="mini" @click="loadList">重新加载</el-button>
    </el-alert>

    <el-card shadow="never" class="table-panel" :body-style="{ padding: 0 }">
      <div class="table-head">
        <div>
          <h2>大咖资料</h2>
          <span>共 {{ rows.length }} 位大咖 · 编辑资料后点击「保存」立即生效</span>
        </div>
        <div>
          <el-button type="primary" size="mini" icon="el-icon-plus" @click="openAdd">添加大咖</el-button>
          <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无大咖" class="profile-table">
        <el-table-column label="大咖" min-width="140">
          <template slot-scope="scope">
            <div class="primary-cell">{{ scope.row.name || '-' }}</div>
            <div class="secondary-cell">ID {{ scope.row.id }}<span v-if="scope.row._seed">（种子）</span></div>
          </template>
        </el-table-column>
        <el-table-column label="角色" width="100" align="center">
          <template slot-scope="scope">
            <el-tag size="mini" :type="roleTagType(scope.row.role)">{{ roleLabel(scope.row.role) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="头衔" min-width="150" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="公司" min-width="150" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.company || '-' }}</template>
        </el-table-column>
        <el-table-column label="行业" min-width="110" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.industry || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" align="center" fixed="right">
          <template slot-scope="scope">
            <el-button type="primary" size="mini" @click="openEdit(scope.row)">编辑资料</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 编辑资料弹窗 -->
    <el-dialog title="编辑大咖资料" :visible.sync="dialogVisible" width="760px" :close-on-click-modal="false" top="4vh">
      <el-form label-width="88px" :model="form" :rules="rules" ref="formRef" class="expert-form">
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" placeholder="大咖姓名" maxlength="30" />
        </el-form-item>
        <el-form-item label="角色" prop="role">
          <el-select v-model="form.role" placeholder="选择角色" style="width: 100%">
            <el-option v-for="o in roleOptions" :key="o.value" :label="o.label" :value="o.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="头衔" prop="title">
          <el-input v-model="form.title" placeholder="如：知名导师 · 行业领袖" maxlength="60" />
        </el-form-item>
        <el-form-item label="公司" prop="company">
          <el-input v-model="form.company" placeholder="所在机构" maxlength="60" />
        </el-form-item>
        <el-form-item label="行业" prop="industry">
          <el-input v-model="form.industry" placeholder="行业领域" maxlength="30" />
        </el-form-item>
        <el-form-item label="简介" prop="bio">
          <el-input v-model="form.bio" type="textarea" :rows="3" placeholder="大咖背景简介，会用于 AI 智能分身人设" maxlength="500" />
        </el-form-item>

        <!-- 角色化专业档案（按角色动态渲染字段） -->
        <template v-if="currentRoleFields.length">
          <el-divider content-position="left">专业档案</el-divider>
          <el-form-item v-for="f in currentRoleFields" :key="f.field_key" :label="f.field_label">
            <el-select
              v-if="f.field_type === 'tags'"
              v-model="form.profile[f.field_key]"
              multiple
              filterable
              allow-create
              default-first-option
              :placeholder="f.placeholder"
              style="width: 100%"
            >
              <el-option v-for="(t, i) in (form.profile[f.field_key] || [])" :key="i" :label="t" :value="t" />
            </el-select>
            <el-input-number
              v-else-if="f.field_type === 'number'"
              v-model="form.profile[f.field_key]"
              :min="0"
              :controls="false"
              :placeholder="f.placeholder"
              style="width: 100%"
            />
            <el-input
              v-else-if="f.field_type === 'textarea'"
              v-model="form.profile[f.field_key]"
              type="textarea"
              :rows="2"
              :placeholder="f.placeholder"
            />
            <el-input v-else v-model="form.profile[f.field_key]" :placeholder="f.placeholder" />
          </el-form-item>
        </template>

        <!-- 辅导案例 -->
        <el-divider content-position="left">辅导案例</el-divider>
        <div v-for="(c, i) in form.cases" :key="'case-' + i" class="showcase-item">
          <div class="showcase-item-head">
            <span>案例 {{ i + 1 }}</span>
            <el-button type="danger" size="mini" icon="el-icon-delete" circle @click="form.cases.splice(i, 1)" />
          </div>
          <el-input v-model="c.title" placeholder="案例标题" size="small" />
          <el-input v-model="c.description" type="textarea" :rows="2" placeholder="案例描述（成果/数据）" size="small" />
          <div class="showcase-row">
            <el-input v-model="c.industry" placeholder="行业" size="small" />
            <el-input-number v-model="c.year" :min="2000" :max="2099" :controls="false" placeholder="年份" size="small" />
          </div>
        </div>
        <el-button type="text" icon="el-icon-plus" @click="addCase">添加案例</el-button>

        <!-- 专业资质 -->
        <el-divider content-position="left">专业资质</el-divider>
        <div v-for="(c, i) in form.credentials" :key="'cred-' + i" class="showcase-item">
          <div class="showcase-item-head">
            <span>资质 {{ i + 1 }}</span>
            <el-button type="danger" size="mini" icon="el-icon-delete" circle @click="form.credentials.splice(i, 1)" />
          </div>
          <el-input v-model="c.name" placeholder="资质名称" size="small" />
          <div class="showcase-row">
            <el-input v-model="c.issuer" placeholder="颁发机构" size="small" />
            <el-input-number v-model="c.year" :min="1970" :max="2099" :controls="false" placeholder="年份" size="small" />
          </div>
        </div>
        <el-button type="text" icon="el-icon-plus" @click="addCredential">添加资质</el-button>

        <!-- 主讲课程 -->
        <el-divider content-position="left">主讲课程</el-divider>
        <div v-for="(c, i) in form.courses" :key="'course-' + i" class="showcase-item">
          <div class="showcase-item-head">
            <span>课程 {{ i + 1 }}</span>
            <el-button type="danger" size="mini" icon="el-icon-delete" circle @click="form.courses.splice(i, 1)" />
          </div>
          <el-input v-model="c.title" placeholder="课程标题" size="small" />
          <el-input v-model="c.summary" type="textarea" :rows="2" placeholder="课程摘要" size="small" />
        </div>
        <el-button type="text" icon="el-icon-plus" @click="addCourse">添加课程</el-button>
      </el-form>
      <div slot="footer">
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveForm">保存</el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { expertProfileList, expertProfileUpdate } from '@/api/chamber/expertProfile';
import { Message } from 'element-ui';

const ROLE_OPTIONS = [
  { value: 'mentor', label: '导师' },
  { value: 'coach', label: '教练' },
  { value: 'industry_leader', label: '行业领袖' },
];

export default {
  name: 'ChamberExpertProfile',
  data() {
    return {
      loading: false,
      saving: false,
      loadError: '',
      rows: [],
      roleFields: { mentor: [], coach: [], industry_leader: [] },
      roleOptions: ROLE_OPTIONS,
      dialogVisible: false,
      form: this.emptyForm(),
      rules: {
        name: [{ required: true, message: '请输入大咖姓名', trigger: 'blur' }],
      },
    };
  },
  computed: {
    currentRoleFields() {
      return this.roleFields[this.form.role] || [];
    },
  },
  created() {
    this.loadList();
  },
  methods: {
    emptyForm() {
      return {
        id: 0,
        name: '',
        title: '',
        company: '',
        industry: '',
        bio: '',
        role: 'mentor',
        profile: {},
        cases: [],
        credentials: [],
        courses: [],
      };
    },
    roleLabel(role) {
      const found = ROLE_OPTIONS.find((o) => o.value === role);
      return found ? found.label : '导师';
    },
    roleTagType(role) {
      const map = { mentor: 'success', coach: 'warning', industry_leader: 'danger' };
      return map[role] || 'info';
    },
    loadList() {
      this.loading = true;
      this.loadError = '';
      expertProfileList()
        .then((res) => {
          const data = res.data || {};
          this.rows = Array.isArray(data.items) ? data.items : Array.isArray(data) ? data : [];
          if (data.role_fields) this.roleFields = data.role_fields;
        })
        .catch((e) => {
          this.loadError = (e && e.msg) || '加载失败';
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openAdd() {
      this.form = this.emptyForm();
      this.dialogVisible = true;
    },
    openEdit(row) {
      this.form = {
        id: row.id,
        name: row.name || '',
        title: row.title || '',
        company: row.company || '',
        industry: row.industry || '',
        bio: row.bio || '',
        role: row.role || 'mentor',
        profile: row.profile && typeof row.profile === 'object' ? row.profile : {},
        cases: Array.isArray(row.cases) ? row.cases.map((x) => Object.assign({}, x)) : [],
        credentials: Array.isArray(row.credentials) ? row.credentials.map((x) => Object.assign({}, x)) : [],
        courses: Array.isArray(row.courses) ? row.courses.map((x) => Object.assign({}, x)) : [],
      };
      this.dialogVisible = true;
    },
    addCase() {
      this.form.cases.push({ title: '', description: '', industry: '', year: new Date().getFullYear() });
    },
    addCredential() {
      this.form.credentials.push({ name: '', issuer: '', year: new Date().getFullYear() });
    },
    addCourse() {
      this.form.courses.push({ title: '', summary: '' });
    },
    normalizeProfile() {
      // tags 字段保证是数组；number 字段转数字
      const out = {};
      const fields = this.currentRoleFields;
      Object.keys(this.form.profile).forEach((k) => {
        const field = fields.find((f) => f.field_key === k);
        const v = this.form.profile[k];
        if (field && field.field_type === 'tags') {
          if (!Array.isArray(v) || v.length === 0) return;
          out[k] = v.map((x) => String(x).trim()).filter(Boolean);
        } else if (field && field.field_type === 'number') {
          const n = Number(v);
          if (!Number.isFinite(n) || n < 0) return;
          out[k] = Math.round(n);
        } else {
          const s = String(v == null ? '' : v).trim();
          if (s === '') return;
          out[k] = s;
        }
      });
      return out;
    },
    saveForm() {
      this.$refs.formRef.validate((valid) => {
        if (!valid) return;
        this.saving = true;
        expertProfileUpdate(this.form.id, {
          name: this.form.name,
          title: this.form.title,
          company: this.form.company,
          industry: this.form.industry,
          bio: this.form.bio,
          role: this.form.role,
          profile: this.normalizeProfile(),
          cases: this.form.cases.filter((c) => c.title && c.title.trim()),
          credentials: this.form.credentials.filter((c) => c.name && c.name.trim()),
          courses: this.form.courses.filter((c) => c.title && c.title.trim()),
        })
          .then(() => {
            Message.success('大咖资料已保存');
            this.dialogVisible = false;
            this.loadList();
          })
          .catch((e) => {
            Message.error((e && e.msg) || '保存失败');
          })
          .finally(() => {
            this.saving = false;
          });
      });
    },
  },
};
</script>

<style scoped>
.expert-profile-workbench {
  padding: 16px;
}
.info-alert {
  margin-bottom: 12px;
}
.load-alert {
  margin-bottom: 12px;
}
.table-panel {
  border-radius: 8px;
}
.table-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid #f0f0f0;
}
.table-head h2 {
  margin: 0;
  font-size: 16px;
  color: #303133;
}
.table-head span {
  margin-left: 10px;
  font-size: 12px;
  color: #909399;
}
.primary-cell {
  color: #303133;
  font-weight: 600;
}
.secondary-cell {
  margin-top: 2px;
  font-size: 12px;
  color: #909399;
}
.profile-table >>> .el-table__row {
  cursor: pointer;
}
.expert-form >>> .el-form-item {
  margin-bottom: 16px;
}
.showcase-item {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 10px 12px;
  margin-bottom: 10px;
  background: #fafbfc;
}
.showcase-item-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  font-size: 13px;
  color: #606266;
  font-weight: 600;
}
.showcase-item >>> .el-input,
.showcase-item >>> .el-input-number {
  margin-bottom: 8px;
}
.showcase-row {
  display: flex;
  gap: 10px;
}
.showcase-row >>> .el-input,
.showcase-row >>> .el-input-number {
  flex: 1;
}
</style>
