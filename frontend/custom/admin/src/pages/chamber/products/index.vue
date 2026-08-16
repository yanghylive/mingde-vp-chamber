<template>
  <div class="products-workbench">
    <el-alert
      class="info-alert"
      title="管理积分商城的兑换商品：积分价决定兑换所需积分，现金价为商品现金价，库存为可兑换数量。修改后点击「保存」立即生效；「上架」控制商品是否在小程序积分商城展示。"
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
          <h2>积分商品管理</h2>
          <span>共 {{ total }} 个商品 · 修改后点击「保存」生效</span>
        </div>
        <div class="head-actions">
          <el-input
            v-model="keyword"
            placeholder="搜索商品名称 / 关键词"
            clearable
            size="small"
            class="search-input"
            @keyup.enter.native="loadList"
          >
            <el-button slot="append" icon="el-icon-search" @click="loadList" />
          </el-input>
          <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无商品" class="products-table">
        <el-table-column label="商品" min-width="220">
          <template slot-scope="scope">
            <div class="product-cell">
              <el-image
                v-if="scope.row.image"
                :src="scope.row.image"
                :preview-src-list="[scope.row.image]"
                fit="cover"
                class="product-thumb"
              />
              <div v-else class="product-thumb product-thumb-empty">无图</div>
              <div class="product-meta">
                <div class="primary-cell">{{ scope.row.store_name || '-' }}</div>
                <div class="secondary-cell">ID {{ scope.row.id }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="积分价" width="130" align="center">
          <template slot-scope="scope">
            <el-input-number
              v-model="scope.row.integral_price"
              :min="0"
              :precision="0"
              :step="100"
              controls-position="right"
              class="num-control"
            />
          </template>
        </el-table-column>
        <el-table-column label="现金价(¥)" width="130" align="center">
          <template slot-scope="scope">
            <el-input-number
              v-model="scope.row.price"
              :min="0"
              :precision="2"
              :step="10"
              controls-position="right"
              class="num-control"
            />
          </template>
        </el-table-column>
        <el-table-column label="库存" width="120" align="center">
          <template slot-scope="scope">
            <el-input-number
              v-model="scope.row.stock"
              :min="0"
              :precision="0"
              :step="10"
              controls-position="right"
              class="num-control num-control-sm"
            />
          </template>
        </el-table-column>
        <el-table-column label="上架" width="90" align="center">
          <template slot-scope="scope">
            <el-switch v-model="scope.row.is_show" :active-value="1" :inactive-value="0" />
          </template>
        </el-table-column>
        <el-table-column label="操作" fixed="right" width="100" align="center">
          <template slot-scope="scope">
            <el-button type="text" icon="el-icon-check" :loading="isSaving(scope.row.id)" @click="saveProduct(scope.row)">
              保存
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script>
import { productList, updateProduct } from '@/api/chamber/products';
import memberUi from '@/chamber/shared/member-ui';

function toNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
}

export default {
  name: 'ChamberProducts',
  data() {
    return {
      loading: false,
      loadError: '',
      rows: [],
      total: 0,
      keyword: '',
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
      const params = {};
      if (this.keyword && this.keyword.trim()) params.q = this.keyword.trim();
      return productList(params)
        .then((response) => {
          const result = memberUi.normalizeAdminList(response);
          this.rows = result.list.map((item) => ({
            id: item.id,
            store_name: item.store_name || '',
            image: item.image || '',
            integral_price: toNumber(item.integral_price),
            price: toNumber(item.price),
            stock: toNumber(item.stock),
            is_show: item.is_show ? 1 : 0,
            unit_name: item.unit_name || '件',
            store_info: item.store_info || '',
          }));
          this.total = result.count;
        })
        .catch((error) => {
          this.rows = [];
          this.total = 0;
          this.loadError = this.errorMessage(error, '商品列表加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    isSaving(id) {
      return this.savingIds.includes(id);
    },
    saveProduct(row) {
      if (this.isSaving(row.id)) return;

      const payload = {
        store_name: row.store_name,
        integral_price: toNumber(row.integral_price),
        price: toNumber(row.price),
        stock: toNumber(row.stock),
        is_show: row.is_show ? 1 : 0,
      };

      this.savingIds.push(row.id);
      updateProduct(row.id, payload)
        .then(() => {
          this.$message.success(`「${row.store_name || row.id}」已保存`);
        })
        .catch((error) => {
          this.$message.error(this.errorMessage(error, '商品保存失败，请稍后重试'));
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
.products-workbench {
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

.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.search-input {
  width: 240px;
}

.products-table {
  width: 100%;
}

.product-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.product-thumb {
  width: 48px;
  height: 48px;
  border-radius: 6px;
  flex-shrink: 0;
}

.product-thumb-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f0f3f5;
  color: #9aa4a0;
  font-size: 12px;
}

.product-meta {
  min-width: 0;
}

.primary-cell {
  color: #28342f;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.secondary-cell {
  margin-top: 4px;
  color: #8a948f;
  font-size: 12px;
}

.num-control {
  width: 120px;
}

.num-control-sm {
  width: 104px;
}
</style>
