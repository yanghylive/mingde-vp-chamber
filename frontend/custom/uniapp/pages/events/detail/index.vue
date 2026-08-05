<template>
  <view class="event-detail-page">
    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="error" class="empty">{{ error }}</view>
    <view v-else-if="event">
      <!-- 头部 -->
      <view class="hero card">
        <view class="hero-date">
          <text class="hd-day">{{ dayOf }}</text>
          <text class="hd-month">{{ monthOf }}</text>
        </view>
        <view class="hero-info">
          <text class="hero-title">{{ event.title }}</text>
          <text class="hero-meta">{{ timeOf }} · {{ locationText }}</text>
          <view class="hero-actions">
            <text class="hero-type">{{ event.event_type || '活动' }}</text>
            <view v-if="locationText" class="nav-btn" @tap="navigate">
              <text class="nb-icon">地</text>
              <text class="nb-text">导航</text>
            </view>
          </view>
        </view>
      </view>

      <!-- 签到奖励 -->
      <view v-if="event.checkin_reward_points || event.checkin_reward_contribution" class="reward card">
        <text class="rw-icon">礼</text>
        <text class="rw-text">
          现场签到奖励
          <text v-if="event.checkin_reward_points" class="rw-gold">+{{ event.checkin_reward_points }} 积分</text>
          <text v-if="event.checkin_reward_contribution" class="rw-gold"> +{{ event.checkin_reward_contribution }} 贡献</text>
        </text>
      </view>

      <!-- 活动详情 -->
      <view v-if="event.description" class="desc card">
        <text class="desc-title">活动详情</text>
        <text class="desc-text">{{ event.description }}</text>
      </view>

      <!-- 票档 -->
      <view class="section-head">
        <text class="section-title">选择票档</text>
      </view>
      <view class="tickets">
        <view v-for="t in tickets" :key="t.id" class="ticket card">
          <view class="tk-info">
            <text class="tk-name">{{ t.name || '标准票' }}</text>
            <text class="tk-meta">¥{{ priceOf(t) }} · {{ t.integral_price > 0 ? t.integral_price + ' 积分' : '免费' }}</text>
          </view>
          <view
            :class="['tk-btn', (registered || registering) && 'tk-btn-disabled']"
            @tap="onRegister(t.id)"
          >
            {{ registered ? '已报名' : registering ? '报名中…' : '报名' }}
          </view>
        </view>
      </view>

      <view v-if="registered" class="registered-tip">OK 你已报名，活动当天出示本页签到</view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { toDate } from '@/common/format'

export default {
  data() {
    return {
      eventId: 0,
      event: null,
      loading: true,
      error: '',
      registering: false,
      registered: false
    }
  },
  computed: {
    dayOf() {
      return toDate(this.event && this.event.start_time).slice(8, 10)
    },
    monthOf() {
      return toDate(this.event && this.event.start_time).slice(5, 7) + '月'
    },
    timeOf() {
      return toDate(this.event && this.event.start_time, 'datetime')
    },
    tickets() {
      const ev = this.event
      if (!ev) return []
      if (ev.tickets && ev.tickets.length) return ev.tickets
      return [{ id: ev.id, name: '标准票', price: 0, integral_price: 0 }]
    },
    locationText() {
      const ev = this.event
      if (!ev) return ''
      return ev.location_name || ev.location || ev.address || '明德'
    }
  },
  onLoad(options) {
    this.eventId = Number(options.id || 0)
    this.loadData()
  },
  methods: {
    async loadData() {
      try {
        this.event = await chamber.eventDetail(this.eventId)
        this.registered = Boolean(this.event && this.event.registered)
      } catch (e) {
        this.error = '活动加载失败'
      }
      this.loading = false
    },
    priceOf(t) {
      const n = Number(t.price || 0)
      return n > 0 ? (Number.isInteger(n) ? n : n.toFixed(2)) : '免费'
    },
    navigate() {
      const ev = this.event
      const lat = Number(ev.latitude || 0)
      const lng = Number(ev.longitude || 0)
      const name = ev.location_name || ev.location || '活动地点'
      const address = ev.address || name
      if (lat && lng) {
        // 原生地图导航
        uni.openLocation({
          latitude: lat,
          longitude: lng,
          name: name,
          address: address,
          scale: 16,
          fail: () => {
            uni.showToast({ title: '打开地图失败', icon: 'none' })
          }
        })
      } else {
        // 无坐标：提示地址 + 复制
        uni.showModal({
          title: '活动地点',
          content: address + '\n\n（暂未配置导航坐标，可复制地址自行搜索）',
          confirmText: '复制地址',
          cancelText: '知道了',
          success: (res) => {
            if (res.confirm) {
              uni.setClipboardData({ data: address })
            }
          }
        })
      }
    },
    onRegister(ticketId) {
      if (this.registered || this.registering) return
      if (!checkLogin()) {
        uni.navigateTo({ url: '/pages/login/index' })
        return
      }
      this.registering = true
      chamber
        .registerEvent(this.eventId, ticketId)
        .then(() => {
          this.registered = true
          uni.showToast({ title: '报名成功', icon: 'success' })
        })
        .catch((e) => {
          uni.showToast({ title: (e && e.msg) || '报名失败', icon: 'none' })
        })
        .finally(() => {
          this.registering = false
        })
    }
  }
}
</script>

