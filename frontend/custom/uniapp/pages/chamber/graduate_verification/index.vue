<template>
  <view class="verification-page">
    <view v-if="loading" class="state-panel">
      <text class="state-title">正在读取认证状态</text>
    </view>

    <view v-else-if="loadError" class="state-panel">
      <text class="state-title">认证状态读取失败</text>
      <text class="state-message">{{ loadError }}</text>
      <button class="secondary-button" @click="loadVerification">重新加载</button>
    </view>

    <template v-else>
      <view class="status-band" :class="'tone-' + statusMeta.tone">
        <view>
          <text class="status-label">毕业认证</text>
          <text class="status-value">{{ statusMeta.label }}</text>
        </view>
        <text v-if="latestApplication" class="application-no">{{ latestApplication.application_no }}</text>
      </view>

      <view v-if="latestApplication" class="section application-section">
        <view class="section-head">
          <text class="section-title">最近申请</text>
          <text class="submitted-at">{{ formatTime(latestApplication.submitted_at) }}</text>
        </view>
        <view class="detail-grid">
          <view class="detail-item">
            <text class="detail-label">班级</text>
            <text class="detail-value">{{ latestApplication.class_name || '-' }}</text>
          </view>
          <view class="detail-item">
            <text class="detail-label">毕业年份</text>
            <text class="detail-value">{{ latestApplication.graduation_year || '-' }}</text>
          </view>
          <view v-if="latestApplication.graduation_at" class="detail-item">
            <text class="detail-label">毕业日期</text>
            <text class="detail-value">{{ formatDate(latestApplication.graduation_at) }}</text>
          </view>
          <view v-if="latestApplication.reviewed_at" class="detail-item">
            <text class="detail-label">审核时间</text>
            <text class="detail-value">{{ formatTime(latestApplication.reviewed_at) }}</text>
          </view>
        </view>
        <view class="proof-summary">
          <text class="detail-label">证明材料</text>
          <view v-for="asset in latestProofAssets" :key="asset.object_key" class="stored-proof">
            <view class="proof-main">
              <text class="proof-name">{{ asset.original_name }}</text>
              <text v-if="asset.size" class="proof-meta">{{ humanFileSize(asset.size) }}</text>
            </view>
            <button
              v-if="asset.id && asset.available"
              class="text-button"
              type="button"
              :loading="openingAssetId === asset.id"
              :disabled="openingAssetId === asset.id"
              @click="openProofAsset(asset)"
            >
              打开
            </button>
            <text v-else class="proof-unavailable">材料不可用</text>
          </view>
        </view>
        <view v-if="latestApplication.review_note" class="review-note">
          <text class="detail-label">审核意见</text>
          <text class="review-note-text">{{ latestApplication.review_note }}</text>
        </view>
      </view>

      <form v-if="canSubmit" @submit="submitApplication">
        <view class="section">
          <view class="section-head">
            <text class="section-title">{{ latestApplication ? '重新提交' : '提交认证' }}</text>
          </view>
          <view class="field required">
            <text class="field-label">班级</text>
            <input v-model="form.class_name" class="field-input" maxlength="80" placeholder="如 EMBA 2008" />
            <text v-if="errors.class_name" class="field-error">{{ errors.class_name }}</text>
          </view>
          <view class="field-grid">
            <view class="field compact required">
              <text class="field-label">毕业年份</text>
              <input
                v-model="form.graduation_year"
                class="field-input"
                type="number"
                maxlength="4"
                placeholder="年份"
              />
              <text v-if="errors.graduation_year" class="field-error">{{ errors.graduation_year }}</text>
            </view>
            <view class="field compact">
              <text class="field-label">毕业日期</text>
              <picker mode="date" :value="form.graduation_at" @change="changeGraduationDate">
                <view class="field-input picker-value" :class="{ muted: !form.graduation_at }">
                  {{ form.graduation_at || '选择日期' }}
                </view>
              </picker>
              <text v-if="errors.graduation_at" class="field-error">{{ errors.graduation_at }}</text>
            </view>
          </view>
        </view>

        <view class="section proof-section">
          <view class="section-head proof-head">
            <view>
              <text class="section-title">证明材料</text>
              <text class="section-count">{{ proofFiles.length }}/10</text>
            </view>
            <view class="proof-pickers">
              <button
                class="picker-button"
                type="button"
                :disabled="proofFiles.length >= 10"
                @click="chooseProofImages"
              >
                图片
              </button>
              <button
                v-if="canChooseMessageFile"
                class="picker-button"
                type="button"
                :disabled="proofFiles.length >= 10"
                @click="chooseProofFiles"
              >
                聊天图片
              </button>
            </view>
          </view>
          <view v-if="!proofFiles.length" class="proof-empty">尚未添加材料</view>
          <view v-for="item in proofFiles" :key="item.local_id" class="proof-row">
            <view class="proof-main">
              <text class="proof-name">{{ item.original_name }}</text>
              <text class="proof-meta" :class="'proof-' + item.status">
                {{ proofStatus(item) }}
              </text>
            </view>
            <view class="proof-actions">
              <button
                v-if="item.status === 'ready' && item.id"
                class="icon-button open-button"
                type="button"
                aria-label="打开证明材料"
                :loading="openingAssetId === item.id"
                :disabled="openingAssetId === item.id"
                @click="openProofAsset(item)"
              >
                ▷
              </button>
              <button
                v-if="item.status === 'failed'"
                class="icon-button retry-button"
                type="button"
                aria-label="重试上传"
                @click="uploadProofCandidate(item)"
              >
                ↻
              </button>
              <button
                class="icon-button remove-button"
                type="button"
                aria-label="移除证明材料"
                :disabled="item.status === 'uploading'"
                @click="removeProof(item.local_id)"
              >
                ×
              </button>
            </view>
          </view>
          <text v-if="errors.proof_object_keys" class="field-error proof-error">{{ errors.proof_object_keys }}</text>
        </view>

        <view class="action-bar">
          <button
            class="primary-button"
            form-type="submit"
            :loading="submitting"
            :disabled="submitting || hasUploadingProof"
          >
            {{ latestApplication ? '重新提交审核' : '提交审核' }}
          </button>
        </view>
      </form>

      <view v-else class="state-strip">
        <text>{{ stateMessage }}</text>
      </view>
    </template>
  </view>
