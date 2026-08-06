<template>
  <div class="verification-workbench">
    <el-card shadow="never" class="filter-panel" :body-style="{ padding: '18px 20px 4px' }">
      <el-form :model="filters" inline @submit.native.prevent>
        <el-form-item label="申请状态">
          <el-select v-model="filters.status" clearable placeholder="全部状态" class="filter-control" @change="search">
            <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="搜索">
          <el-input
            v-model.trim="filters.keyword"
            clearable
            class="keyword-control"
            placeholder="申请号、姓名或班级"
            @keyup.enter.native="search"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" icon="el-icon-search" @click="search">查询</el-button>
          <el-button icon="el-icon-refresh" @click="resetFilters">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-alert v-if="loadError" class="load-alert" type="error" :title="loadError" show-icon :closable="false">
      <el-button slot="description" size="mini" @click="loadList">重新加载</el-button>
    </el-alert>

    <el-card shadow="never" class="table-panel" :body-style="{ padding: 0 }">
      <div class="table-head">
        <div>
          <h2>毕业认证审核</h2>
          <span>共 {{ total }} 条申请</span>
        </div>
        <el-button icon="el-icon-refresh" circle title="刷新" :loading="loading" @click="loadList" />
      </div>

      <el-table v-loading="loading" :data="rows" empty-text="暂无认证申请" class="verification-table">
        <el-table-column label="申请" min-width="180">
          <template slot-scope="scope">
            <div class="primary-cell">
              {{ scope.row.application_no || '-' }}
            </div>
            <div class="secondary-cell">ID {{ scope.row.id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="申请人" min-width="140">
          <template slot-scope="scope">
            <div class="primary-cell">{{ memberName(scope.row) }}</div>
            <div v-if="memberReference(scope.row)" class="secondary-cell">
              {{ memberReference(scope.row) }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="毕业信息" min-width="160">
          <template slot-scope="scope">
            <div class="primary-cell">{{ scope.row.class_name || '-' }}</div>
            <div class="secondary-cell">
              {{ scope.row.graduation_year || '-' }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="材料" width="80" align="center">
          <template slot-scope="scope">{{ proofCount(scope.row) }}</template>
        </el-table-column>
        <el-table-column label="提交时间" min-width="150">
          <template slot-scope="scope">{{ formatTime(scope.row.submitted_at) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template slot-scope="scope">
            <el-tag :type="statusMeta(scope.row.status).tone" effect="plain" size="small">
              {{ statusMeta(scope.row.status).label }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" fixed="right" width="240">
          <template slot-scope="scope">
            <el-button type="text" icon="el-icon-view" @click="openDetail(scope.row)">详情</el-button>
            <template v-for="action in rowActions(scope.row)">
              <el-divider :key="action.value + '-divider'" direction="vertical" />
              <el-button
                :key="action.value"
                type="text"
                :class="'action-' + action.tone"
                @click="openReview(scope.row, action.value)"
              >
                {{ action.label }}
              </el-button>
            </template>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="total" class="pagination-row">
        <pagination :total="total" :page.sync="filters.page" :limit.sync="filters.per_page" @pagination="loadList" />
      </div>
    </el-card>

    <el-drawer
      :visible.sync="drawerVisible"
      title="认证申请详情"
      :size="drawerSize"
      :wrapper-closable="false"
      @closed="closeDetail"
    >
      <div v-loading="detailLoading" class="detail-drawer">
        <el-alert
          v-if="detailError"
          :title="detailError"
          type="warning"
          show-icon
          :closable="false"
          class="detail-alert"
        />

        <template v-if="selected">
          <div class="detail-heading">
            <div>
              <span class="detail-overline">申请编号</span>
              <strong>{{ selected.application_no || '-' }}</strong>
            </div>
            <el-tag :type="statusMeta(selected.status).tone" effect="plain">
              {{ statusMeta(selected.status).label }}
            </el-tag>
          </div>

          <section class="detail-section">
            <h3>申请信息</h3>
            <dl class="detail-grid">
              <div>
                <dt>申请人</dt>
                <dd>{{ memberName(selected) }}</dd>
              </div>
              <div>
                <dt>会员标识</dt>
                <dd>{{ memberReference(selected) || '-' }}</dd>
              </div>
              <div>
                <dt>班级</dt>
                <dd>{{ selected.class_name || '-' }}</dd>
              </div>
              <div>
                <dt>毕业年份</dt>
                <dd>{{ selected.graduation_year || '-' }}</dd>
              </div>
              <div>
                <dt>毕业日期</dt>
                <dd>{{ formatDate(selected.graduation_at) }}</dd>
              </div>
              <div>
                <dt>提交时间</dt>
                <dd>{{ formatTime(selected.submitted_at) }}</dd>
              </div>
              <div>
                <dt>审核时间</dt>
                <dd>{{ formatTime(selected.reviewed_at) }}</dd>
              </div>
            </dl>
          </section>

          <section class="detail-section">
            <h3>证明材料</h3>
            <div v-if="proofCount(selected)" class="proof-list">
              <div v-for="asset in proofAssets(selected)" :key="asset.object_key" class="proof-item">
                <div>
                  <strong>{{ asset.original_name }}</strong>
                  <span>{{ asset.mime_type || '未知类型' }}<template v-if="asset.size"> · {{ humanFileSize(asset.size) }}</template></span>
                </div>
                <el-button
                  v-if="asset.id && asset.available"
                  type="text"
                  icon="el-icon-document"
                  :loading="openingAssetId === asset.id"
                  :disabled="openingAssetId === asset.id"
                  @click="openProofAsset(asset)"
                >
                  打开
                </el-button>
                <span v-else class="proof-unavailable">无可用预览</span>
              </div>
            </div>
            <el-empty v-else :image-size="64" description="暂无证明材料" />
          </section>

          <section v-if="selected.review_note" class="detail-section">
            <h3>审核意见</h3>
            <p class="review-note">{{ selected.review_note }}</p>
          </section>

          <div v-if="rowActions(selected).length" class="drawer-actions">
            <el-button
              v-for="action in rowActions(selected)"
              :key="action.value"
              :type="buttonType(action.tone)"
              @click="openReview(selected, action.value)"
            >
              {{ action.label }}
            </el-button>
          </div>
        </template>
      </div>
    </el-drawer>

    <el-dialog
      :visible.sync="reviewVisible"
      :title="reviewDefinition ? reviewDefinition.label : '审核'"
      width="520px"
      custom-class="review-dialog"
      :close-on-click-modal="false"
      @closed="resetReview"
    >
      <el-form label-position="top" @submit.native.prevent>
        <el-form-item
          label="审核意见"
          :required="reviewDefinition && reviewDefinition.noteRequired"
          :error="reviewError"
        >
          <el-input
            v-model="reviewNote"
            type="textarea"
            :rows="5"
            maxlength="500"
            show-word-limit
            placeholder="填写审核结论或需补充的材料"
          />
        </el-form-item>
      </el-form>
      <div slot="footer">
        <el-button @click="reviewVisible = false">取消</el-button>
        <el-button
          :type="reviewDefinition ? buttonType(reviewDefinition.tone) : 'primary'"
          :loading="reviewing"
          @click="submitReview"
        >
          确认{{ reviewDefinition ? reviewDefinition.label : '' }}
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import {
  graduateVerificationAssetContent,
  graduateVerificationDetail,
  graduateVerificationList,
  reviewGraduateVerification,
} from '@/api/chamber/graduateVerification';
import memberUi from '@/chamber/shared/member-ui';

export default {
  name: 'ChamberGraduateVerification',
  data() {
    return {
      loading: false,
      loadError: '',
      rows: [],
      total: 0,
      filters: {
        status: '',
        keyword: '',
        page: 1,
        per_page: 20,
      },
      statusOptions: Object.keys(memberUi.STATUS_META).map((value) => ({
        value,
        label: memberUi.STATUS_META[value].label,
      })),
      drawerVisible: false,
      detailLoading: false,
      detailError: '',
      selected: null,
      reviewVisible: false,
      reviewing: false,
      reviewAction: '',
      reviewNote: '',
      reviewError: '',
      reviewTarget: null,
      pendingReviewKey: '',
      pendingReviewFingerprint: '',
      openingAssetId: 0,
    };
  },
  computed: {
    drawerSize() {
      return typeof document !== 'undefined' && document.documentElement.clientWidth < 768 ? '100%' : '620px';
    },
    reviewDefinition() {
      return memberUi.REVIEW_ACTIONS[this.reviewAction] || null;
    },
  },
  mounted() {
    this.loadList();
  },
  methods: {
    queryParams() {
      const params = {
        page: this.filters.page,
        per_page: this.filters.per_page,
      };
      if (this.filters.status) params.status = this.filters.status;
      if (this.filters.keyword) params.keyword = this.filters.keyword;
      return params;
    },
    loadList() {
      this.loading = true;
      this.loadError = '';
      return graduateVerificationList(this.queryParams())
        .then((response) => {
          const result = memberUi.normalizeAdminList(response);
          this.rows = result.list;
          this.total = result.count;
        })
        .catch((error) => {
          this.rows = [];
          this.total = 0;
          this.loadError = this.errorMessage(error, '认证申请加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    search() {
      this.filters.page = 1;
      this.loadList();
    },
    resetFilters() {
      this.filters.status = '';
      this.filters.keyword = '';
      this.filters.page = 1;
      this.loadList();
    },
    statusMeta(status) {
      return memberUi.verificationStatusMeta(status);
    },
    rowActions(row) {
      const actions = memberUi.reviewActionsForStatus(row && row.status);
      const hasUnavailableProof = this.proofAssets(row).some((asset) => !asset.available);
      return hasUnavailableProof ? actions.filter((action) => action.value !== 'approve') : actions;
    },
    proofCount(row) {
      return this.proofAssets(row).length;
    },
    proofAssets(row) {
      return memberUi.proofAssetsFromApplication(row || {});
    },
    humanFileSize(size) {
      return memberUi.humanFileSize(size);
    },
    memberName(row) {
      if (!row) return '-';
      return row.member_name || row.real_name || (row.member && (row.member.real_name || row.member.nickname)) || '-';
    },
    memberReference(row) {
      if (!row) return '';
      const uid = row.uid || (row.member && row.member.uid);
      const memberId = row.member_id || (row.member && row.member.id);
      return uid ? `UID ${uid}` : memberId ? `会员 ${memberId}` : '';
    },
    openDetail(row) {
      this.selected = row;
      this.drawerVisible = true;
      this.detailLoading = true;
      this.detailError = '';
      graduateVerificationDetail(row.id)
        .then((response) => {
          this.selected = response.data || row;
        })
        .catch((error) => {
          this.detailError = this.errorMessage(error, '完整详情加载失败，当前展示列表数据');
        })
        .finally(() => {
          this.detailLoading = false;
        });
    },
    closeDetail() {
      this.selected = null;
      this.detailError = '';
    },
    openProofAsset(asset) {
      const applicationId = this.selected && this.selected.id;
      if (!asset || !asset.id || !asset.available || !applicationId || this.openingAssetId) return;
      const popup = window.open('', '_blank');
      this.openingAssetId = asset.id;
      graduateVerificationAssetContent(asset.id, applicationId)
        .then((response) => {
          const objectUrl = window.URL.createObjectURL(response.data);
          if (popup) {
            popup.location.replace(objectUrl);
          } else {
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = asset.original_name || 'member-asset';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
          }
          window.setTimeout(() => window.URL.revokeObjectURL(objectUrl), 300000);
        })
        .catch((error) => {
          if (popup) popup.close();
          this.$message.error(this.errorMessage(error, '文件打开失败'));
        })
        .finally(() => {
          this.openingAssetId = 0;
        });
    },
    openReview(row, action) {
      this.reviewTarget = row;
      this.reviewAction = action;
      this.reviewNote = '';
      this.reviewError = '';
      this.reviewVisible = true;
    },
    resetReview() {
      if (this.reviewing) return;
      this.reviewTarget = null;
      this.reviewAction = '';
      this.reviewNote = '';
      this.reviewError = '';
    },
    submitReview() {
      if (this.reviewing || !this.reviewTarget) return;
      const result = memberUi.buildReviewRequest(this.reviewAction, this.reviewNote);
      if (!result.valid) {
        this.reviewError = result.errors.note || result.errors.action;
        return;
      }

      const fingerprint = memberUi.payloadFingerprint({
        id: this.reviewTarget.id,
        request: result.value,
      });
      if (!this.pendingReviewKey || this.pendingReviewFingerprint !== fingerprint) {
        this.pendingReviewKey = memberUi.createIdempotencyKey('graduate-review');
        this.pendingReviewFingerprint = fingerprint;
      }

      this.reviewing = true;
      reviewGraduateVerification(this.reviewTarget.id, result.value, this.pendingReviewKey)
        .then((response) => {
          const updated = response.data || this.reviewTarget;
          this.selected = this.selected && this.selected.id === updated.id ? updated : this.selected;
          this.reviewVisible = false;
          this.pendingReviewKey = '';
          this.pendingReviewFingerprint = '';
          this.$message.success('审核结果已提交');
          return this.loadList();
        })
        .catch((error) => {
          this.reviewError = this.errorMessage(error, '审核提交失败，请稍后重试');
        })
        .finally(() => {
          this.reviewing = false;
        });
    },
    buttonType(tone) {
      if (tone === 'warning') return 'warning';
      if (tone === 'danger') return 'danger';
      if (tone === 'success') return 'success';
      return 'primary';
    },
    errorMessage(error, fallback) {
      return error && (error.msg || error.message) ? error.msg || error.message : fallback;
    },
    formatDate(timestamp) {
      if (!timestamp) return '-';
      const date = new Date(Number(timestamp) * 1000);
      const pad = (value) => (Number(value) < 10 ? '0' : '') + Number(value);
      return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
    },
    formatTime(timestamp) {
      if (!timestamp) return '-';
      const date = new Date(Number(timestamp) * 1000);
      const pad = (value) => (Number(value) < 10 ? '0' : '') + Number(value);
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(
        date.getMinutes(),
      )}`;
    },
  },
};
</script>

<style lang="scss" scoped>
.verification-workbench {
  min-height: 100%;
  color: #25312c;
}

.filter-panel,
.table-panel {
  border-radius: 6px;
}

.filter-panel {
  margin-bottom: 16px;
}

.filter-control {
  width: 160px;
}

.keyword-control {
  width: 240px;
}

.load-alert {
  margin-bottom: 16px;
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

.verification-table {
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

.action-warning {
  color: #a66312;
}
.action-danger {
  color: #b0362f;
}
.action-success {
  color: #167055;
}

.pagination-row {
  display: flex;
  padding: 18px 20px;
  justify-content: flex-end;
  border-top: 1px solid #ebeef0;
}

.detail-drawer {
  box-sizing: border-box;
  min-height: 100%;
  padding: 0 24px 92px;
}

.detail-alert {
  margin-bottom: 18px;
}

.detail-heading {
  display: flex;
  padding: 4px 0 22px;
  align-items: flex-start;
  justify-content: space-between;
  border-bottom: 1px solid #e6ebe8;
}

.detail-overline {
  display: block;
  margin-bottom: 7px;
  color: #7b8580;
  font-size: 12px;
}

.detail-heading strong {
  display: block;
  max-width: 390px;
  overflow-wrap: anywhere;
  color: #202b27;
  font-family: Menlo, Consolas, monospace;
  font-size: 17px;
  font-weight: 600;
}

.detail-section {
  padding: 24px 0;
  border-bottom: 1px solid #e6ebe8;
}

.detail-section h3 {
  margin: 0 0 18px;
  color: #2c3833;
  font-size: 15px;
  font-weight: 600;
  letter-spacing: 0;
}

.detail-grid {
  display: grid;
  margin: 0;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px 24px;
}

.detail-grid div {
  min-width: 0;
}
.detail-grid dt {
  color: #7b8580;
  font-size: 12px;
}
.detail-grid dd {
  margin: 7px 0 0;
  color: #303c37;
  font-size: 14px;
  overflow-wrap: anywhere;
}

.proof-list {
  display: grid;
  gap: 10px;
}

.proof-item {
  display: flex;
  padding: 11px 12px;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  border: 1px solid #dfe5e2;
  border-radius: 4px;
  background: #f7f9f8;
}

.proof-item > div {
  min-width: 0;
}

.proof-item strong,
.proof-item span {
  display: block;
  overflow-wrap: anywhere;
}

.proof-item strong {
  color: #34413b;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.5;
}

.proof-item span {
  margin-top: 4px;
  color: #7b8580;
  font-size: 12px;
}

.proof-unavailable {
  flex: 0 0 auto;
}

.review-note {
  margin: 0;
  color: #46524d;
  font-size: 14px;
  line-height: 1.7;
  white-space: pre-wrap;
}

.drawer-actions {
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  padding: 16px 24px;
  justify-content: flex-end;
  gap: 10px;
  border-top: 1px solid #e6ebe8;
  background: #ffffff;
}

::v-deep .review-dialog {
  width: calc(100% - 32px) !important;
  max-width: 520px;
}

@media (max-width: 767px) {
  .detail-drawer {
    padding-right: 16px;
    padding-left: 16px;
  }

  .detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .drawer-actions {
    padding-right: 16px;
    padding-left: 16px;
    flex-wrap: wrap;
  }

  ::v-deep .review-dialog .el-dialog__body {
    padding: 20px 16px;
  }

  ::v-deep .review-dialog .el-dialog__footer {
    padding-right: 16px;
    padding-left: 16px;
  }
}

@media (max-width: 767px) {
  .filter-panel ::v-deep .el-form-item {
    display: block;
    margin-right: 0;
  }

  .filter-control,
  .keyword-control,
  .filter-panel ::v-deep .el-input-number {
    width: 100%;
  }

  .detail-grid {
    grid-template-columns: 1fr;
  }

  .detail-heading strong {
    max-width: 230px;
  }
}
</style>
