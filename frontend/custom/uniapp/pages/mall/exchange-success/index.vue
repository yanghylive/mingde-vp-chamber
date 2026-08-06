<template>
  <view class="success-page">
    <page-header title="兑换成功" />
    <view class="s-icon-box">
      <text class="s-icon-text">OK</text>
    </view>
    <view class="s-title">兑换成功</view>
    <view class="s-sub">积分已扣减，兑换订单已生成</view>

    <view v-if="order" class="s-order card">
      <view class="so-head">
        <view class="so-head-icon">
          <image class="ic ic-md" src="/static/icons/ic-store-gold.png" mode="aspectFit" />
        </view>
        <view class="so-head-info">
          <text class="so-name">{{ order.name }}</text>
          <text class="so-cost">消耗 {{ order.points }} 积分<span v-if="Number(order.cash) > 0"> · 价值 ¥{{ order.cash }}</span></text>
        </view>
      </view>
      <view class="so-detail">
        <view class="so-row"><text class="so-label">消耗积分</text><text class="so-value so-points">{{ order.points }}</text></view>
        <view v-if="order.orderNo" class="so-row"><text class="so-label">订单号</text><text class="so-value">#{{ order.orderNo }}</text></view>
        <view v-if="Number(order.cash) > 0" class="so-row"><text class="so-label">现金支付</text><text class="so-value">¥{{ order.cash }}</text></view>
      </view>
    </view>

    <view class="s-btns">
      <view class="btn-primary s-btn" @tap="goMall">
        <text>返回商城</text>
      </view>
      <view class="btn-secondary s-btn" @tap="goMine">
        <text>去「我的」查看记录</text>
      </view>
    </view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
export default {
  components: { PageHeader },
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
    goMine() {
      uni.switchTab({ url: '/pages/mine/index' })
    }
  }
}
</script>

<style lang="scss">
.success-page {
  padding: 112rpx 32rpx 60rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}

/* Success icon */
.s-icon-box {
  width: 160rpx;
  height: 160rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #e6f4ec, #cde9da);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 32rpx;
}
.s-icon-text {
  font-size: 60rpx;
  font-weight: 700;
  color: #059669;
}
.s-title {
  font-size: 48rpx;
  font-weight: 700;
  color: #17325b;
}
.s-sub {
  font-size: 26rpx;
  color: #8a94a3;
  margin-top: 16rpx;
  text-align: center;
}

/* Order card */
.s-order {
  width: 100%;
  margin-top: 40rpx;
  padding: 0;
  overflow: hidden;
}
.so-head {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 32rpx;
  border-bottom: 1rpx solid #edf0f4;
}
.so-head-icon {
  width: 96rpx;
  height: 96rpx;
  border-radius: 24rpx;
  background: #fff0dc;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.so-head-info {
  flex: 1;
  min-width: 0;
}
.so-name {
  display: block;
  font-size: 30rpx;
  font-weight: 700;
  color: #203755;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.so-cost {
  display: block;
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 6rpx;
}
.so-detail {
  padding: 32rpx;
}
.so-row {
  display: flex;
  justify-content: space-between;
  padding: 10rpx 0;
  font-size: 26rpx;
}
.so-label {
  color: #8a94a3;
}
.so-value {
  color: #17325b;
  font-weight: 600;
}
.so-points {
  color: #c57620;
}

/* Buttons */
.s-btns {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
  margin-top: 48rpx;
  width: 100%;
}
.s-btn {
  text-align: center;
  padding: 26rpx 0;
  font-size: 28rpx;
  font-weight: 600;
  border-radius: 24rpx;
}
</style>
