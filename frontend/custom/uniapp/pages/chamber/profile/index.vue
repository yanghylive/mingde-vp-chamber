<template>
  <view class="profile-page">
    <view v-if="loading" class="state-panel">
      <text class="state-title">正在读取会员资料</text>
    </view>

    <view v-else-if="loadError" class="state-panel">
      <text class="state-title">资料读取失败</text>
      <text class="state-message">{{ loadError }}</text>
      <button class="secondary-button" @click="loadProfile">重新加载</button>
    </view>

    <form v-else @submit="saveProfile">
      <view class="page-head">
        <view>
          <text class="page-title">会员资料</text>
          <text class="page-status" class="{{profileComplete ? 'complete' : 'incomplete'}}">
            {{ profileComplete ? '资料已完善' : '资料待完善' }}
          </text>
        </view>
        <text v-if="updatedAt" class="updated-at">更新于 {{ formatTime(updatedAt) }}</text>
      </view>

      <view class="section">
        <view class="section-head">
          <text class="section-title">基本信息</text>
        </view>
        <view class="field required">
          <text class="field-label">姓名</text>
          <input v-model="form.real_name" class="field-input" maxlength="40" placeholder="请输入姓名" />
          <text v-if="errors.real_name" class="field-error">{{ errors.real_name }}</text>
        </view>
        <view class="field">
          <text class="field-label">头像文件标识</text>
          <input
            v-model="form.avatar_object_key"
            class="field-input mono"
            maxlength="255"
            placeholder="profile/avatars/member.png"
          />
          <text v-if="errors.avatar_object_key" class="field-error">{{ errors.avatar_object_key }}</text>
        </view>
        <view class="field-grid">
          <view class="field compact">
            <text class="field-label">班级</text>
            <input v-model="form.class_name" class="field-input" maxlength="80" placeholder="如 EMBA 2008" />
          </view>
          <view class="field compact">
            <text class="field-label">毕业年份</text>
            <input v-model="form.graduation_year" class="field-input" type="number" maxlength="4" placeholder="年份" />
            <text v-if="errors.graduation_year" class="field-error">{{ errors.graduation_year }}</text>
          </view>
        </view>
        <view class="field">
          <text class="field-label">行业</text>
          <input v-model="form.industry" class="field-input" maxlength="80" placeholder="所在行业" />
        </view>
        <view class="field">
          <text class="field-label">公司</text>
          <input v-model="form.company_name" class="field-input" maxlength="120" placeholder="公司或机构名称" />
          <text v-if="errors.company_name" class="field-error">{{ errors.company_name }}</text>
        </view>
        <view class="field">
          <text class="field-label">职务</text>
          <input v-model="form.job_title" class="field-input" maxlength="80" placeholder="当前职务" />
        </view>
        <view class="field">
          <text class="field-label">主营业务</text>
          <textarea
            v-model="form.main_business"
            class="field-textarea small"
            maxlength="500"
            placeholder="主营产品、服务或业务方向"
          />
        </view>
        <view class="field-grid">
          <view class="field compact">
            <text class="field-label">省份</text>
            <input v-model="form.province" class="field-input" maxlength="40" placeholder="省份" />
          </view>
          <view class="field compact">
            <text class="field-label">城市</text>
            <input v-model="form.city" class="field-input" maxlength="40" placeholder="城市" />
          </view>
        </view>
        <view class="field">
          <text class="field-label">个人简介</text>
          <textarea v-model="form.bio" class="field-textarea" maxlength="1000" placeholder="个人经历与当前关注方向" />
        </view>
      </view>

      <view class="section">
        <view class="section-head">
          <text class="section-title">资源与方向</text>
        </view>
        <view v-for="item in listFields" :key="item.key" class="field">
          <text class="field-label">{{ item.label }}</text>
          <textarea
            v-model="form[item.key]"
            class="field-textarea small"
            :maxlength="item.maxlength"
            placeholder="每行一项"
          />
          <text v-if="errors[item.key]" class="field-error">{{ errors[item.key] }}</text>
        </view>
      </view>

      <view class="section privacy-section">
        <view class="section-head">
          <text class="section-title">展示范围</text>
        </view>
        <view v-for="item in privacyFields" :key="item.key" class="privacy-row">
          <text class="privacy-label">{{ item.label }}</text>
          <picker
            class="privacy-picker"
            :value="privacyIndex(form.privacy[item.key])"
            :range="privacyOptions"
            range-key="label"
            @change="changePrivacy(item.key, $event)"
          >
            <view class="privacy-value">
              <text>{{ privacyLabel(form.privacy[item.key]) }}</text>
              <text class="chevron">></text>
            </view>
          </picker>
        </view>
      </view>

      <view class="action-bar">
        <button class="primary-button" form-type="submit" :loading="saving" :disabled="saving">保存资料</button>
      </view>
    </form>
  </view>
</template>

<script>
import { getMemberProfile, updateMemberProfile } from '@/api/chamber/member.js';
import memberUi from '@/chamber/shared/member-ui.js';