<style lang="scss">
.event-detail-page {
  padding: 32rpx 32rpx 80rpx;
}
.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.hero {
  display: flex;
  gap: 24rpx;
  padding: 32rpx;
}
.hero-date {
  width: 116rpx;
  height: 116rpx;
  border-radius: 24rpx;
  background: linear-gradient(135deg, #fff0dc, #f6e2c2);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.hd-day {
  font-size: 44rpx;
  font-weight: 800;
  color: #b8751d;
}
.hd-month {
  font-size: 22rpx;
  color: #ad6b22;
}
.hero-info {
  flex: 1;
  min-width: 0;
}
.hero-title {
  font-size: 34rpx;
  font-weight: 800;
  color: #273b59;
  display: block;
}
.hero-meta {
  font-size: 24rpx;
  color: #8a94a3;
  display: block;
  margin-top: 10rpx;
}
.hero-type {
  display: inline-block;
  font-size: 22rpx;
  color: #b8751d;
  background: #f6ead6;
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
  margin-top: 12rpx;
}
.hero-actions {
  display: flex;
  align-items: center;
  gap: 16rpx;
  margin-top: 12rpx;
}
.nav-btn {
  display: flex;
  align-items: center;
  gap: 6rpx;
  padding: 6rpx 20rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 22rpx;
  font-weight: 600;
}
.nb-icon {
  font-size: 22rpx;
}
.nb-text {
  font-size: 22rpx;
}
.reward {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 24rpx 32rpx;
  margin-top: 20rpx;
  background: linear-gradient(135deg, #fffaf2, #fff);
}
.rw-icon {
  font-size: 36rpx;
}
.rw-text {
  font-size: 24rpx;
  color: #516580;
}
.rw-gold {
  color: #b8751d;
  font-weight: 700;
}
.desc {
  margin-top: 20rpx;
  padding: 32rpx;
}
.desc-title {
  font-size: 28rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
  margin-bottom: 12rpx;
}
.desc-text {
  font-size: 26rpx;
  color: #516580;
  line-height: 1.8;
  white-space: pre-wrap;
}
.section-head {
  margin: 36rpx 0 20rpx;
}
.section-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #273b59;
}
.tickets {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.ticket {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx;
}
.tk-info {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.tk-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
}
.tk-meta {
  font-size: 22rpx;
  color: #8a94a3;
}
.tk-btn {
  padding: 16rpx 40rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 26rpx;
  font-weight: 600;
}
.tk-btn-disabled {
  opacity: 0.5;
}
.registered-tip {
  text-align: center;
  margin-top: 32rpx;
  padding: 24rpx;
  background: #f0f7ec;
  border-radius: 16rpx;
  color: #4c8a3f;
  font-size: 24rpx;
}
</style>
