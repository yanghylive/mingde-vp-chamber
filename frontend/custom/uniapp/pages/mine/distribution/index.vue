<template>
  <view class="distribution-page">
    <view class="code-card glass-dark">
      <view class="dc-label">我的分销码</view>
      <view class="dc-code">{{ code || (loading ? '···' : '——') }}</view>
      <view class="dc-hint">{{ code ? '分享该码，好友注册时填写即可绑定推荐关系' : '分销码生成中，请稍后再试' }}</view>
      <view class="dc-copy" class="{{{ 'dc-copy-disabled': !code || loading }}}" @tap="copyCode">
        {{ copied ? '已复制 OK' : '复制分销码' }}
      </view>
    </view>

    <view class="stats card">
      <view class="stat">
        <text class="stat-num">{{ info.invite_count || 0 }}</text>
        <text class="stat-label">邀请好友</text>
      </view>
      <view class="stat">
        <text class="stat-num">{{ info.points_earned || 0 }}</text>
        <text class="stat-label">累计积分</text>
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
      code: '',
      info: {},
      loading: true,
      copied: false
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
        this.info = (await chamber.meDistribution()) || {}
        this.code = this.info.code || ''
      } catch (e) {}
      this.loading = false
    },
    copyCode() {
      if (!this.code || this.loading) return
      uni.setClipboardData({
        data: this.code,
        success: () => {
          this.copied = true
          setTimeout(() => (this.copied = false), 2000)
        }
      })
    }
  }
}
</script>

<style lang="scss">
.distribution-page {
  padding: 48rpx 40rpx 60rpx;
}
.code-card {
  border-radius: 32rpx;
  padding: 60rpx 40rpx;
  
  display: flex;
  flex-direction: column;
  align-items: center;
  box-shadow: 0 16rpx 40rpx rgba(39, 59, 89, 0.3);
}
.dc-label {
  color: rgba(255, 255, 255, 0.6);
  font-size: 24rpx;
}
.dc-code {
  font-size: 56rpx;
  font-weight: 800;
  letter-spacing: 6rpx;
  color: #ffd78f;
  margin: 24rpx 0;
}
.dc-hint {
  color: rgba(255, 255, 255, 0.5);
  font-size: 22rpx;
  text-align: center;
}
.dc-copy {
  margin-top: 40rpx;
  padding: 20rpx 80rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 28rpx;
  font-weight: 700;
}
.dc-copy-disabled {
  opacity: 0.5;
}
.stats {
  display: flex;
  margin-top: 24rpx;
  padding: 32rpx 16rpx;
}
.stat {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8rpx;
}
.stat-num {
  font-size: 36rpx;
  font-weight: 800;
  color: #b8751d;
}
.stat-label {
  font-size: 22rpx;
  color: #8a94a3;
}
</style>
