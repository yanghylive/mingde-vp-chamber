<template>
  <view class="notifications-page">
    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="list.length === 0" class="empty">暂无通知</view>
    <view v-else class="list">
      <view
        v-for="n in list"
        :key="n.id"
        :class="['notif card', !n.is_read && 'notif-unread']"
      >
        <view class="n-icon">{{ n.title === '活动提醒' ? '活' : '通' }}</view>
        <view class="n-info">
          <text class="n-title">{{ n.title }}</text>
          <text v-if="n.content" class="n-content">{{ n.content }}</text>
          <text class="n-time">{{ timeText(n.created_at) }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { toDate } from '@/common/format'

export default {
  data() {
    return {
      list: [],
      loading: true
    }
  },
  onLoad() {
    this.loadData()
  },
  methods: {
    async loadData() {
      try {
        this.list = await chamber.meNotifications()
      } catch (e) {}
      this.loading = false
    },
    timeText(ts) {
      return toDate(ts, 'datetime')
    }
  }
}
</script>

<style lang="scss">
.notifications-page {
  padding: 24rpx 32rpx 60rpx;
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
.notif {
  display: flex;
  gap: 20rpx;
  padding: 28rpx;
  border-left: 6rpx solid transparent;
}
.notif-unread {
  border-left-color: #d98a2d;
}
.n-icon {
  font-size: 36rpx;
}
.n-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.n-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
}
.n-content {
  font-size: 24rpx;
  color: #516580;
  line-height: 1.6;
}
.n-time {
  font-size: 20rpx;
  color: #c0c6d0;
}
</style>
