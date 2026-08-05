<template>
  <view class="paths-page">
    <view class="head glass-dark">
      <text class="h-title">如何获得积分</text>
      <text class="h-sub">参与活动、贡献与学习，积分可兑换好礼</text>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else class="list">
      <view v-for="(p, i) in paths" :key="i" class="path card">
        <view class="p-icon">{{ pathGlyph(p.icon) }}</view>
        <view class="p-info">
          <text class="p-title">{{ p.title }}</text>
          <text v-if="p.desc" class="p-desc">{{ p.desc }}</text>
        </view>
        <view class="p-points">+{{ p.points }}<text class="p-unit">{{ pathUnit(p.icon) }}</text></view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'

export default {
  data() {
    return {
      paths: [],
      loading: true
    }
  },
  onLoad() {
    this.loadData()
  },
  methods: {
    async loadData() {
      try {
        this.paths = await chamber.pointsPaths()
      } catch (e) {}
      this.loading = false
    },
    pathGlyph(icon) {
      const map = { coach: '🎓', charity: '🤝', roadshow: '🎤', distribution: '📢', study: '📚', medal: '🏅' }
      return map[icon] || '✦'
    },
    pathUnit(icon) {
      return icon === 'distribution' ? '/人' : '/次'
    }
  }
}
</script>

<style lang="scss">
.paths-page {
  padding: 32rpx;
}
.head {
  padding: 40rpx 36rpx;
  
  margin-bottom: 24rpx;
}
.h-title {
  display: block;
  font-size: 36rpx;
  font-weight: 800;
  color: #fff;
}
.h-sub {
  display: block;
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.6);
  margin-top: 10rpx;
}
.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.path {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 28rpx;
}
.p-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 24rpx;
  background: #fff0dc;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40rpx;
  flex-shrink: 0;
}
.p-info {
  flex: 1;
  min-width: 0;
}
.p-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
  display: block;
}
.p-desc {
  font-size: 22rpx;
  color: #8a94a3;
  display: block;
  margin-top: 6rpx;
}
.p-points {
  font-size: 34rpx;
  font-weight: 800;
  color: #b8751d;
}
.p-unit {
  font-size: 20rpx;
  color: #8a94a3;
  margin-left: 4rpx;
}
</style>
