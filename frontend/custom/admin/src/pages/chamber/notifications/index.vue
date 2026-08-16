<template>
  <div class="notifications-workbench">
    <el-alert
      class="info-alert"
      title="向会员发布站内信通知：选择「全部会员」或「指定会员」，发布后会员在 App 通知中心实时可见。"
      type="info"
      show-icon
      :closable="false"
    />

    <el-card shadow="never" class="publish-panel">
      <h2 class="panel-title">{{ editingId ? '编辑通知' : '发布通知' }}</h2>
      <el-form label-width="84px" :model="form" :rules="rules" ref="formRef">
        <el-form-item label="通知标题" prop="title">
          <el-input v-model="form.title" placeholder="如：本周六沙龙活动提醒" maxlength="60" />
        </el-form-item>
        <el-form-item label="通知内容" prop="body">
          <el-input v-model="form.body" type="textarea" :rows="3" placeholder="通知正文内容" maxlength="500" />
        </el-form-item>
        <el-form-item v-if="!editingId" label="发送范围" prop="scope">
          <el-radio-group v-model="form.scope">
            <el-radio label="all">全部会员</el-radio>
            <el-radio label="member">指定会员</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="!editingId && form.scope === 'member'" label="会员 ID" prop="member_id">
          <el-input-number v-model="form.member_id" :min="1" :step="1" style="width: 200px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="sending" @click="sendForm">{{ editingId ? '保存修改' : '发布' }}</el-button>
          <el-button v-if="editingId" @click="editingId = 0; form = { title: '', body: '', scope: 'all', member_id: 1 }">取消编辑</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="table-panel" :body-style="{ padding: 0 }" style="margin-top: 16px">
      <div class="table-head">
        <div>
          <h2>已发通知</h2>
          <span>共 {{ total }} 条</span>
        </div>
        <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
      </div>
      <el-table v-loading="loading" :data="rows" empty-text="暂无通知" class="notifications-table">
        <el-table-column label="标题" min-width="180">
          <template slot-scope="scope">
            <div class="primary-cell">{{ scope.row.title }}</div>
            <div class="secondary-cell">ID {{ scope.row.id }} · {{ scope.row.member_id === 0 ? '全部会员' : '会员 ' + scope.row.member_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="内容" min-width="240" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.body || '-' }}</template>
        </el-table-column>
        <el-table-column label="已读" width="80" align="center">
          <template slot-scope="scope">
            <el-tag :type="scope.row.read ? 'info' : 'danger'" size="mini">{{ scope.row.read ? '已读' : '未读' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发布时间" width="160" align="center">
          <template slot-scope="scope">{{ fmtTime(scope.row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="center" fixed="right">
          <template slot-scope="scope">
            <el-button size="mini" @click="openEdit(scope.row)">编辑</el-button>
            <el-button size="mini" type="danger" :loading="acting === scope.row.id" @click="recall(scope.row)">
              撤销
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { notificationList, notificationCreate, notificationUpdate, notificationDelete } from '@/api/chamber/notifications';
import { Message, MessageBox } from 'element-ui';

export default {
  name: 'ChamberNotifications',
  data() {
    return {
      loading: false,
      sending: false,
      acting: 0,
      rows: [],
      total: 0,
      editingId: 0,
      form: { title: '', body: '', scope: 'all', member_id: 1 },
      rules: {
        title: [{ required: true, message: '请输入通知标题', trigger: 'blur' }],
        body: [{ required: true, message: '请输入通知内容', trigger: 'blur' }],
        member_id: [{ required: true, message: '请输入会员 ID', trigger: 'change' }],
      },
    };
  },
  created() {
    this.loadList();
  },
  methods: {
    fmtTime(ts) {
      if (!ts) return '-';
      const d = new Date(Number(ts) * 1000);
      const p = (n) => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    },
    loadList() {
      this.loading = true;
      notificationList({ page: 1, limit: 50 })
        .then((res) => {
          const data = res.data || {};
          this.rows = Array.isArray(data.items) ? data.items : [];
          this.total = data.total || this.rows.length;
        })
        .catch(() => {})
        .finally(() => {
          this.loading = false;
        });
    },
    sendForm() {
      this.$refs.formRef.validate((valid) => {
        if (!valid) return;
        this.sending = true;
        const req = this.editingId
          ? notificationUpdate(this.editingId, {
              title: this.form.title,
              body: this.form.body,
            })
          : notificationCreate({
              title: this.form.title,
              body: this.form.body,
              scope: this.form.scope,
              member_id: this.form.member_id,
            });
        req
          .then((res) => {
            if (this.editingId) {
              Message.success('通知已更新');
            } else {
              const sent = (res.data && res.data.sent) || 1;
              Message.success(`通知已发布（${sent} 人）`);
            }
            this.editingId = 0;
            this.form = { title: '', body: '', scope: 'all', member_id: 1 };
            this.loadList();
          })
          .catch((e) => Message.error((e && e.msg) || (this.editingId ? '更新失败' : '发布失败')))
          .finally(() => {
            this.sending = false;
          });
      });
    },
    openEdit(row) {
      this.editingId = row.id;
      this.form = {
        title: row.title || '',
        body: row.body || '',
        scope: 'all',
        member_id: 1,
      };
    },
    recall(row) {
      MessageBox.confirm(`确定撤销通知「${row.title}」？撤销后会员端将不再显示。`, '撤销确认', {
        type: 'warning',
      })
        .then(() => {
          this.acting = row.id;
          return notificationDelete(row.id);
        })
        .then(() => {
          Message.success('通知已撤销');
          this.loadList();
        })
        .catch((e) => {
          if (e !== 'cancel') Message.error((e && e.msg) || '撤销失败');
        })
        .finally(() => {
          this.acting = 0;
        });
    },
  },
};
</script>

<style scoped>
.notifications-workbench {
  padding: 16px;
}
.info-alert {
  margin-bottom: 12px;
}
.publish-panel {
  border-radius: 8px;
}
.panel-title {
  margin: 0 0 14px;
  font-size: 15px;
  color: #303133;
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
</style>