</template>

<script>
import {
  ensureMemberInitialized,
  downloadMemberAssetContent,
  getGraduateVerification,
  submitGraduateVerification,
  uploadMemberAsset,
} from '@/api/chamber/member.js';
import memberUi from '@/chamber/shared/member-ui.js';

export default {
  data() {
    return {
      loading: true,
      submitting: false,
      loadError: '',
      errors: {},
      currentStatus: 'draft',
      canSubmit: false,
      latestApplication: null,
      latestProofAssets: [],
      proofFiles: [],
      canChooseMessageFile: false,
      openingAssetId: 0,
      pendingKey: '',
      pendingFingerprint: '',
      form: {
        class_name: '',
        graduation_year: '',
        graduation_at: '',
      },
      inviteCode: '',
    };
  },
  computed: {
    statusMeta() {
      return memberUi.verificationStatusMeta(this.currentStatus);
    },
    stateMessage() {
      if (this.currentStatus === 'pending') return '申请正在审核中';
      if (this.currentStatus === 'approved') return '认证已生效';
      return '当前状态暂不可提交新申请';
    },
    hasUploadingProof() {
      return this.proofFiles.some((item) => item.status === 'uploading');
    },
  },
  onLoad(options) {
    this.inviteCode = options && options.invite_code ? String(options.invite_code) : '';
    this.canChooseMessageFile = typeof uni.chooseMessageFile === 'function';
    this.loadVerification();
  },
  onPullDownRefresh() {
    this.loadVerification(true);
  },
  methods: {
    loadVerification(fromPullDown) {
      this.loading = !fromPullDown;
      this.loadError = '';
      ensureMemberInitialized(this.inviteCode)
        .then(() => getGraduateVerification())
        .then((response) => {
          this.applySummary(response.data || {});
        })
        .catch((error) => {
          this.loadError = this.errorMessage(error, '暂时无法读取认证状态');
        })
        .finally(() => {
          this.loading = false;
          if (fromPullDown) uni.stopPullDownRefresh();
        });
    },
    applySummary(summary) {
      this.currentStatus = summary.current_status || 'draft';
      this.latestApplication = summary.latest_application || null;
      this.latestProofAssets = memberUi.proofAssetsFromApplication(this.latestApplication || {});
      this.canSubmit = Boolean(summary.can_submit);
      this.errors = {};

      if (this.canSubmit && this.latestApplication) {
        this.form.class_name = this.latestApplication.class_name || '';
        this.form.graduation_year = this.latestApplication.graduation_year
          ? String(this.latestApplication.graduation_year)
          : '';
        this.form.graduation_at = this.latestApplication.graduation_at
          ? this.formatDate(this.latestApplication.graduation_at)
          : '';
        this.proofFiles = this.latestProofAssets.filter((asset) => asset.available).map((asset, index) =>
          Object.assign({}, asset, {
            local_id: 'stored-' + (asset.id || index) + '-' + index,
            status: 'ready',
            file_path: '',
          }),
        );
      } else if (!this.latestApplication) {
        this.proofFiles = [];
      }
    },
    changeGraduationDate(event) {
      this.form.graduation_at = event.detail.value;
    },
    chooseProofImages() {
      const remaining = 10 - this.proofFiles.length;
      if (remaining <= 0) return;
      uni.chooseImage({
        count: remaining,
        sizeType: ['original', 'compressed'],
        sourceType: ['album', 'camera'],
        success: (response) => {
          const files = Array.isArray(response.tempFiles)
            ? response.tempFiles
            : (response.tempFilePaths || []).map((path) => ({ path }));
          this.appendProofCandidates(files);
        },
      });
    },
    chooseProofFiles() {
      const remaining = 10 - this.proofFiles.length;
      if (remaining <= 0 || typeof uni.chooseMessageFile !== 'function') return;
      uni.chooseMessageFile({
        count: remaining,
        type: 'file',
        extension: ['jpg', 'jpeg', 'png'],
        success: (response) => this.appendProofCandidates(response.tempFiles || []),
      });
    },
    appendProofCandidates(files) {
      const remaining = 10 - this.proofFiles.length;
      let accepted = 0;
      let rejectionMessage = '';
      (files || []).forEach((file, index) => {
        if (accepted >= remaining) return;
        const candidate = memberUi.validateProofUploadCandidate(file);
        if (!candidate.valid) {
          rejectionMessage = rejectionMessage || candidate.error;
          return;
        }
        const filePath = candidate.value.file_path;
        const item = {
          id: 0,
          object_key: '',
          original_name: candidate.value.original_name,
          mime_type: candidate.value.mime_type,
          size: Number(file.size || 0),
          local_id: 'upload-' + Date.now() + '-' + index + '-' + Math.floor(Math.random() * 100000),
          idempotency_key: memberUi.createIdempotencyKey('asset-upload'),
          status: 'queued',
          file_path: filePath,
          upload_error: '',
        };
        this.proofFiles.push(item);
        accepted += 1;
        this.uploadProofCandidate(item);
      });
      if (rejectionMessage) uni.showToast({ title: rejectionMessage, icon: 'none' });
    },
    uploadProofCandidate(item) {
      if (!item || !item.file_path || item.status === 'uploading') return;
      this.$set(item, 'status', 'uploading');
      this.$set(item, 'upload_error', '');
      uploadMemberAsset(item.file_path, item.idempotency_key)
        .then((response) => {
          const asset = memberUi.normalizeMemberAsset(response.data || {});
          if (!asset) throw new Error('上传结果缺少文件标识');
          Object.keys(asset).forEach((key) => this.$set(item, key, asset[key]));
          this.$set(item, 'status', 'ready');
          this.errors = Object.assign({}, this.errors, { proof_object_keys: '' });
        })
        .catch((error) => {
          this.$set(item, 'status', 'failed');
          this.$set(item, 'upload_error', this.errorMessage(error, '上传失败'));
        });
    },
    removeProof(localId) {
      const index = this.proofFiles.findIndex((item) => item.local_id === localId);
      if (index >= 0 && this.proofFiles[index].status !== 'uploading') this.proofFiles.splice(index, 1);
    },
    proofStatus(item) {
      if (item.status === 'uploading') return '正在上传';
      if (item.status === 'failed') return item.upload_error || '上传失败';
      return this.humanFileSize(item.size) || '已上传';
    },
    openProofAsset(asset) {
      if (!asset || !asset.id || !asset.available || this.openingAssetId) return;
      this.openingAssetId = asset.id;
      downloadMemberAssetContent(asset.id)
        .then((filePath) => {
          if (/^image\//i.test(asset.mime_type || '')) {
            uni.previewImage({ urls: [filePath], current: filePath });
            return;
          }
          uni.openDocument({
            filePath,
            showMenu: true,
            fail: () => uni.showToast({ title: '暂时无法预览该文件', icon: 'none' }),
          });
        })
        .catch((error) => {
          uni.showToast({ title: this.errorMessage(error, '文件打开失败'), icon: 'none' });
        })
        .finally(() => {
          this.openingAssetId = 0;
        });
    },
    submitApplication() {
      if (this.submitting) return;
      if (this.hasUploadingProof) {
        uni.showToast({ title: '请等待材料上传完成', icon: 'none' });
        return;
      }
      if (this.proofFiles.some((item) => item.status === 'failed')) {
        uni.showToast({ title: '请重试或删除上传失败的材料', icon: 'none' });
        return;
      }
      const submission = Object.assign({}, this.form, {
        proof_object_keys: this.proofFiles
          .filter((item) => item.status === 'ready')
          .map((item) => item.object_key),
      });
      const result = memberUi.buildVerificationSubmission(submission, this.latestApplication);
      this.errors = result.errors;
      if (!result.valid) {
        uni.showToast({
          title: result.errors[Object.keys(result.errors)[0]],
          icon: 'none',
        });
        return;
      }

      const fingerprint = memberUi.payloadFingerprint(result.value);
      if (!this.pendingKey || this.pendingFingerprint !== fingerprint) {
        this.pendingKey = memberUi.createIdempotencyKey('graduate-submit');
        this.pendingFingerprint = fingerprint;
      }

      this.submitting = true;
      submitGraduateVerification(result.value, this.pendingKey)
        .then(() => {
          this.pendingKey = '';
          this.pendingFingerprint = '';
          uni.showToast({ title: '申请已提交', icon: 'success' });
          return this.loadVerification();
        })
        .catch((error) => {
          if (error && error.data && error.data.field_errors) {
            this.errors = memberUi.fieldErrorsToMap(error.data.field_errors);
          }
          uni.showToast({
            title: this.errorMessage(error, '提交失败，请稍后重试'),
            icon: 'none',
          });
        })
        .finally(() => {
          this.submitting = false;
        });
    },
    errorMessage(error, fallback) {
      return error && (error.msg || error.message) ? error.msg || error.message : fallback;
    },
    formatDate(timestamp) {
      if (!timestamp) return '';
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
    humanFileSize(size) {
      return memberUi.humanFileSize(size);
    },
  },
};
</script>

<style lang="scss" scoped>
.verification-page {
  min-height: 100vh;
  padding-bottom: calc(148rpx + env(safe-area-inset-bottom));
  background: #f4f6f5;
  color: #17211d;
}

.status-band {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 34rpx 32rpx;
  border-left: 8rpx solid #75807b;
  background: #ffffff;
}
.status-band.tone-success {
  border-left-color: #167455;
}
.status-band.tone-warning {
  border-left-color: #bd7b18;
}
.status-band.tone-danger {
  border-left-color: #b33b32;
}
.status-label {
  display: block;
  color: #68736e;
  font-size: 24rpx;
}
.status-value {
  display: block;
  margin-top: 8rpx;
  font-size: 38rpx;
  font-weight: 600;
}
.application-no {
  max-width: 330rpx;
  color: #68736e;
  font-family: Menlo, Consolas, monospace;
  font-size: 22rpx;
  word-break: break-all;
  text-align: right;
}

.section,
.state-panel,
.state-strip {
  margin-top: 16rpx;
  background: #ffffff;
}
.section {
  padding: 0 32rpx 24rpx;
}
.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 26rpx 0 16rpx;
  border-bottom: 1rpx solid #edf0ee;
}
.section-title {
  font-size: 30rpx;
  font-weight: 600;
}
.submitted-at {
  color: #78837e;
  font-size: 24rpx;
}

