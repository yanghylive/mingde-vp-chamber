<template>
  <view class="checkin-page">
    <view class="card tip-card">
      <view class="tip-icon">📷</view>
      <view class="tip-title">活动签到</view>
      <view class="tip-sub">点击下方按钮，扫描活动现场二维码完成签到</view>
    </view>

    <view class="scan-btn" @tap="scan">
      <text class="sb-icon">🔍</text>
      <text class="sb-text">扫码签到</text>
    </view>

    <view v-if="result" class="card result-card">
      <text class="r-title">{{ resultTitle }}</text>
      <text class="r-sub">{{ resultMsg }}</text>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'

export default {
  data() {
    return {
      result: '',
      resultTitle: '',
      resultMsg: ''
    }
  },
  methods: {
    scan() {
      if (!checkLogin()) {
        uni.navigateTo({ url: '/pages/login/index' })
        return
      }
      uni.scanCode({
        onlyFromCamera: false,
        success: (res) => {
          this.handleScan(res.result)
        },
        fail: () => {
          uni.showToast({ title: '已取消扫码', icon: 'none' })
        }
      })
    },
    handleScan(scanned) {
      // 兼容两种二维码内容：纯 eventId 或 URL 带 id
      let eventId = null
      if (/^\d+$/.test(String(scanned).trim())) {
        eventId = Number(scanned.trim())
      } else {
        const m = String(scanned).match(/[?&]id=(\d+)/)
        if (m) eventId = Number(m[1])
      }
      if (!eventId) {
        this.result = 'error'
        this.resultTitle = '无效二维码'
        this.resultMsg = '请扫描活动签到二维码'
        return
      }
      chamber
        .checkinEvent(eventId)
        .then(() => {
          this.result = 'success'
          this.resultTitle = '签到成功'
          this.resultMsg = '积分已到账，欢迎参加活动！'
        })
        .catch((e) => {
          this.result = 'error'
          this.resultTitle = '签到失败'
          this.resultMsg = (e && e.msg) || '请确认已报名该活动'
        })
    }
  }
}
</script>

<style lang="scss">
.checkin-page {
  padding: 80rpx 48rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.tip-card {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 60rpx 40rpx;
}
.tip-icon {
  font-size: 80rpx;
}
.tip-title {
  font-size: 34rpx;
  font-weight: 800;
  color: #273b59;
  margin-top: 20rpx;
}
.tip-sub {
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 12rpx;
  text-align: center;
}
.scan-btn {
  margin-top: 64rpx;
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 30rpx 80rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  box-shadow: 0 12rpx 32rpx rgba(184, 117, 29, 0.35);
}
.sb-icon {
  font-size: 36rpx;
}
.sb-text {
  font-size: 30rpx;
  font-weight: 700;
}
.result-card {
  width: 100%;
  margin-top: 48rpx;
  padding: 40rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.r-title {
  font-size: 32rpx;
  font-weight: 800;
  color: #273b59;
}
.r-sub {
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 12rpx;
  text-align: center;
}
</style>
