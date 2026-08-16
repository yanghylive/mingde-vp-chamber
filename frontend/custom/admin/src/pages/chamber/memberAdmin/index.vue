<template>
  <div class="member-admin-workbench">
    <el-alert
      class="info-alert"
      title="会员等级管理：L1 免费 / L2 ¥1000 年费 / L3 ¥5000 年费 / L4 认证（人工指定）。调整后立即生效。"
      type="info"
      show-icon
      :closable="false"
    />

    <el-alert v-if="loadError" class="load-alert" type="error" :title="loadError" show-icon :closable="false">
      <el-button slot="description" size="mini" @click="loadList">重新加载</el-button>
    </el-alert>

    <el-tabs v-model="activeTab" @tab-click="onTabChange">
      <!-- 会员列表 -->
      <el-tab-pane label="会员列表" name="members">
        <el-card shadow="never" class="table-panel" :body-style="{ padding: 0 }">
          <div class="table-head">
            <div>
              <h2>会员</h2>
              <span>共 {{ rows.length }} 人 · 等级调整后立即生效</span>
            </div>
            <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
          </div>

          <el-table v-loading="loading" :data="rows" empty-text="暂无会员" class="member-table">
            <el-table-column label="会员" min-width="140">
              <template slot-scope="scope">
                <div class="primary-cell">{{ scope.row.name || '-' }}</div>
                <div class="secondary-cell">会员ID {{ scope.row.id }} · UID {{ scope.row.uid }}</div>
              </template>
            </el-table-column>
            <el-table-column label="手机号" min-width="120">
              <template slot-scope="scope">{{ scope.row.phone || '-' }}</template>
            </el-table-column>
            <el-table-column label="等级" width="100" align="center">
              <template slot-scope="scope">
                <el-tag
                  :type="tagType(scope.row.tier)"
                  size="mini"
                  :class="{ 'expired-tag': scope.row.is_expired === 1 }"
                >
                  {{ scope.row.tier_label }}
                  <span v-if="scope.row.is_expired === 1">（已过期）</span>
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="到期时间" min-width="150">
              <template slot-scope="scope">
                <span v-if="scope.row.tier > 1 && scope.row.expire_time">
                  {{ fmtTime(scope.row.expire_time) }}
                </span>
                <span v-else class="muted">—</span>
              </template>
            </el-table-column>
            <el-table-column label="认证" width="80" align="center">
              <template slot-scope="scope">
                <el-tag :type="scope.row.verification_status === 1 ? 'success' : 'info'" size="mini">
                  {{ scope.row.verification_status === 1 ? '已认证' : '未认证' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="340" align="center" fixed="right">
              <template slot-scope="scope">
                <el-button type="primary" size="mini" @click="openAdjust(scope.row)">等级调整</el-button>
                <el-button type="success" size="mini" @click="openPointsAdjust(scope.row)">积分调整</el-button>
                <el-button type="info" size="mini" @click="openNumbers(scope.row)">番号</el-button>
                <el-button v-if="scope.row.tier !== 4" type="warning" size="mini" @click="certifyL4(scope.row)"
                  >指定 L4</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-tab-pane>

      <!-- 订单记录 -->
      <el-tab-pane label="订单记录" name="orders">
        <el-card shadow="never" class="table-panel" :body-style="{ padding: 0 }">
          <div class="table-head">
            <div>
              <h2>会员订单</h2>
              <span>共 {{ orders.length }} 笔 · 收入合计 ¥{{ incomeTotal }}</span>
            </div>
            <el-button icon="el-icon-refresh" circle title="刷新" :loading="ordersLoading" @click="loadOrders" />
          </div>

          <el-table v-loading="ordersLoading" :data="orders" empty-text="暂无订单" class="order-table">
            <el-table-column label="订单号" min-width="180">
              <template slot-scope="scope">{{ scope.row.order_no }}</template>
            </el-table-column>
            <el-table-column label="会员" min-width="120">
              <template slot-scope="scope">ID {{ scope.row.member_id }}</template>
            </el-table-column>
            <el-table-column label="等级" width="80" align="center">
              <template slot-scope="scope">L{{ scope.row.tier }}</template>
            </el-table-column>
            <el-table-column label="金额" width="100" align="right">
              <template slot-scope="scope">
                <b>¥{{ scope.row.amount_yuan }}</b>
              </template>
            </el-table-column>
            <el-table-column label="支付方式" width="100" align="center">
              <template slot-scope="scope">{{ payTypeLabel(scope.row.pay_type) }}</template>
            </el-table-column>
            <el-table-column label="状态" width="90" align="center">
              <template slot-scope="scope">
                <el-tag :type="orderStatusType(scope.row.status)" size="mini">{{
                  orderStatusLabel(scope.row.status)
                }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="备注" min-width="160" show-overflow-tooltip>
              <template slot-scope="scope">{{ scope.row.remark || '-' }}</template>
            </el-table-column>
            <el-table-column label="下单时间" min-width="150">
              <template slot-scope="scope">{{ fmtTime(scope.row.add_time) }}</template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- 等级调整弹窗 -->
    <el-dialog title="等级调整" :visible.sync="dialogVisible" width="460px" :close-on-click-modal="false">
      <el-form label-width="90px" :model="form" ref="formRef">
        <el-form-item label="会员">
          <span class="member-name">{{ form.name || '-' }}</span>
          <span class="member-sub">（会员ID {{ form.id }} · 当前 {{ form.tier_label }}）</span>
        </el-form-item>
        <el-form-item label="调整等级" required>
          <el-radio-group v-model="form.tier">
            <el-radio :label="1">L1 免费</el-radio>
            <el-radio :label="2">L2 付费（+1年）</el-radio>
            <el-radio :label="3">L3 高会（+1年）</el-radio>
            <el-radio :label="4">L4 认证</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" placeholder="如：线下转账开通 / 年度续费 / 理事会指定" maxlength="100" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="saveAdjust">保存调整</el-button>
          <el-button @click="dialogVisible = false">取消</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>

    <!-- 积分调整弹窗 -->
    <el-dialog title="积分调整" :visible.sync="pointsDialogVisible" width="480px" :close-on-click-modal="false">
      <el-form label-width="90px" :model="pointsForm" ref="pointsFormRef">
        <el-form-item label="会员">
          <span class="member-name">{{ pointsForm.name || '-' }}</span>
          <span class="member-sub">（会员ID {{ pointsForm.id }}）</span>
        </el-form-item>
        <el-form-item label="调整数值" required>
          <el-input-number v-model="pointsForm.delta" :min="-1000000" :max="1000000" :step="50" style="width: 180px" />
          <span class="points-hint">正数=增发，负数=扣减（单次 ±100 万以内）</span>
        </el-form-item>
        <el-form-item label="调整原因" required>
          <el-input
            v-model="pointsForm.reason"
            type="textarea"
            :rows="2"
            placeholder="必填：如 开业活动积分补偿 / 运营失误扣回 / 积分兑换售后"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="幂等键" label-width="90px">
          <el-input v-model="pointsForm.callerKey" placeholder="留空自动生成（重复提交不会重复入账）" maxlength="120" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="pointsSaving" @click="submitPointsAdjust">确认调整</el-button>
          <el-button @click="pointsDialogVisible = false">取消</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>

    <!-- 会员番号弹窗（后台录入多个，前台会员选一个展示） -->
    <el-dialog title="会员番号" :visible.sync="numbersDialogVisible" width="560px" :close-on-click-modal="false">
      <el-form label-width="80px">
        <el-form-item label="会员">
          <span class="member-name">{{ numberForm.name || '-' }}</span>
          <span class="member-sub">（会员ID {{ numberForm.id }}）</span>
        </el-form-item>
        <el-form-item label="番号列表">
          <div class="number-list">
            <div v-for="(n, i) in numberForm.numbers" :key="i" class="number-row">
              <el-input v-model="n.number" placeholder="番号（如 MD-2024-001）" size="small" />
              <el-input v-model="n.label" placeholder="标签（如 商会会员号）" size="small" />
              <el-button type="danger" size="mini" icon="el-icon-delete" circle @click="numberForm.numbers.splice(i, 1)" />
            </div>
          </div>
          <el-button type="text" icon="el-icon-plus" @click="addNumber">添加番号</el-button>
          <div class="number-hint">会员可从前台「我的」页选择其中一个作为对外展示番号</div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="numbersSaving" @click="saveNumbers">保存番号</el-button>
          <el-button @click="numbersDialogVisible = false">取消</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>
  </div>
</template>

<script>
import { memberList, memberUpdate, memberOrders, memberAdjustPoints, memberNumbers, memberNumbersUpdate } from '@/api/chamber/members';
import { Message, MessageBox } from 'element-ui';

export default {
  name: 'ChamberMemberAdmin',
  data() {
    return {
      activeTab: 'members',
      loading: false,
      loadError: '',
      rows: [],
      orders: [],
      ordersLoading: false,
      dialogVisible: false,
      saving: false,
      form: { id: 0, name: '', tier: 1, tier_label: '', remark: '' },
      pointsDialogVisible: false,
      pointsSaving: false,
      pointsForm: { id: 0, name: '', delta: 100, reason: '', callerKey: '' },
      numbersDialogVisible: false,
      numbersSaving: false,
      numberForm: { id: 0, name: '', numbers: [] },
    };
  },
  computed: {
    incomeTotal() {
      return this.orders
        .filter((o) => o.status === 1)
        .reduce((s, o) => s + (Number(o.amount_yuan) || 0), 0)
        .toFixed(2);
    },
  },
  created() {
    this.loadList();
    this.loadOrders();
  },
  methods: {
    loadList() {
      this.loading = true;
      this.loadError = '';
      memberList()
        .then((res) => {
          const data = (res && res.data) || {};
          this.rows = (data.items || data.list || []).map((m) => Object.assign({}, m));
        })
        .catch((e) => {
          this.loadError = (e && e.msg) || '加载失败';
        })
        .finally(() => {
          this.loading = false;
        });
    },
    loadOrders() {
      this.ordersLoading = true;
      memberOrders()
        .then((res) => {
          const data = (res && res.data) || {};
          this.orders = (data.items || data.list || []).map((o) => Object.assign({}, o));
        })
        .catch(() => {})
        .finally(() => {
          this.ordersLoading = false;
        });
    },
    onTabChange(tab) {
      if (tab && tab.name === 'orders') this.loadOrders();
    },
    openAdjust(row) {
      this.form = { id: row.id, name: row.name, tier: row.tier, tier_label: row.tier_label, remark: '' };
      this.dialogVisible = true;
    },
    saveAdjust() {
      this.saving = true;
      memberUpdate(this.form.id, {
        tier: this.form.tier,
        action: this.form.tier === 4 ? 'certify' : 'adjust',
        remark: this.form.remark,
      })
        .then(() => {
          Message.success('等级已调整');
          this.dialogVisible = false;
          this.loadList();
          this.loadOrders();
        })
        .catch((e) => Message.error((e && e.msg) || '调整失败'))
        .finally(() => {
          this.saving = false;
        });
    },
    certifyL4(row) {
      MessageBox.confirm(
        `确认将「${row.name || 'ID ' + row.id}」指定为 L4 认证会员？将同时标记为已认证。`,
        '指定 L4 认证',
        { type: 'warning', confirmButtonText: '确认指定', cancelButtonText: '取消' },
      )
        .then(() => {
          this.saving = true;
          memberUpdate(row.id, { tier: 4, action: 'certify', remark: 'L4 人工认证指定' })
            .then(() => {
              Message.success('已指定为 L4 认证会员');
              this.loadList();
            })
            .catch((e) => Message.error((e && e.msg) || '操作失败'))
            .finally(() => {
              this.saving = false;
            });
        })
        .catch(() => {});
    },
    openPointsAdjust(row) {
      this.pointsForm = {
        id: row.id,
        name: row.name || 'ID ' + row.id,
        delta: 100,
        reason: '',
        callerKey: '',
      };
      this.pointsDialogVisible = true;
    },
    submitPointsAdjust() {
      const { id, delta, reason, callerKey } = this.pointsForm;
      if (!delta) {
        Message.warning('调整数值不能为 0');
        return;
      }
      if (!reason || !reason.trim()) {
        Message.warning('请填写调整原因');
        return;
      }
      this.pointsSaving = true;
      memberAdjustPoints(id, {
        delta,
        reason: reason.trim(),
        caller_key: callerKey.trim() || `admin-points-${Date.now()}`,
      })
        .then((res) => {
          const data = (res && res.data) || {};
          this.pointsDialogVisible = false;
          Message.success(
            `调整成功：当前积分 ${data.balance != null ? data.balance : '-'}${
              data.idempotent ? '（幂等命中，未重复入账）' : ''
            }`,
          );
        })
        .catch((e) => Message.error((e && e.msg) || '调整失败'))
        .finally(() => {
          this.pointsSaving = false;
        });
    },
    openNumbers(row) {
      this.numberForm = { id: row.id, name: row.name || 'ID ' + row.id, numbers: [] };
      memberNumbers(row.id)
        .then((res) => {
          const items = (res && res.data && res.data.items) || [];
          this.numberForm.numbers = items.map((n) => ({ number: n.number || '', label: n.label || '' }));
          if (this.numberForm.numbers.length === 0) this.numberForm.numbers.push({ number: '', label: '' });
        })
        .catch(() => {
          this.numberForm.numbers = [{ number: '', label: '' }];
        });
      this.numbersDialogVisible = true;
    },
    addNumber() {
      this.numberForm.numbers.push({ number: '', label: '' });
    },
    saveNumbers() {
      const numbers = this.numberForm.numbers
        .map((n) => ({ number: (n.number || '').trim(), label: (n.label || '').trim() }))
        .filter((n) => n.number);
      this.numbersSaving = true;
      memberNumbersUpdate(this.numberForm.id, numbers)
        .then(() => {
          Message.success('番号已保存');
          this.numbersDialogVisible = false;
        })
        .catch((e) => Message.error((e && e.msg) || '保存失败'))
        .finally(() => {
          this.numbersSaving = false;
        });
    },
    tagType(tier) {
      return ['', 'info', 'warning', 'danger', 'success'][tier] || 'info';
    },
    payTypeLabel(t) {
      return { wechat: '微信支付', ali: '支付宝', manual: '手动开通' }[t] || t || '-';
    },
    orderStatusLabel(s) {
      return ['待支付', '已支付', '已取消', '已退款'][s] || '未知';
    },
    orderStatusType(s) {
      return ['warning', 'success', 'info', 'danger'][s] || 'info';
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
.member-admin-workbench {
  padding: 4px 2px;
}
.info-alert {
  margin-bottom: 12px;
}
.load-alert {
  margin-bottom: 12px;
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
.primary-cell {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
}
.secondary-cell {
  font-size: 11px;
  color: #a0a6b0;
  margin-top: 2px;
}
.muted {
  color: #b0b6c0;
}
.member-name {
  font-weight: 600;
}
.member-sub {
  color: #909399;
  font-size: 12px;
  margin-left: 6px;
}
.expired-tag {
  opacity: 0.7;
}
.number-list {
  width: 100%;
  max-height: 260px;
  overflow-y: auto;
}
.number-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.number-row >>> .el-input {
  flex: 1;
}
.number-hint {
  font-size: 12px;
  color: #909399;
  margin-top: 6px;
}
</style>
