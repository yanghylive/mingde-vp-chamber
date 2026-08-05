<template>
  <view class="notifications-page">
    <page-header title="通知中心" eyebrow="活动提醒与系统通知" />
    <view v-if="loading" class="empty">通知加载中…</view>
    <view v-else-if="list.length === 0" class="empty-wrap glass-dark">
        <view class="empty-icon"><view class="ic ic-lg ic-bell-dark" /></view>
        <text class="empty-title">暂无通知</text>
        <text class="empty-sub">报名活动、签到、积分变动后会在这里提醒你</text>
      </view>
    <view v-else class="list">
      <view
        v-for="n in list"
        :key="n.id"
        class="{{'notif card' + (!isRead(n) ? ' notif-unread' : '')}}"
      >
        <view class="{{'n-icon n-icon-' + notifType(n)}}">
          <text>{{ notifChar(n) }}</text>
        </view>
        <view class="n-info">
          <text class="n-title">{{ n.title }}</text>
          <text v-if="n.content || n.body" class="n-content">{{ n.content || n.body }}</text>
          <text class="n-time">{{ timeText(n.created_at) }}</text>
        </view>
        <view v-if="!isRead(n)" class="n-dot" />
      </view>
    </view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { toDate } from '@/common/format'

export default {
  components: { PageHeader },
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
    isRead(n) {
      return n.is_read || n.read === true || n.read === 1
    },
    notifType(n) {
      var t = String(n.type || n.category || '').toLowerCase()
      if (t.indexOf('event') >= 0 || t.indexOf('activity') >= 0 || n.title === '活动提醒') return 'event'
      if (t.indexOf('system') >= 0) return 'system'
      return 'default'
    },
    notifChar(n) {
      var t = this.notifType(n)
      if (t === 'event') return '活'
      if (t === 'system') return '通'
      return '消'
    },
    timeText(ts) {
      var d = toDate(ts)
      if (!d) return ''
      var dt = new Date(d.replace(/-/g, '/'))
      var diff = Date.now() - dt.getTime()
      var min = Math.floor(diff / 60000)
      if (min < 1) return '刚刚'
      if (min < 60) return min + ' 分钟前'
      var h = Math.floor(min / 60)
      if (h < 24) return h + ' 小时前'
      return d.slice(5, 7) + '月' + d.slice(8, 10) + '日'
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
.empty-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 64rpx 40rpx;
  border-radius: 48rpx;
}
.empty-icon {
  margin-bottom: 24rpx;
}
.empty-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #fff;
}
.empty-sub {
  font-size: 22rpx;
  color: rgba(191, 219, 254, 0.7);
  margin-top: 8rpx;
  text-align: center;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.notif {
  display: flex;
  gap: 20rpx;
  padding: 32rpx;
  border-left: 6rpx solid transparent;
}
.notif-unread {
  border-left-color: #d98a2d;
}
.n-icon {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28rpx;
  font-weight: 700;
  flex-shrink: 0;
}
.n-icon-event {
  background: #f4f0e8;
  color: #d18a35;
}
.n-icon-system {
  background: #eef2f8;
  color: #5c7fbf;
}
.n-icon-default {
  background: #f4f0e8;
  color: #b87325;
}
.n-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.n-title {
  font-size: 26rpx;
  font-weight: 600;
  color: #10284d;
}
.n-content {
  font-size: 22rpx;
  color: #8d97a6;
  line-height: 1.6;
}
.n-time {
  font-size: 20rpx;
  color: #b0b8c4;
}
.n-dot {
  width: 16rpx;
  height: 16rpx;
  border-radius: 50%;
  background: #e05d3f;
  flex-shrink: 0;
  margin-top: 8rpx;
}
</style>