.detail-grid {
  display: flex;
  flex-wrap: wrap;
  padding-top: 8rpx;
}
.detail-item {
  box-sizing: border-box;
  width: 50%;
  padding: 20rpx 12rpx 4rpx 0;
}
.detail-label {
  display: block;
  color: #78837e;
  font-size: 24rpx;
}
.detail-value {
  display: block;
  margin-top: 8rpx;
  font-size: 28rpx;
  line-height: 1.45;
}
.proof-summary,
.review-note {
  margin-top: 24rpx;
  padding-top: 22rpx;
  border-top: 1rpx solid #edf0ee;
}
.stored-proof {
  display: flex;
  min-height: 82rpx;
  margin-top: 12rpx;
  padding: 14rpx 16rpx;
  align-items: center;
  justify-content: space-between;
  gap: 14rpx;
  border: 1rpx solid #dfe5e1;
  border-radius: 6rpx;
  background: #f7f9f8;
}
.review-note-text {
  display: block;
  margin-top: 10rpx;
  color: #3f4b46;
  font-size: 27rpx;
  line-height: 1.6;
  white-space: pre-wrap;
}

.field {
  padding: 22rpx 0 4rpx;
}
.field.compact {
  width: calc(50% - 12rpx);
}
.field-grid {
  display: flex;
  justify-content: space-between;
  gap: 24rpx;
}
.field-label {
  display: block;
  margin-bottom: 12rpx;
  color: #45514c;
  font-size: 26rpx;
}
.required .field-label::after {
  content: ' *';
  color: #b33b32;
}
.field-input {
  box-sizing: border-box;
  width: 100%;
  height: 82rpx;
  padding: 0 22rpx;
  border: 1rpx solid #cfd8d3;
  border-radius: 8rpx;
  background: #ffffff;
  color: #17211d;
  font-size: 27rpx;
  line-height: 82rpx;
}
.picker-value.muted {
  color: #9aa49f;
}
.field-error {
  display: block;
  margin-top: 10rpx;
  color: #b33b32;
  font-size: 24rpx;
}

