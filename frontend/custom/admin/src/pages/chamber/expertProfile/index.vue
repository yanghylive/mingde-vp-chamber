<template>
  <div class="expert-profile-workbench">
    <el-alert
      class="info-alert"
      title="维护大咖的公开资料：姓名、头衔、公司、行业、简介。资料保存后，前端大咖页与 AI 智能分身会同步生效。"
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
        <el-table-column label="头衔" min-width="150" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="公司" min-width="150" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.company || '-' }}</template>
        </el-table-column>
        <el-table-column label="行业" min-width="110" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.industry || '-' }}</template>
        </el-table-column>
        <el-table-column label="简介" min-width="220" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.bio || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" align="center" fixed="right">
          <template slot-scope="scope">
            <el-button type="primary" size="mini" @click="openEdit(scope.row)">编辑资料</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 编辑资料弹窗 -->
    <el-dialog title="编辑大咖资料" :visible.sync="dialogVisible" width="520px" :close-on-click-modal="false">
      <el-form label-width="72px" :model="form" :rules="rules" ref="formRef">
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" placeholder="大咖姓名" maxlength="30" />
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
          <el-input v-model="form.bio" type="textarea" :rows="4" placeholder="大咖背景简介，会用于 AI 智能分身人设" maxlength="500" />
        </el-form-item>
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

export default {
  name: 'ChamberExpertProfile',
  data() {
    return {
      loading: false,
      saving: false,
      loadError: '',
      rows: [],
      dialogVisible: false,
      form: { id: 0, name: '', title: '', company: '', industry: '', bio: '' },
      rules: {
        name: [{ required: true, message: '请输入大咖姓名', trigger: 'blur' }],
      },
    };
  },
  created() {
    this.loadList();
  },
  methods: {
    loadList() {
      this.loading = true;
      this.loadError = '';
      expertProfileList()
        .then((res) => {
          const data = res.data || {};
          this.rows = Array.isArray(data.items) ? data.items : Array.isArray(data) ? data : [];
        })
        .catch((e) => {
          this.loadError = (e && e.msg) || '加载失败';
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openAdd() {
      this.form = {
        id: 0,
        name: '',
        title: '',
        company: '',
        industry: '',
        bio: '',
      };
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
      };
      this.dialogVisible = true;
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
</style>
