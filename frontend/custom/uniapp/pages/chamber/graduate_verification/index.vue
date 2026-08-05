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
      <view class="status-band" class="{{'tone-' + statusMeta.tone}}">
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
          <view v-for="key in latestApplication.proof_object_keys" :key="key" class="object-key">
            <text>{{ key }}</text>
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
                <view class="field-input picker-value" class="{{{ muted: !form.graduation_at }}}">
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
              <text class="section-count">{{ form.proof_object_keys.length }}/10</text>
            </view>
            <button
              class="icon-button add-button"
              type="button"
              aria-label="添加证明材料"
              :disabled="form.proof_object_keys.length >= 10"
              @click="addProofKey"
            >
              +
            </button>
          </view>
          <view v-for="(key, index) in form.proof_object_keys" :key="index" class="proof-row">
            <input
              :value="key"
              class="field-input proof-input"
              maxlength="255"
              placeholder="verification/year/proof.pdf"
              @input="changeProofKey(index, $event)"
            />
            <button
              v-if="form.proof_object_keys.length > 1"
              class="icon-button remove-button"
              type="button"
              aria-label="移除证明材料"
              @click="removeProofKey(index)"
            >
              ×
            </button>
          </view>
          <text v-if="errors.proof_object_keys" class="field-error proof-error">{{ errors.proof_object_keys }}</text>
        </view>

        <view class="action-bar">
          <button class="primary-button" form-type="submit" :loading="submitting" :disabled="submitting">
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
import { getGraduateVerification, submitGraduateVerification } from '@/api/chamber/member.js';
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
      pendingKey: '',
      pendingFingerprint: '',
      form: {
        class_name: '',
        graduation_year: '',
        graduation_at: '',
        proof_object_keys: [''],
      },
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
  },
  onLoad() {
    this.loadVerification();
  },
  onPullDownRefresh() {
    this.loadVerification(true);
  },
  methods: {
    loadVerification(fromPullDown) {
      this.loading = !fromPullDown;
      this.loadError = '';
      getGraduateVerification()
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
        this.form.proof_object_keys = (this.latestApplication.proof_object_keys || []).slice();
        if (!this.form.proof_object_keys.length) this.form.proof_object_keys = [''];
      }
    },
    changeGraduationDate(event) {
      this.form.graduation_at = event.detail.value;
    },
    addProofKey() {
      if (this.form.proof_object_keys.length < 10) this.form.proof_object_keys.push('');
    },
    removeProofKey(index) {
      this.form.proof_object_keys.splice(index, 1);
    },
    changeProofKey(index, event) {
      this.$set(this.form.proof_object_keys, index, event.detail.value);
    },
    submitApplication() {
      if (this.submitting) return;
      const result = memberUi.buildVerificationSubmission(this.form, this.latestApplication);
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
          if (error && error.data && error.data.field_errors) this.errors = error.data.field_errors;
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
      const pad = (value) => String(value).padStart(2, '0');
      return (date.getFullYear()) + '-' + (pad(date.getMonth() + 1)) + '-' + (pad(date.getDate()));
    },
    formatTime(timestamp) {
      if (!timestamp) return '-';
      const date = new Date(Number(timestamp) * 1000);
      const pad = (value) => String(value).padStart(2, '0');
      return (date.getFullYear()) + '-' + (pad(date.getMonth() + 1)) + '-' + (pad(date.getDate())) + ' ' + (pad(date.getHours())) + ':' + (pad(
        date.getMinutes(),
      ));
    },
  },
};
</script>

<style lang="scss">
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
.object-key {
  margin-top: 12rpx;
  padding: 14rpx 16rpx;
  border: 1rpx solid #dfe5e1;
  border-radius: 6rpx;
  background: #f7f9f8;
  font-family: Menlo, Consolas, monospace;
  font-size: 22rpx;
  line-height: 1.5;
  word-break: break-all;
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
  align-items: center;
  gap: 14rpx;
  margin-top: 18rpx;
}
.proof-input {
  flex: 1;
  min-width: 0;
  font-family: Menlo, Consolas, monospace;
  font-size: 23rpx;
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
.add-button {
  color: #176b52;
  border: 1rpx solid #176b52;
  background: #ffffff;
}
.add-button[disabled] {
  color: #a9b2ae;
  border-color: #d7ddda;
}
.remove-button {
  color: #a3342d;
  border: 1rpx solid #e2c5c2;
  background: #ffffff;
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
