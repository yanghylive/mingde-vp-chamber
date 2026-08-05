<template>
  <view class="gv-page">
    <view v-if="status" :class="['status-card', 'status-' + status]">
      <view class="st-icon">{{ statusIcon }}</view>
      <view class="st-title">{{ statusTitle }}</view>
      <view class="st-sub">{{ statusSub }}</view>
    </view>

    <view class="card form">
      <view class="form-title">毕业认证</view>
      <view class="field">
        <text class="field-label">学校 / 班级</text>
        <input v-model="form.class_name" class="input" placeholder="填写学校或班级名称" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="field-label">毕业年份</text>
        <input v-model="form.graduation_year" class="input" type="number" placeholder="如 2020" placeholder-class="ph" />
      </view>
      <view class="submit-btn" :disabled="submitting" @tap="submit">
        {{ submitting ? '提交中…' : '提交认证' }}
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'

export default {
  data() {
    return {
      form: { class_name: '', graduation_year: '', graduation_at: 0 },
      status: '',
      submitting: false
    }
  },
  computed: {
    statusIcon() {
      return { pending: '⏳', approved: 'OK', rejected: '❌' }[this.status] || ''
    },
    statusTitle() {
      return { pending: '认证审核中', approved: '认证通过', rejected: '认证未通过' }[this.status] || ''
    },
    statusSub() {
      if (this.status === 'pending') return '提交成功，等待管理员审核'
      if (this.status === 'approved') return '恭喜！你已成为认证校友'
      if (this.status === 'rejected') return '资料未通过审核，请重新提交'
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
        const g = await chamber.myGraduateVerification()
        if (g && g.status) {
          this.status = g.status
          if (g.status === 'pending' || g.status === 'approved') {
            this.form.class_name = g.class_name || ''
            this.form.graduation_year = g.graduation_year || ''
          }
        }
      } catch (e) {}
    },
    submit() {
      if (!this.form.class_name || !this.form.graduation_year) {
        uni.showToast({ title: '请填写学校和年份', icon: 'none' })
        return
      }
      this.submitting = true
      chamber
        .submitGraduateVerification({
          class_name: this.form.class_name,
          graduation_year: Number(this.form.graduation_year),
          graduation_at: Number(new Date(String(this.form.graduation_year)) / 1000)
        })
        .then(() => {
          this.status = 'pending'
          uni.showToast({ title: '提交成功', icon: 'success' })
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
.status-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 48rpx 40rpx;
  border-radius: 28rpx;
  margin-bottom: 24rpx;
}
.status-pending {
  background: #f6ead6;
}
.status-approved {
  background: #f0f7ec;
}
.status-rejected {
  background: #fdeeee;
}
.st-icon {
  font-size: 72rpx;
}
.st-title {
  font-size: 32rpx;
  font-weight: 800;
  color: #273b59;
  margin-top: 16rpx;
}
.st-sub {
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 8rpx;
}
.form {
  padding: 36rpx 32rpx;
}
.form-title {
  font-size: 30rpx;
  font-weight: 700;
  color: #273b59;
  margin-bottom: 28rpx;
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
  border-radius: 16rpx;
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
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 28rpx;
  font-weight: 700;
}
</style>
