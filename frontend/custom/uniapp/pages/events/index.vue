<template>
  <view class="events-page">
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

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="filtered.length === 0" class="empty">暂无活动</view>
    <view v-else class="list">
      <view
        v-for="ev in filtered"
        :key="ev.id"
        class="event-card card"
        @tap="goDetail(ev.id)"
      >
        <view class="ec-head">
          <view class="ec-date">
            <text class="ed-day">{{ dayOf(ev) }}</text>
            <text class="ed-month">{{ monthOf(ev) }}</text>
          </view>
          <view class="ec-info">
            <text class="ec-title">{{ ev.title }}</text>
            <text class="ec-meta">{{ timeOf(ev) }} · {{ ev.location || '明德' }}</text>
            <text class="ec-desc">{{ (ev.description || '').slice(0, 40) }}</text>
          </view>
        </view>
        <view class="ec-foot">
          <text class="ec-type">{{ ev.event_type || '活动' }}</text>
          <text v-if="ev.checkin_reward_points" class="ec-reward">签到 +{{ ev.checkin_reward_points }}积分</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { toDate } from '@/common/format'

const CHIPS = ['全部', '大咖讲堂', '交流沙龙', '公益活动', '路演']

export default {
  data() {
    return {
      events: [],
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
        this.events = await chamber.events()
      } catch (e) {}
      this.applyChip()
      this.loading = false
    },
    applyChip() {
      if (this.activeChip === 0) {
        this.filtered = this.events
      } else {
        const c = CHIPS[this.activeChip]
        this.filtered = this.events.filter((ev) => (ev.event_type || '').indexOf(c) >= 0)
      }
    },
    dayOf(ev) {
      return toDate(ev.start_time).slice(8, 10)
    },
    monthOf(ev) {
      return toDate(ev.start_time).slice(5, 7) + '月'
    },
    timeOf(ev) {
      return toDate(ev.start_time, 'datetime')
    },
    goDetail(id) {
      uni.navigateTo({ url: '/pages/events/detail?id=' + id })
    }
  }
}
</script>

<style scoped lang="scss">
.events-page {
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
.event-card {
  padding: 28rpx;
}
.ec-head {
  display: flex;
  gap: 20rpx;
}
.ec-date {
  width: 100rpx;
  height: 100rpx;
  border-radius: 20rpx;
  background: linear-gradient(135deg, #fff0dc, #f6e2c2);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ed-day {
  font-size: 38rpx;
  font-weight: 800;
  color: #b8751d;
}
.ed-month {
  font-size: 20rpx;
  color: #ad6b22;
}
.ec-info {
  flex: 1;
  min-width: 0;
}
.ec-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #273b59;
  display: block;
}
.ec-meta {
  font-size: 22rpx;
  color: #8a94a3;
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
.ec-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 20rpx;
  padding-top: 20rpx;
  border-top: 1rpx solid #f5f2ea;
}
.ec-type {
  font-size: 22rpx;
  color: #b8751d;
  background: #f6ead6;
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
}
.ec-reward {
  font-size: 22rpx;
  color: #ad6b22;
}
</style>
