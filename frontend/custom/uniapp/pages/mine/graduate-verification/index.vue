<template>
  <view class="gv-page">
    <page-header title="毕业验证" eyebrow="学历 / 身份认证" />
    <view v-if="loading" class="empty">加载中…</view>
    <template v-else>
      <view v-if="status" class="{{'status-card status-' + status}}">
        <view class="st-left">
          <view class="st-icon-box">
            <text class="st-icon-text">{{ statusIcon }}</text>
          </view>
        </view>
        <view class="st-right">
          <text class="st-title">{{ statusTitle }}</text>
          <text class="st-sub">{{ statusSub }}</text>
        </view>
      </view>

      <view class="card form">
        <view class="form-title-row">
          <image class="ic ic-sm" src="/static/icons/ic-shield-check-gold.png" mode="aspectFit" />
          <text class="form-title">{{ canSubmit ? '提交验证申请' : '当前申请处理中' }}</text>
        </view>
        <view v-if="!canSubmit" class="form-hint">
          你的申请已提交，管理员审核通过后将自动解锁对应会员等级。
        </view>
        <template v-if="canSubmit">
          <view class="field">
            <text class="field-label">班级名称</text>
            <input v-model="form.class_name" class="input" placeholder="如：沈阳总部 · 一期" placeholder-class="ph" />
          </view>
          <view class="field">
            <text class="field-label">毕业年份</text>
            <input v-model="form.graduation_year" class="input" type="number" placeholder="" placeholder-class="ph" />
          </view>
          <view class="submit-btn" :disabled="submitting" @tap="submit">
            {{ submitting ? '提交中…' : '提交验证' }}
          </view>
        </template>
      </view>
    </template>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'

export default {
  components: { PageHeader },
  data() {
    return {
      form: { class_name: '', graduation_year: String(new Date().getFullYear()), graduation_at: 0 },
      status: '',
      canSubmit: true,
      loading: true,
      submitting: false,
      approvedSub: ''
    }
  },
  computed: {
    statusIcon() {
      return { pending: '?', approved: 'OK', rejected: '!', returned: '!' }[this.status] || ''
    },
    statusTitle() {
      return {
        pending: '审核中',
        approved: '已验证',
        rejected: '未通过',
        returned: '待补充'
      }[this.status] || ''
    },
    statusSub() {
      if (this.status === 'pending') return '完成毕业验证后解锁会员等级与专属权益'
      if (this.status === 'approved') return this.approvedSub
      if (this.status === 'rejected') return '请修改资料后重新提交'
      if (this.status === 'returned') return '请补充相关材料后重新提交'
      return ''
    }
  },
  onLoad() {
    if (!checkLogin()) {
      uni.navigateTo({ url: '/pages/login/index' })
      return
    }
    this.loadData()
  },
  methods: {
    async loadData() {
      try {
        var g = await chamber.myGraduateVerification()
        var app = g && g.latest_application
        this.status = (g && g.current_status) || 'draft'
        this.canSubmit = !g || g.can_submit !== false
        if (app) {
          if (this.status === 'pending' || this.status === 'approved') {
            this.form.class_name = app.class_name || ''
            this.form.graduation_year = app.graduation_year ? String(app.graduation_year) : ''
          }
          if (this.status === 'approved' && app.class_name) {
            this.approvedSub = (app.class_name || '') + ' · ' + (app.graduation_year || '') + ' 届'
          }
        }
      } catch (e) {}
      this.loading = false
    },
    submit() {
      var year = Number(this.form.graduation_year)
      if (!this.form.class_name || !year) {
        uni.showToast({ title: '请填写有效的毕业年份', icon: 'none' })
        return
      }
      if (year < 1990 || year > new Date().getFullYear() + 1) {
        uni.showToast({ title: '请填写有效的毕业年份', icon: 'none' })
        return
      }
      this.submitting = true
      chamber
        .submitGraduateVerification({
          class_name: this.form.class_name,
          graduation_year: year,
          graduation_at: Math.floor(Date.now() / 1000)
        })
        .then(() => {
          this.form.class_name = ''
          this.form.graduation_year = ''
          this.loadData()
        })
        .catch((e) => {
          uni.showToast({ title: (e && e.msg) || '提交失败', icon: 'none' })
        })
        .finally(() => {
          this.submitting = false
        })
    }
  }
}
</script>

<style lang="scss">
.gv-page {
  padding: 32rpx;
}
.empty {
  text-align: center;
  padding: 120rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}

/* Status card */
.status-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 40rpx;
  border-radius: 36rpx;
  margin-bottom: 24rpx;
  background: #e9f3ef;
}
.status-pending {
  background: #e9f3ef;
}
.status-approved {
  background: #e9f3ef;
}
.status-rejected {
  background: #fdeeee;
}
.status-returned {
  background: #fef6ec;
}
.st-icon-box {
  width: 112rpx;
  height: 112rpx;
  border-radius: 28rpx;
  background: #e9f3ef;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.status-rejected .st-icon-box {
  background: #fdeeee;
}
.status-returned .st-icon-box {
  background: #fef6ec;
}
.st-icon-text {
  font-size: 56rpx;
  font-weight: 700;
  color: #42705f;
}
.status-rejected .st-icon-text {
  color: #c23b3b;
}
.status-returned .st-icon-text {
  color: #d05b4e;
}
.st-right {
  flex: 1;
  min-width: 0;
}
.st-title {
  display: block;
  font-size: 32rpx;
  font-weight: 700;
  color: #17325b;
}
.st-sub {
  display: block;
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 8rpx;
}

/* Form */
.form {
  padding: 36rpx 32rpx;
}
.form-title-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-bottom: 28rpx;
}
.form-title {
  font-size: 30rpx;
  font-weight: 700;
  color: #17325b;
}
.form-hint {
  font-size: 24rpx;
  color: #8a94a3;
  line-height: 1.6;
  padding: 16rpx 0;
}
.field {
  margin-bottom: 24rpx;
}
.field-label {
  display: block;
  font-size: 24rpx;
  color: #8a94a3;
  margin-bottom: 10rpx;
}
.input {
  background: #f7f5f0;
  border-radius: 24rpx;
  padding: 20rpx 24rpx;
  font-size: 28rpx;
  color: #273b59;
}
.ph {
  color: #c0c6d0;
}
.submit-btn {
  margin-top: 12rpx;
  text-align: center;
  padding: 24rpx 0;
  border-radius: 24rpx;
  background: linear-gradient(135deg, #c87922, #eba94e);
  color: #fff;
  font-size: 28rpx;
  font-weight: 700;
}
</style>
