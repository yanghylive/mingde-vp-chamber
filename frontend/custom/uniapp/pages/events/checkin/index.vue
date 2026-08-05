<template>
  <view class="checkin-page">
    <page-header title="扫码签到" eyebrow="扫描活动现场签到二维码" />
    <view class="card tip-card">
      <view class="tip-icon"><view class="ic ic-lg ic-scan-line-white" /></view>
      <view class="tip-title">活动签到</view>
      <view class="tip-sub">点击下方按钮，扫描活动现场二维码完成签到</view>
    </view>

    <view class="scan-btn" @tap="scan">
      <view class="ic ic-sm ic-scan-line-white" />
      <text class="sb-text">扫码签到</text>
    </view>

    <view v-if="result === 'success'" class="card result-card result-success">
      <view class="r-icon-box">
        <text class="r-icon-text">OK</text>
      </view>
      <text class="r-title">签到成功</text>
      <text class="r-sub">{{ resultMsg }}</text>
      <view v-if="pointsAwarded > 0" class="r-badge">
        <view class="ic ic-xs ic-coins-gold" />
        <text class="r-badge-text">+{{ pointsAwarded }} 积分</text>
      </view>
      <view class="r-btns">
        <view class="btn-secondary r-btn" @tap="resetResult">
          <text>继续签到</text>
        </view>
        <view class="btn-primary r-btn" @tap="goHome">
          <text>返回首页</text>
        </view>
      </view>
    </view>

    <view v-if="result === 'error'" class="card result-card result-error">
      <view class="r-icon-box r-icon-error">
        <text class="r-icon-text">!</text>
      </view>
      <text class="r-title">签到失败</text>
      <text class="r-sub">{{ resultMsg }}</text>
      <view class="btn-secondary r-btn r-btn-single" @tap="resetResult">
        <text>重试</text>
      </view>
      <text class="r-back-link" @tap="goBack">返回上一页</text>
    </view>
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
      result: '',
      resultTitle: '',
      resultMsg: '',
      pointsAwarded: 0
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
      var eventId = null
      try {
        eventId = Number(scanned.trim())
      } catch (e) {}
      if (!eventId) {
        var m = String(scanned).match(/[?&](?:id|event)=?(\d+)|\/events\/(\d+)/)
        if (m) eventId = Number(m[1] || m[2])
      }
      if (!eventId) {
        this.result = 'error'
        this.resultTitle = '无效二维码'
        this.resultMsg = '请扫描活动签到二维码'
        return
      }
      chamber
        .checkinEvent(eventId)
        .then((res) => {
          this.result = 'success'
          this.resultTitle = '签到成功'
          this.pointsAwarded = (res && res.points_awarded) || 0
          this.resultMsg = this.pointsAwarded > 0
            ? '活动 #' + eventId + ' · 获得 ' + this.pointsAwarded + ' 积分'
            : '已记录签到'
        })
        .catch((e) => {
          this.result = 'error'
          this.resultTitle = '签到失败'
          this.resultMsg = (e && e.msg) || '请确认已报名该活动'
        })
    },
    resetResult() {
      this.result = ''
      this.pointsAwarded = 0
    },
    goHome() {
      uni.switchTab({ url: '/pages/index/index' })
    },
    goBack() {
      uni.navigateBack({ fail: function() { uni.switchTab({ url: '/pages/index/index' }) } })
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
  margin-bottom: 20rpx;
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
  border-radius: 24rpx;
  background: linear-gradient(135deg, #c87922, #eba94e);
  color: #fff;
  box-shadow: 0 12rpx 32rpx rgba(184, 117, 29, 0.35);
}
.sb-text {
  font-size: 30rpx;
  font-weight: 700;
}

/* Result cards */
.result-card {
  width: 100%;
  margin-top: 48rpx;
  padding: 40rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.r-icon-box {
  width: 112rpx;
  height: 112rpx;
  border-radius: 50%;
  background: #e9f3ef;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 24rpx;
}
.r-icon-error {
  background: #fdeeee;
}
.r-icon-text {
  font-size: 48rpx;
  font-weight: 700;
  color: #3f715f;
}
.r-icon-error .r-icon-text {
  color: #c23b3b;
}
.r-title {
  font-size: 36rpx;
  font-weight: 800;
  color: #273b59;
}
.r-sub {
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 12rpx;
  text-align: center;
}
.r-badge {
  display: flex;
  align-items: center;
  gap: 8rpx;
  background: #fff2df;
  color: #bd7627;
  padding: 10rpx 24rpx;
  border-radius: 999rpx;
  margin-top: 20rpx;
}
.r-badge-text {
  font-size: 24rpx;
  font-weight: 800;
}
.r-btns {
  display: flex;
  gap: 20rpx;
  margin-top: 32rpx;
  width: 100%;
}
.r-btn {
  flex: 1;
  text-align: center;
  padding: 24rpx 0;
  font-size: 28rpx;
  font-weight: 600;
  border-radius: 24rpx;
}
.r-btn-single {
  margin-top: 24rpx;
  width: 60%;
}
.r-back-link {
  margin-top: 20rpx;
  font-size: 24rpx;
  color: #8a94a3;
}
</style>