export default {
  data() {
    return {
      loading: true,
      saving: false,
      loadError: '',
      errors: {},
      profileComplete: false,
      updatedAt: 0,
      pendingKey: '',
      pendingFingerprint: '',
      privacyOptions: memberUi.VISIBILITY_OPTIONS,
      privacyFields: memberUi.PROFILE_FIELDS,
      listFields: [
        { key: 'resources', label: '可提供资源', maxlength: 3029 },
        { key: 'needs', label: '当前需求', maxlength: 3029 },
        { key: 'interests', label: '兴趣方向', maxlength: 1829 },
        { key: 'expertise', label: '专业能力', maxlength: 1829 },
      ],
      form: memberUi.profileFormFromData({}),
    };
  },
  onLoad() {
    this.loadProfile();
  },
  methods: {
    loadProfile() {
      this.loading = true;
      this.loadError = '';
      getMemberProfile()
        .then((response) => {
          this.applyProfile(response.data || {});
        })
        .catch((error) => {
          this.loadError = this.errorMessage(error, '暂时无法读取资料');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    applyProfile(profile) {
      this.form = memberUi.profileFormFromData(profile);
      this.profileComplete = Boolean(profile.profile_complete);
      this.updatedAt = Number(profile.updated_at || 0);
      this.errors = {};
    },
    privacyIndex(value) {
      const index = this.privacyOptions.findIndex((item) => item.value === value);
      return index < 0 ? 0 : index;
    },
    privacyLabel(value) {
      return this.privacyOptions[this.privacyIndex(value)].label;
    },
    changePrivacy(key, event) {
      const option = this.privacyOptions[Number(event.detail.value)] || this.privacyOptions[0];
      this.$set(this.form.privacy, key, option.value);
    },
    saveProfile() {
      if (this.saving) return;
      const result = memberUi.buildProfilePatch(this.form);
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
        this.pendingKey = memberUi.createIdempotencyKey('profile-save');
        this.pendingFingerprint = fingerprint;
      }

      this.saving = true;
      updateMemberProfile(result.value, this.pendingKey)
        .then((response) => {
          this.applyProfile(response.data || {});
          this.pendingKey = '';
          this.pendingFingerprint = '';
          uni.showToast({ title: '资料已保存', icon: 'success' });
        })
        .catch((error) => {
          if (error && error.data && error.data.field_errors) this.errors = error.data.field_errors;
          uni.showToast({
            title: this.errorMessage(error, '保存失败，请稍后重试'),
            icon: 'none',
          });
        })
        .finally(() => {
          this.saving = false;
        });
    },
    errorMessage(error, fallback) {
      return error && (error.msg || error.message) ? error.msg || error.message : fallback;
    },
    formatTime(timestamp) {
      const date = new Date(Number(timestamp) * 1000);
      const pad = (value) => String(value).padStart(2, '0');
      return (date.getFullYear()) + '-' + (pad(date.getMonth() + 1)) + '-' + (pad(date.getDate()));
    },
  },
};
</script>

<style lang="scss">
.profile-page {
  min-height: 100vh;
  background: #f4f6f5;
  color: #17211d;
  padding-bottom: calc(148rpx + env(safe-area-inset-bottom));
}

.page-head,
.section,
.state-panel {
  background: #ffffff;
}

.page-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 34rpx 32rpx 28rpx;
  border-bottom: 1rpx solid #dfe5e1;
}

.page-title {
  display: block;
  font-size: 36rpx;
  font-weight: 600;
  line-height: 1.3;
}

.page-status {
  display: inline-block;
  margin-top: 12rpx;
  font-size: 24rpx;
}

.page-status.complete {
  color: #167455;
}
.page-status.incomplete {
  color: #9a6510;
}
.updated-at {
  color: #78837e;
  font-size: 24rpx;
  line-height: 46rpx;
}

.section {
  margin-top: 16rpx;
  padding: 0 32rpx 20rpx;
}

.section-head {
  padding: 26rpx 0 12rpx;
  border-bottom: 1rpx solid #edf0ee;
}

.section-title {
  font-size: 30rpx;
  font-weight: 600;
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

.field-input,
.field-textarea {
  box-sizing: border-box;
  width: 100%;
  border: 1rpx solid #cfd8d3;
  border-radius: 8rpx;
  background: #ffffff;
  font-size: 28rpx;
  color: #17211d;
}

.field-input {
  height: 82rpx;
  padding: 0 22rpx;
  line-height: 82rpx;
}
.field-textarea {
  height: 210rpx;
  padding: 20rpx 22rpx;
  line-height: 1.55;
}
.field-textarea.small {
  height: 152rpx;
}
.mono {
  font-family: Menlo, Consolas, monospace;
  font-size: 24rpx;
}
.field-error {
  display: block;
  margin-top: 10rpx;
  color: #b33b32;
  font-size: 24rpx;
}

.privacy-section {
  padding-bottom: 0;
}
.privacy-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 92rpx;
  border-bottom: 1rpx solid #edf0ee;
}
.privacy-row:last-child {
  border-bottom: 0;
}
.privacy-label {
  font-size: 27rpx;
  color: #35413c;
}
.privacy-picker {
  min-width: 220rpx;
}
.privacy-value {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 16rpx;
  color: #167455;
  font-size: 26rpx;
}
.chevron {
  color: #9aa49f;
  font-size: 38rpx;
  line-height: 1;
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
  background: #88a99e;
  color: #eef3f1;
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
</style>
