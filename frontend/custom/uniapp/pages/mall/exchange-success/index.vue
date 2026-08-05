<template>
  <view class="success-page">
    <view class="s-icon">!!</view>
    <view class="s-title">兑换成功</view>
    <view class="s-sub">商品兑换已受理，详情可在积分记录中查看</view>
    <view v-if="order" class="s-order card">
      <view class="so-row"><text class="so-label">商品</text><text class="so-value">{{ order.name }}</text></view>
      <view v-if="order.orderNo" class="so-row"><text class="so-label">订单号</text><text class="so-value">{{ order.orderNo }}</text></view>
      <view class="so-row"><text class="so-label">消耗积分</text><text class="so-value">{{ order.points }}</text></view>
      <view v-if="Number(order.cash) > 0" class="so-row"><text class="so-label">现金支付</text><text class="so-value">¥{{ order.cash }}</text></view>
    </view>
    <view class="s-btns">
      <view class="btn-secondary s-btn" @tap="goMall">继续逛逛</view>
      <view class="btn-primary s-btn" @tap="goHome">返回首页</view>
    </view>
  </view>
</template>

<script>
export default {
  data() {
    return {
      order: null
    }
  },
  onLoad(options) {
    if (!options) return
    this.order = {
      name: decodeURIComponent(options.name || '商品'),
      orderNo: options.order || '',
      points: options.points || 0,
      cash: options.cash || 0
    }
  },
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
.s-order {
  width: 100%;
  padding: 32rpx;
  margin-top: 40rpx;
}
.so-row {
  display: flex;
  justify-content: space-between;
  padding: 12rpx 0;
  font-size: 26rpx;
}
.so-label {
  color: #8a94a3;
}
.so-value {
  color: #273b59;
  font-weight: 600;
  max-width: 60%;
  text-align: right;
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
