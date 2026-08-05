<template>
  <view class="experts-page">
    <view class="chips">
      <view
        v-for="(c, i) in chips"
        :key="c"
        :class="['chip', activeChip === i && 'chip-active']"
        @tap="activeChip = i; applyChip()"
      >
        {{ c }}
      </view>
    </view>

    <view v-if="loading" class="empty"><skeleton type="list" :rows="3" /></view>
    <view v-else-if="filtered.length === 0" class="empty">暂无大咖</view>
    <view v-else class="list">
      <view
        v-for="e in filtered"
        :key="e.id"
        class="expert-card card"
        @tap="goDetail(e.id)"
      >
        <view class="ec-avatar">{{ (e.name || '大咖').slice(0, 1) }}</view>
        <view class="ec-info">
          <view class="ec-name-row">
            <text class="ec-name">{{ e.name }}</text>
            <text v-if="e.industry" class="ec-industry">{{ e.industry }}</text>
          </view>
          <text class="ec-title">{{ e.title || e.bio || '明德大咖' }}</text>
          <text class="ec-desc">{{ (e.description || '').slice(0, 30) }}</text>
        </view>
        <view class="ec-arrow">></view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import Skeleton from '@/components/Skeleton.vue'

const CHIPS = ['全部', 'AI 科技', '企业管理', '投资金融', '教育文化']

export default {
  components: { Skeleton },
  data() {
    return {
      experts: [],
      filtered: [],
      loading: true,
      activeChip: 0,
      chips: CHIPS
    }
  },
  onShow() {
    this.loadData()
  },
  onPullDownRefresh() {
    this.loadData().finally(() => uni.stopPullDownRefresh())
  },
  methods: {
    async loadData() {
      this.loading = true
      try {
        this.experts = await chamber.experts()
      } catch (e) {}
      this.applyChip()
      this.loading = false
    },
    applyChip() {
      if (this.activeChip === 0) {
        this.filtered = this.experts
      } else {
        const c = CHIPS[this.activeChip]
        this.filtered = this.experts.filter((e) => (e.industry || e.category || '').indexOf(c) >= 0)
      }
    },
    goDetail(id) {
      uni.navigateTo({ url: '/pages/experts/detail?id=' + id })
    }
  }
}
</script>

<style lang="scss">
.experts-page {
  padding: 24rpx 32rpx 60rpx;
}
.chips {
  display: flex;
  gap: 16rpx;
  margin-bottom: 24rpx;
  overflow-x: auto;
  white-space: nowrap;
}
.chip {
  flex-shrink: 0;
  padding: 14rpx 30rpx;
  border-radius: 999rpx;
  background: #fff;
  color: #516580;
  font-size: 26rpx;
  box-shadow: 0 4rpx 12rpx rgba(39, 59, 89, 0.04);
}
.chip-active {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-weight: 600;
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
.expert-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 28rpx;
}
.ec-avatar {
  width: 112rpx;
  height: 112rpx;
  border-radius: 28rpx;
  
  color: #ffd78f;
  font-size: 44rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ec-info {
  flex: 1;
  min-width: 0;
}
.ec-name-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
}
.ec-name {
  font-size: 30rpx;
  font-weight: 700;
  color: #273b59;
}
.ec-industry {
  font-size: 20rpx;
  color: #b8751d;
  background: #f6ead6;
  padding: 4rpx 12rpx;
  border-radius: 999rpx;
}
.ec-title {
  font-size: 24rpx;
  color: #516580;
  display: block;
  margin-top: 8rpx;
}
.ec-desc {
  font-size: 22rpx;
  color: #a0a8b5;
  display: block;
  margin-top: 8rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ec-arrow {
  color: #c0c6d0;
  font-size: 36rpx;
}
</style>
