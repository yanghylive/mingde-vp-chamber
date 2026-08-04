<template>
  <div class="slots-workbench">
    <el-alert
      class="info-alert"
      title="为大咖开放可预约档期（日期 + 时段 + 线上/线下）。保存后会员端大咖详情页立即显示可预约。"
      type="info"
      show-icon
      :closable="false"
    />

    <el-alert v-if="loadError" class="load-alert" type="error" :title="loadError" show-icon :closable="false">
      <el-button slot="description" size="mini" @click="loadList">重新加载</el-button>
    </el-alert>

    <!-- 新增档期 -->
    <el-card shadow="never" class="create-panel">
      <div class="create-head">
        <h2>开放档期</h2>
        <span>为指定大咖添加一个可预约时段</span>
      </div>
      <el-form label-width="72px" :inline="true" class="create-form">
        <el-form-item label="大咖">
          <el-select v-model="form.expert_id" placeholder="选择大咖" filterable style="width: 160px">
            <el-option v-for="e in experts" :key="e.id" :label="e.name" :value="e.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="日期">
          <el-date-picker v-model="form.date" type="date" placeholder="选择日期" value-format="yyyy-MM-dd" style="width: 150px" />
        </el-form-item>
        <el-form-item label="开始">
          <el-time-picker v-model="form.start" placeholder="开始时间" format="HH:mm" value-format="HH:mm" style="width: 110px" />
        </el-form-item>
        <el-form-item label="结束">
          <el-time-picker v-model="form.end" placeholder="结束时间" format="HH:mm" value-format="HH:mm" style="width: 110px" />
        </el-form-item>
        <el-form-item label="方式">
          <el-radio-group v-model="form.location">
            <el-radio :label="0">线上</el-radio>
            <el-radio :label="1">线下</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="createSlot">开放档期</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 档期列表 -->
    <el-card shadow="never" class="table-panel" :body-style="{ padding: 0 }">
      <div class="table-head">
        <div>
          <h2>档期列表</h2>
          <span>共 {{ rows.length }} 个档期 · 已预约的档期不可删除</span>
        </div>
        <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无档期，请先开放" class="slots-table">
        <el-table-column label="大咖" min-width="110">
          <template slot-scope="scope">{{ scope.row.expert_name }}</template>
        </el-table-column>
        <el-table-column label="开始时间" min-width="150">
          <template slot-scope="scope">{{ fmtTime(scope.row.start_time) }}</template>
        </el-table-column>
        <el-table-column label="结束时间" min-width="150">
          <template slot-scope="scope">{{ fmtTime(scope.row.end_time) }}</template>
        </el-table-column>
        <el-table-column label="方式" width="80" align="center">
          <template slot-scope="scope">
            <el-tag :type="scope.row.location === 1 ? 'warning' : 'info'" size="mini">{{ scope.row.location_label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template slot-scope="scope">
            <el-tag :type="scope.row.status === 'open' ? 'success' : 'danger'" size="mini">
              {{ scope.row.status === 'open' ? '可约' : '已约' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" align="center">
          <template slot-scope="scope">
            <el-button
              type="danger"
              size="mini"
              :disabled="scope.row.status !== 'open'"
              @click="removeSlot(scope.row)"
            >删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { slotList, slotCreate, slotDelete } from '@/api/chamber/slots';
import { expertPricingList } from '@/api/chamber/expertPricing';
import { Message, MessageBox } from 'element-ui';

export default {
  name: 'ChamberSlots',
  data() {
    return {
      loading: false,
      loadError: '',
      saving: false,
      rows: [],
      experts: [],
      form: {
        expert_id: undefined,
        date: '',
        start: '',
        end: '',
        location: 0,
      },
    };
  },
  created() {
    this.loadExperts();
    this.loadList();
  },
  methods: {
    loadExperts() {
      expertPricingList()
        .then((res) => {
          const data = (res && res.data) || {};
          this.experts = (data.items || data.list || []).map((e) => ({
            id: e.id,
            name: e.name || '大咖#' + e.id,
          }));
        })
        .catch(() => {});
    },
    loadList() {
      this.loading = true;
      this.loadError = '';
      slotList()
        .then((res) => {
          const data = (res && res.data) || {};
          this.rows = (data.items || []).map((s) => Object.assign({}, s));
        })
        .catch((e) => {
          this.loadError = (e && e.msg) || '加载失败';
        })
        .finally(() => {
          this.loading = false;
        });
    },
    createSlot() {
      const { expert_id, date, start, end, location } = this.form;
      if (!expert_id || !date || !start || !end) {
        Message.warning('请完整填写大咖、日期、开始和结束时间');
        return;
      }
      const startTs = new Date(`${date}T${start}:00`).getTime() / 1000;
      const endTs = new Date(`${date}T${end}:00`).getTime() / 1000;
      if (endTs <= startTs) {
        Message.warning('结束时间需晚于开始时间');
        return;
      }
      this.saving = true;
      slotCreate({ expert_id, start_time: startTs, end_time: endTs, location })
        .then(() => {
          Message.success('档期已开放');
          this.loadList();
          this.form = { expert_id: undefined, date: '', start: '', end: '', location: 0 };
        })
        .catch((e) => Message.error((e && e.msg) || '开放失败'))
        .finally(() => {
          this.saving = false;
        });
    },
    removeSlot(row) {
      MessageBox.confirm(`确认删除 ${row.expert_name} 的档期？`, '删除档期', { type: 'warning' })
        .then(() =>
          slotDelete(row.id)
            .then(() => {
              Message.success('已删除');
              this.loadList();
            })
            .catch((e) => Message.error((e && e.msg) || '删除失败')),
        )
        .catch(() => {});
    },
    fmtTime(ts) {
      if (!ts) return '-';
      const d = new Date(ts * 1000);
      const p = (n) => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    },
  },
};
</script>

<style scoped>
.slots-workbench {
  padding: 4px 2px;
}
.info-alert,
.load-alert {
  margin-bottom: 12px;
}
.create-panel {
  margin-bottom: 12px;
}
.create-head {
  margin-bottom: 14px;
}
.create-head h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.create-head span {
  font-size: 12px;
  color: #909399;
  margin-left: 8px;
}
.create-form {
  margin-bottom: -12px;
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
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.table-head span {
  font-size: 12px;
  color: #909399;
  margin-left: 8px;
}
</style>