.proof-head > view:first-child {
  display: flex;
  align-items: baseline;
  gap: 14rpx;
}
.section-count {
  color: #78837e;
  font-size: 23rpx;
}
.proof-row {
  display: flex;
  min-height: 88rpx;
  padding: 12rpx 0;
  align-items: center;
  justify-content: space-between;
  gap: 14rpx;
  border-bottom: 1rpx solid #edf0ee;
}
.proof-main {
  flex: 1;
  min-width: 0;
}
.proof-name,
.proof-meta {
  display: block;
  overflow-wrap: anywhere;
}
.proof-name {
  color: #26332d;
  font-size: 26rpx;
  line-height: 1.4;
}
.proof-meta {
  margin-top: 6rpx;
  color: #78837e;
  font-size: 22rpx;
}
.proof-unavailable {
  color: #9a3f36;
  font-size: 24rpx;
}
.proof-failed {
  color: #b33b32;
}
.proof-uploading {
  color: #9a6510;
}
.proof-actions,
.proof-pickers {
  display: flex;
  align-items: center;
  gap: 10rpx;
}
.proof-empty {
  padding: 36rpx 0 18rpx;
  color: #8a948f;
  font-size: 25rpx;
  text-align: center;
}
.picker-button,
.text-button {
  min-width: 100rpx;
  height: 62rpx;
  margin: 0;
  padding: 0 18rpx;
  border: 1rpx solid #176b52;
  border-radius: 8rpx;
  background: #ffffff;
  color: #176b52;
  font-size: 25rpx;
  line-height: 60rpx;
}
.picker-button[disabled],
.text-button[disabled] {
  border-color: #d7ddda;
  color: #a9b2ae;
}
.icon-button {
  display: flex;
  width: 68rpx;
  height: 68rpx;
  min-width: 68rpx;
  margin: 0;
  padding: 0;
  align-items: center;
  justify-content: center;
  border-radius: 8rpx;
  font-size: 38rpx;
  line-height: 68rpx;
}
.open-button,
.retry-button {
  color: #176b52;
  border: 1rpx solid #176b52;
  background: #ffffff;
}
.remove-button {
  color: #a3342d;
  border: 1rpx solid #e2c5c2;
  background: #ffffff;
}
.remove-button[disabled] {
  color: #b6bfbb;
  border-color: #e1e5e3;
}
.proof-error {
  margin-top: 16rpx;
}

.action-bar {
  position: fixed;
  z-index: 20;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 18rpx 32rpx calc(18rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid #dfe5e1;
  background: rgba(255, 255, 255, 0.96);
}
.primary-button,
.secondary-button {
  height: 84rpx;
  border-radius: 8rpx;
  font-size: 29rpx;
  line-height: 84rpx;
}
.primary-button {
  color: #ffffff;
  background: #176b52;
}
.primary-button[disabled] {
  color: #eef3f1;
  background: #88a99e;
}
.secondary-button {
  width: 240rpx;
  margin-top: 28rpx;
  color: #176b52;
  border: 1rpx solid #176b52;
  background: #ffffff;
}

.state-panel {
  display: flex;
  min-height: 420rpx;
  padding: 80rpx 32rpx;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  text-align: center;
}
.state-title {
  font-size: 30rpx;
  font-weight: 600;
}
.state-message {
  margin-top: 14rpx;
  color: #737f79;
  font-size: 26rpx;
  line-height: 1.6;
}
.state-strip {
  padding: 30rpx 32rpx;
  color: #59645f;
  font-size: 27rpx;
  text-align: center;
}
</style>
