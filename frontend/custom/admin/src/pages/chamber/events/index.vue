<template>
  <div class="events-workbench">
    <el-alert
      class="info-alert"
      title="发布与维护商会官方活动：新建活动后点「发布」对会员可见；「签到码」用于现场扫码签到。"
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
          <h2>活动管理</h2>
          <span>共 {{ total }} 个活动</span>
        </div>
        <div>
          <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
          <el-button type="primary" icon="el-icon-plus" @click="openCreate">新建活动</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无活动" class="events-table">
        <el-table-column label="活动" min-width="200">
          <template slot-scope="scope">
            <div class="primary-cell">{{ scope.row.title || '-' }}</div>
            <div class="secondary-cell">ID {{ scope.row.id }} · {{ scope.row.event_no || '' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="110" align="center">
          <template slot-scope="scope">{{ typeLabel(scope.row.event_type) }}</template>
        </el-table-column>
        <el-table-column label="时间" min-width="150">
          <template slot-scope="scope">{{ fmtTime(scope.row.start_time) }}</template>
        </el-table-column>
        <el-table-column label="地点" min-width="140" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.location_name || scope.row.address || '-' }}</template>
        </el-table-column>
        <el-table-column label="签到奖励" width="110" align="center">
          <template slot-scope="scope">
            <span v-if="scope.row.checkin_reward_points">+{{ scope.row.checkin_reward_points }} 积分</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template slot-scope="scope">
            <el-tag :type="scope.row.status === 1 ? 'success' : 'info'" size="mini">
              {{ scope.row.status === 1 ? '已发布' : '草稿' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="260" align="center" fixed="right">
          <template slot-scope="scope">
            <el-button size="mini" @click="openEdit(scope.row)">编辑</el-button>
            <el-button
              v-if="scope.row.status !== 1"
              size="mini"
              type="success"
              :loading="acting === scope.row.id"
              @click="publish(scope.row)"
            >
              发布
            </el-button>
            <el-button
              v-else
              size="mini"
              type="warning"
              :loading="acting === scope.row.id"
              @click="cancelEvent(scope.row)"
            >
              取消
            </el-button>
            <el-button size="mini" type="info" @click="showCheckinToken(scope.row)">签到码</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination">
        <el-pagination
          layout="prev, pager, next"
          :total="total"
          :page-size="pageSize"
          :current-page="page"
          @current-change="(p) => { page = p; loadList(); }"
        />
      </div>
    </el-card>

    <!-- 新建/编辑弹窗 -->
    <el-dialog :title="form.id ? '编辑活动' : '新建活动'" :visible.sync="dialogVisible" width="560px" :close-on-click-modal="false">
      <el-form label-width="92px" :model="form" :rules="rules" ref="formRef">
        <el-form-item label="活动标题" prop="title">
          <el-input v-model="form.title" placeholder="活动名称" maxlength="80" />
        </el-form-item>
        <el-form-item label="活动类型" prop="event_type">
          <el-select v-model="form.event_type" style="width: 100%">
            <el-option label="沙龙" value="salon" />
            <el-option label="工作坊" value="workshop" />
            <el-option label="路演" value="roadshow" />
            <el-option label="公益" value="charity" />
            <el-option label="其他" value="other" />
          </el-select>
        </el-form-item>
        <el-form-item label="开始时间" prop="start_time">
          <el-date-picker
            v-model="form.start_time"
            type="datetime"
            placeholder="选择开始时间"
            value-format="timestamp"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="结束时间" prop="end_time">
          <el-date-picker v-model="form.end_time" type="datetime" placeholder="选择结束时间" value-format="timestamp" style="width: 100%" />
        </el-form-item>
        <el-form-item label="地点名称" prop="location_name">
          <el-input v-model="form.location_name" placeholder="如：沈阳总部 · 2 号会议室" maxlength="80" />
        </el-form-item>
        <el-form-item label="详细地址" prop="address">
          <el-input v-model="form.address" placeholder="详细地址" maxlength="160" />
        </el-form-item>
        <el-form-item label="经纬度" prop="location">
          <div class="geo-row">
            <el-input v-model="form.longitude" placeholder="经度 lng（可选）" />
            <el-input v-model="form.latitude" placeholder="纬度 lat（可选）" />
          </div>
        </el-form-item>
        <el-form-item label="签到奖励">
          <div class="geo-row">
            <el-input-number v-model="form.checkin_reward_points" :min="0" :precision="0" :step="10" placeholder="积分" />
            <el-input-number v-model="form.checkin_reward_contribution" :min="0" :precision="0" :step="10" placeholder="贡献值" />
          </div>
        </el-form-item>
        <el-form-item label="活动简介" prop="summary">
          <el-input v-model="form.summary" type="textarea" :rows="3" placeholder="活动简介" maxlength="500" />
        </el-form-item>
      </el-form>
      <div slot="footer">
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveForm">保存</el-button>
      </div>
    </el-dialog>

    <!-- 签到码弹窗 -->
    <el-dialog title="活动签到码" :visible.sync="tokenVisible" width="420px">
      <p v-if="checkinToken" class="token-box">{{ checkinToken }}</p>
      <p v-else class="token-empty">暂无签到码</p>
      <p class="token-tip">会员现场扫码或在签到页输入此码完成签到，将发放签到奖励。</p>
      <div slot="footer">
        <el-button @click="tokenVisible = false">关闭</el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { eventList, eventCreate, eventUpdate, eventPublish, eventCancel, eventCheckinToken } from '@/api/chamber/events';
import { Message } from 'element-ui';

const TYPE_LABELS = { salon: '沙龙', workshop: '工作坊', roadshow: '路演', charity: '公益', other: '其他' };

export default {
  name: 'ChamberEvents',
  data() {
    return {
      loading: false,
      saving: false,
      acting: 0,
      loadError: '',
      rows: [],
      total: 0,
      page: 1,
      pageSize: 20,
      dialogVisible: false,
      tokenVisible: false,
      checkinToken: '',
      form: {
        id: 0,
        title: '',
        event_type: 'salon',
        start_time: '',
        end_time: '',
        location_name: '',
        address: '',
        longitude: '',
        latitude: '',
        checkin_reward_points: 50,
        checkin_reward_contribution: 10,
        summary: '',
      },
      rules: {
        title: [{ required: true, message: '请输入活动标题', trigger: 'blur' }],
        event_type: [{ required: true, message: '请选择活动类型', trigger: 'change' }],
        start_time: [{ required: true, message: '请选择开始时间', trigger: 'change' }],
      },
    };
  },
  created() {
    this.loadList();
  },
  methods: {
    typeLabel(t) {
      return TYPE_LABELS[t] || t || '-';
    },
    fmtTime(ts) {
      if (!ts) return '-';
      const d = new Date(Number(ts) * 1000);
      const p = (n) => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    },
    loadList() {
      this.loading = true;
      this.loadError = '';
      eventList({ page: this.page, limit: this.pageSize })
        .then((res) => {
          const data = res.data || {};
          this.rows = Array.isArray(data.items) ? data.items : [];
          this.total = data.total || this.rows.length;
        })
        .catch((e) => {
          this.loadError = (e && e.msg) || '加载失败';
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openCreate() {
      this.form = {
        id: 0,
        title: '',
        event_type: 'salon',
        start_time: '',
        end_time: '',
        location_name: '',
        address: '',
        longitude: '',
        latitude: '',
        checkin_reward_points: 50,
        checkin_reward_contribution: 10,
        summary: '',
      };
      this.dialogVisible = true;
    },
    openEdit(row) {
      this.form = {
        id: row.id,
        title: row.title || '',
        event_type: row.event_type || 'salon',
        start_time: row.start_time,
        end_time: row.end_time,
        location_name: row.location_name || '',
        address: row.address || '',
        longitude: row.longitude != null ? String(row.longitude) : '',
        latitude: row.latitude != null ? String(row.latitude) : '',
        checkin_reward_points: row.checkin_reward_points || 0,
        checkin_reward_contribution: row.checkin_reward_contribution || 0,
        summary: row.summary || '',
      };
      this.dialogVisible = true;
    },
    saveForm() {
      this.$refs.formRef.validate((valid) => {
        if (!valid) return;
        this.saving = true;
        const payload = {
          title: this.form.title,
          event_type: this.form.event_type,
          start_time: this.form.start_time ? Number(this.form.start_time) : 0,
          end_time: this.form.end_time ? Number(this.form.end_time) : 0,
          location_name: this.form.location_name,
          address: this.form.address,
          longitude: this.form.longitude ? String(this.form.longitude) : '',
          latitude: this.form.latitude ? String(this.form.latitude) : '',
          checkin_reward_points: this.form.checkin_reward_points || 0,
          checkin_reward_contribution: this.form.checkin_reward_contribution || 0,
          summary: this.form.summary,
        };
        const req = this.form.id ? eventUpdate(this.form.id, payload) : eventCreate(payload);
        req
          .then(() => {
            Message.success('活动已保存');
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
    publish(row) {
      this.acting = row.id;
      eventPublish(row.id)
        .then(() => {
          Message.success('活动已发布');
          this.loadList();
        })
        .catch((e) => Message.error((e && e.msg) || '发布失败'))
        .finally(() => {
          this.acting = 0;
        });
    },
    cancelEvent(row) {
      this.$confirm(`确定取消活动「${row.title}」？`, '提示', { type: 'warning' })
        .then(() => eventCancel(row.id))
        .then(() => {
          Message.success('活动已取消');
          this.loadList();
        })
        .catch(() => {});
    },
    showCheckinToken(row) {
      this.checkinToken = '';
      this.tokenVisible = true;
      eventCheckinToken(row.id)
        .then((res) => {
          const data = res.data || {};
          this.checkinToken = data.token || data.checkin_token || '';
        })
        .catch((e) => Message.error((e && e.msg) || '获取签到码失败'));
    },
  },
};
</script>

<style scoped>
.events-workbench {
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
.pagination {
  display: flex;
  justify-content: flex-end;
  padding: 14px 16px;
}
.geo-row {
  display: flex;
  gap: 10px;
}
.geo-row .el-input {
  flex: 1;
}
.token-box {
  padding: 16px;
  border: 1px dashed #d0d7de;
  border-radius: 8px;
  background: #f8f9fa;
  font-family: monospace;
  font-size: 16px;
  text-align: center;
  letter-spacing: 2px;
  word-break: break-all;
}
.token-empty {
  color: #909399;
  text-align: center;
}
.token-tip {
  margin-top: 10px;
  font-size: 12px;
  color: #909399;
}
</style>
