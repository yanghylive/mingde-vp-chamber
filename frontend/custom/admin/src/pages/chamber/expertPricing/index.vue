<template>
  <div class="pricing-workbench">
    <el-alert
      class="info-alert"
      title="维护大咖在商会商城的咨询定价：线上 / 线下各支持积分与现金两种计价方式。修改任一价格后，点击对应行「保存」立即生效。"
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
          <h2>大咖定价</h2>
          <span>共 {{ total }} 位大咖 · 修改价格后点击「保存」生效</span>
        </div>
        <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无大咖" class="pricing-table">
        <el-table-column label="大咖" min-width="160">
          <template slot-scope="scope">
            <div class="primary-cell">{{ scope.row.name || '-' }}</div>
            <div class="secondary-cell">ID {{ scope.row.id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="角色" min-width="120" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.title || '-' }}</template>
        </el-table-column>
        <el-table-column label="公司" min-width="160" show-overflow-tooltip>
          <template slot-scope="scope">{{ scope.row.company || '-' }}</template>
        </el-table-column>
        <el-table-column label="线上积分" width="140" align="center">
          <template slot-scope="scope">
            <el-input-number
              v-model="scope.row.pricing.online_points"
              :min="0"
              :precision="0"
              :step="10"
              controls-position="right"
              class="price-control"
            />
          </template>
        </el-table-column>
        <el-table-column label="线上现金" width="140" align="center">
          <template slot-scope="scope">
            <el-input-number
              v-model="scope.row.pricing.online_cash"
              :min="0"
              :precision="2"
              :step="10"
              controls-position="right"
              class="price-control"
            />
          </template>
        </el-table-column>
        <el-table-column label="线下积分" width="140" align="center">
          <template slot-scope="scope">
            <el-input-number
              v-model="scope.row.pricing.offline_points"
              :min="0"
              :precision="0"
              :step="10"
              controls-position="right"
              class="price-control"
            />
          </template>
        </el-table-column>
        <el-table-column label="线下现金" width="140" align="center">
          <template slot-scope="scope">
            <el-input-number
              v-model="scope.row.pricing.offline_cash"
              :min="0"
              :precision="2"
              :step="10"
              controls-position="right"
              class="price-control"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" fixed="right" width="100" align="center">
          <template slot-scope="scope">
            <el-button type="text" icon="el-icon-check" :loading="isSaving(scope.row.id)" @click="savePricing(scope.row)">
              保存
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { expertPricingList, updateExpertPricing } from '@/api/chamber/expertPricing';
import memberUi from '@/chamber/shared/member-ui';

const PRICING_FIELDS = ['online_points', 'online_cash', 'offline_points', 'offline_cash'];

function toNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
}

function normalizePricing(pricing) {
  const source = pricing && typeof pricing === 'object' ? pricing : {};
  const normalized = {};
  PRICING_FIELDS.forEach((field) => {
    normalized[field] = toNumber(source[field]);
  });
  return normalized;
}

export default {
  name: 'ChamberExpertPricing',
  data() {
    return {
      loading: false,
      loadError: '',
      rows: [],
      total: 0,
      savingIds: [],
    };
  },
  mounted() {
    this.loadList();
  },
  methods: {
    loadList() {
      this.loading = true;
      this.loadError = '';
      return expertPricingList()
        .then((response) => {
          const result = memberUi.normalizeAdminList(response);
          this.rows = result.list.map((item) => ({
            id: item.id,
            name: item.name || '',
            title: item.title || '',
            company: item.company || '',
            pricing: normalizePricing(item.pricing),
          }));
          this.total = result.count;
        })
        .catch((error) => {
          this.rows = [];
          this.total = 0;
          this.loadError = this.errorMessage(error, '大咖列表加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    isSaving(id) {
      return this.savingIds.includes(id);
    },
    savePricing(row) {
      if (this.isSaving(row.id)) return;

      const payload = {
        online_points: toNumber(row.pricing.online_points),
        online_cash: toNumber(row.pricing.online_cash),
        offline_points: toNumber(row.pricing.offline_points),
        offline_cash: toNumber(row.pricing.offline_cash),
      };

      this.savingIds.push(row.id);
      updateExpertPricing(row.id, payload)
        .then((response) => {
          const result = response && response.data ? response.data : response;
          if (result && result.pricing) {
            row.pricing = normalizePricing(result.pricing);
          }
          this.$message.success(`「${row.name || row.id}」定价已保存`);
        })
        .catch((error) => {
          this.$message.error(this.errorMessage(error, '定价保存失败，请稍后重试'));
        })
        .finally(() => {
          const index = this.savingIds.indexOf(row.id);
          if (index > -1) this.savingIds.splice(index, 1);
        });
    },
    errorMessage(error, fallback) {
      return error && (error.msg || error.message) ? error.msg || error.message : fallback;
    },
  },
};
</script>

<style lang="scss" scoped>
.pricing-workbench {
  min-height: 100%;
  color: #25312c;
}

.info-alert {
  margin-bottom: 16px;
}

.load-alert {
  margin-bottom: 16px;
}

.table-panel {
  border-radius: 6px;
}

.table-head {
  display: flex;
  min-height: 66px;
  padding: 0 20px;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #ebeef0;
}

.table-head h2 {
  margin: 0;
  color: #202b27;
  font-size: 18px;
  font-weight: 600;
  letter-spacing: 0;
}

.table-head span {
  display: block;
  margin-top: 4px;
  color: #7b8580;
  font-size: 12px;
}

.pricing-table {
  width: 100%;
}

.primary-cell {
  color: #28342f;
  font-weight: 500;
}

.secondary-cell {
  margin-top: 4px;
  color: #8a948f;
  font-size: 12px;
}

.price-control {
  width: 128px;
}
</style>
