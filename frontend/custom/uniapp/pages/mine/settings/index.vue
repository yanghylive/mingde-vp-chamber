<template>
  <view class="settings-page">
    <view class="card group">
      <view class="item" @tap="clearCache">
        <text class="it-icon">清</text>
        <text class="it-label">清除缓存</text>
        <text class="it-arrow">></text>
      </view>
      <view class="item" @tap="about">
        <text class="it-icon">ℹ️</text>
        <text class="it-label">关于明德恒智</text>
        <text class="it-arrow">></text>
      </view>
    </view>

    <view v-if="isLogin" class="logout-btn" @tap="doLogout">退出登录</view>
  </view>
</template>

<script>
import { checkLogin, logout } from '@/libs/login'

export default {
  data() {
    return {
      isLogin: false
    }
  },
  onShow() {
    this.isLogin = checkLogin()
  },
  methods: {
    clearCache() {
      uni.clearStorageSync()
      this.isLogin = false
      uni.showToast({ title: '缓存已清除', icon: 'success' })
    },
    about() {
      uni.showModal({
        title: '明德恒智',
        content: '明德恒智 · PBC 企业家事业共同体\nv1.0.0',
        showCancel: false
      })
    },
    doLogout() {
      uni.showModal({
        title: '退出登录',
        content: '确定退出当前账号？',
        success: (res) => {
          if (res.confirm) {
            logout()
            this.isLogin = false
            uni.showToast({ title: '已退出', icon: 'success' })
            setTimeout(() => {
              uni.reLaunch({ url: '/pages/index/index' })
            }, 500)
          }
        }
      })
    }
  }
}
</script>

<style lang="scss">
.settings-page {
  padding: 32rpx;
}
.group {
  padding: 8rpx 0;
}
.item {
  display: flex;
  align-items: center;
  padding: 30rpx 32rpx;
  gap: 20rpx;
  border-bottom: 1rpx solid #f5f2ea;
}
.item:last-child {
  border-bottom: none;
}
.it-icon {
  font-size: 32rpx;
}
.it-label {
  flex: 1;
  font-size: 28rpx;
  color: #273b59;
}
.it-arrow {
  color: #c0c6d0;
  font-size: 32rpx;
}
.logout-btn {
  margin-top: 48rpx;
  text-align: center;
  padding: 26rpx 0;
  border-radius: 20rpx;
  background: #fff;
  color: #e5484d;
  font-size: 28rpx;
  font-weight: 600;
}
</style>
