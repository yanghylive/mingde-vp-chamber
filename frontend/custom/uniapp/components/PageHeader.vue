<template>
  <!-- 统一沉浸式页头（对齐 H5 PageHeader：返回 + 标题 + 眉题/副标） -->
  <view class="ph-wrap">
    <view class="ph-back glass-control" @tap="goBack">
      <view class="ic ic-sm ic-chevron-left-gray" />
    </view>
    <view class="ph-info">
      <text class="ph-title">{{ title }}</text>
      <text v-if="eyebrow" class="ph-eyebrow">{{ eyebrow }}</text>
      <text v-if="subtitle && !eyebrow" class="ph-subtitle">{{ subtitle }}</text>
    </view>
    <view class="ph-slot">
      <slot />
    </view>
  </view>
</template>

<script>
export default {
  name: 'PageHeader',
  props: {
    title: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    subtitle: { type: String, default: '' }
  },
  methods: {
    goBack() {
      const pages = getCurrentPages()
      if (pages.length > 1) {
        uni.navigateBack()
      } else {
        uni.switchTab({ url: '/pages/index/index' })
      }
    }
  }
}
</script>

<style lang="scss">
.ph-wrap {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: env(safe-area-inset-top) 32rpx 16rpx;
}
.ph-back {
  width: 64rpx;
  height: 64rpx;
  border-radius: 20rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ph-info {
  flex: 1;
  min-width: 0;
}
.ph-title {
  display: block;
  font-size: 32rpx;
  font-weight: 800;
  color: #17233d;
  line-height: 1.3;
}
.ph-eyebrow {
  display: block;
  font-size: 20rpx;
  color: #8a94a3;
  margin-top: 4rpx;
}
.ph-subtitle {
  display: block;
  font-size: 20rpx;
  color: #8a94a3;
  margin-top: 4rpx;
}
.ph-slot {
  flex-shrink: 0;
}
</style>
