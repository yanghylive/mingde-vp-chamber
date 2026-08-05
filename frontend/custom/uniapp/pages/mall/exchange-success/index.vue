<template>
  <view class="success-page">
    <view class="s-icon">!!</view>
    <view class="s-title">兑换成功</view>
    <view class="s-sub">商品兑换已受理，详情可在积分记录中查看</view>
    <view class="s-btns">
      <view class="btn-secondary s-btn" @tap="goMall">继续逛逛</view>
      <view class="btn-primary s-btn" @tap="goHome">返回首页</view>
    </view>
  </view>
</template>

<script>
export default {
  methods: {
    goMall() {
      uni.switchTab({ url: '/pages/mall/index' })
    },
    goHome() {
      uni.switchTab({ url: '/pages/index/index' })
    }
  }
}
</script>

<style lang="scss">
.success-page {
  padding: 140rpx 60rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.s-icon {
  font-size: 120rpx;
}
.s-title {
  font-size: 40rpx;
  font-weight: 800;
  color: #273b59;
  margin-top: 32rpx;
}
.s-sub {
  font-size: 26rpx;
  color: #8a94a3;
  margin-top: 16rpx;
  text-align: center;
}
.s-btns {
  display: flex;
  gap: 24rpx;
  margin-top: 64rpx;
  width: 100%;
}
.s-btn {
  flex: 1;
  text-align: center;
}
</style>
