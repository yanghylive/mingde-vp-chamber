<template>
  <div class="points-paths-workbench">
    <el-alert
      class="info-alert"
      title="配置会员端「积分商城」页展示的积分获取路径（标题 / 单次积分）。保存后会员端立即生效。"
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
          <h2>积分获取路径</h2>
          <span>
            共 {{ rows.length }} 条
            <template v-if="isDefault"> · 当前为默认配置</template>
            <template v-else> · 已自定义</template>
          </span>
        </div>
        <div>
          <el-button icon="el-icon-plus" size="small" @click="addRow">新增路径</el-button>
          <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无路径" class="paths-table">
        <el-table-column label="标题" min-width="200">
          <template slot-scope="scope">
            <el-input v-model="scope.row.title" placeholder="如：做教练 / 开课" maxlength="40" />
          </template>
        </el-table-column>
        <el-table-column label="单次积分" width="160" align="center">
          <template slot-scope="scope">
            <el-input-number v-model="scope.row.points" :min="0" :max="100000" :step="10" style="width: 130px" />
          </template>
        </el-table-column>
        <el-table-column label="图标标识" width="170" align="center">
          <template slot-scope="scope">
            <el-select
              v-model="scope.row.icon"
              placeholder="选择或输入"
              size="small"
              filterable
              allow-create
              default-first-option
              style="width: 140px"
            >
              <el-option label="教练" value="coach" />
              <el-option label="公益" value="charity" />
              <el-option label="路演" value="roadshow" />
              <el-option label="推荐" value="distribution" />
              <el-option label="学习" value="learning" />
              <el-option label="奖章" value="medal" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" align="center">
          <template slot-scope="scope">
            <el-button type="danger" size="mini" icon="el-icon-delete" @click="removeRow(scope.$index)" />
          </template>
        </el-table-column>
      </el-table>

      <div class="table-foot">
        <el-button type="primary" :loading="saving" @click="saveAll">保存配置</el-button>
        <el-button v-if="!isDefault" @click="resetDefault">恢复默认</el-button>
        <span class="foot-hint">保存后会员端商城页立即生效</span>
      </div>
    </el-card>
  </div>
</template>

<script>
import { pointsPathsList, pointsPathsSave } from '@/api/chamber/pointsPaths';
import { Message } from 'element-ui';

export default {
  name: 'ChamberPointsPaths',
  data() {
    return {
      loading: false,
      loadError: '',
      saving: false,
      rows: [],
      isDefault: true,
    };
  },
  created() {
    this.loadList();
  },
  methods: {
    loadList() {
      this.loading = true;
      this.loadError = '';
      pointsPathsList()
        .then((res) => {
          const data = (res && res.data) || {};
          this.rows = (data.items || []).map((p) => Object.assign({}, p));
          this.isDefault = data.is_default !== false;
        })
        .catch((e) => {
          this.loadError = (e && e.msg) || '加载失败';
        })
        .finally(() => {
          this.loading = false;
        });
    },
    addRow() {
      this.rows.push({ code: '', title: '', points: 50, icon: 'distribution' });
    },
    removeRow(index) {
      this.rows.splice(index, 1);
    },
    saveAll() {
      const items = this.rows.map((r) => ({
        code: r.code || '',
        title: (r.title || '').trim(),
        points: Number(r.points) || 0,
        icon: r.icon || '',
      }));
      if (items.length === 0) {
        Message.warning('至少保留一条路径');
        return;
      }
      if (items.some((i) => !i.title)) {
        Message.warning('路径标题不能为空');
        return;
      }
      this.saving = true;
      pointsPathsSave(items)
        .then(() => {
          Message.success('配置已保存，会员端立即生效');
          this.isDefault = false;
        })
        .catch((e) => Message.error((e && e.msg) || '保存失败'))
        .finally(() => {
          this.saving = false;
        });
    },
    resetDefault() {
      // 恢复默认 = 清空配置（后端回退默认 4 条）
      pointsPathsSave([
        { code: 'coach', title: '做教练 / 开课', points: 200, icon: 'coach' },
        { code: 'charity', title: '公益活动', points: 100, icon: 'charity' },
        { code: 'roadshow', title: '项目路演', points: 80, icon: 'roadshow' },
        { code: 'distribution', title: '推荐新会员', points: 50, icon: 'distribution' },
      ])
        .then(() => {
          Message.success('已恢复默认配置');
          this.loadList();
        })
        .catch((e) => Message.error((e && e.msg) || '操作失败'));
    },
  },
};
</script>

<style scoped>
.points-paths-workbench {
  padding: 4px 2px;
}
.info-alert,
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
.table-foot {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  border-top: 1px solid #f0f0f0;
}
.foot-hint {
  font-size: 12px;
  color: #909399;
}
</style>
