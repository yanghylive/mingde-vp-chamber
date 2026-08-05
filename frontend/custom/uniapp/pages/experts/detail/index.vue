<template>
  <view class="expert-detail-page">
    <page-header title="大咖主页" eyebrow="明德恒智AI企商汇 · 大咖" />
    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="error" class="empty">{{ error }}</view>
    <view v-else-if="expert">
      <!-- 大咖信息 -->
      <view class="hero glass-dark">
        <view class="hero-avatar">{{ (expert.name || '大咖').slice(0, 1) }}</view>
        <view class="hero-info">
          <view class="hero-name-row">
            <text class="hero-name">{{ expert.name }}</text>
            <text v-if="expert.industry" class="hero-industry">{{ expert.industry }}</text>
          </view>
          <text class="hero-title">{{ expert.title || '明德大咖' }}{{ expert.company ? ' · ' + expert.company : '' }}</text>
          <view class="hero-ai" @tap="goAiChat">
            <view class="ic ic-sm ic-bot-gold" />
            <text>大咖 AI 对话</text>
          </view>
        </view>
      </view>
      <view v-if="expert.bio || expert.description" class="bio card">
        <text class="bio-title">简介</text>
        <text class="bio-text">{{ expert.bio || expert.description }}</text>
      </view>

      <!-- 定价 -->
      <view class="section-head">
        <text class="section-title">1v1 预约</text>
        <text class="section-sub">线上 L2+ / 线下 L3+</text>
      </view>
      <view class="pricing card">
        <block v-if="pricingReady">
          <view class="price-item">
            <view class="pi-icon"><view class="ic ic-sm ic-monitor-gold" /></view>
            <text class="pi-label">线上 1v1</text>
            <text class="pi-points">{{ onlinePoints }} 积分</text>
            <text v-if="onlineCash > 0" class="pi-cash">+ ¥{{ onlineCash }}</text>
          </view>
          <view class="price-item">
            <view class="pi-icon"><view class="ic ic-sm ic-map-pin-orange" /></view>
            <text class="pi-label">线下 1v1</text>
            <text class="pi-points">{{ offlinePoints }} 积分</text>
            <text v-if="offlineCash > 0" class="pi-cash">+ ¥{{ offlineCash }}</text>
          </view>
        </block>
        <view v-else class="price-empty">收费明细定价更新中，敬请期待</view>
      </view>

      <!-- 档期 -->
      <view class="section-head">
        <text class="section-title">选择档期</text>
      </view>
      <view v-if="slotsState === 'loading'" class="empty small">档期加载中…</view>
      <view v-else-if="slotsState === 'error'" class="empty small">档期加载失败</view>
      <view v-else-if="slotsState === 'empty'" class="empty small">暂无开放档期，敬请期待</view>
      <view v-else class="slots">
        <view v-for="(list, day) in slotsByDay" :key="day" class="slot-day card">
          <text class="sd-date">{{ day }}</text>
          <view class="sd-list">
            <view
              v-for="s in list"
              :key="s.id"
              class="{{'slot-item' + (s.status !== 'open' ? ' slot-item-disabled' : '') + (selectedSlot === s.id ? ' slot-item-active' : '')}}"
              @tap="s.status === 'open' && selectSlot(s)"
            >
              <text class="si-time">{{ slotTime(s) }}</text>
              <text class="si-mode">{{ Number(s.location) === 1 || s.location === 'offline' ? '线下' : '线上' }}</text>
            </view>
          </view>
        </view>
      </view>

      <!-- 预约按钮 -->
      <view v-if="selectedSlot" class="book-bar">
        <view class="book-info">
          <text class="bi-label">已选档期</text>
          <text class="bi-value">{{ selectedSlotText }}</text>
        </view>
        <view class="{{'book-btn' + (submitting ? ' book-btn-disabled' : '')}}" @tap="submitAppointment">
          {{ submitting ? '提交中…' : '确认预约' }}
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { toDate } from '@/common/format'

export default {
  components: { PageHeader },
  data() {
    return {
      expertId: 0,
      expert: null,
      loading: true,
      error: '',
      slots: [],
      slotsState: 'loading',
      selectedSlot: null,
      mode: 'online',
      booked: false,
      submitting: false
    }
  },
  computed: {
    // 定价归一化（兼容嵌套 pricing 对象）
    pricingReady() {
      return Number(this.onlinePoints || 0) > 0 || Number(this.onlineCash || 0) > 0 || Number(this.offlinePoints || 0) > 0 || Number(this.offlineCash || 0) > 0
    },
    onlinePoints() {
      const p = this.expert && (this.expert.pricing || {})
      const v = p && p.online ? p.online.points : (this.expert && this.expert.online_points)
      return Number(v || 0)
    },
    onlineCash() {
      const p = this.expert && (this.expert.pricing || {})
      const v = p && p.online ? p.online.cash : (this.expert && this.expert.online_cash)
      return Number(v || 0)
    },
    offlinePoints() {
      const p = this.expert && (this.expert.pricing || {})
      const v = p && p.offline ? p.offline.points : (this.expert && this.expert.offline_points)
      return Number(v || 0)
    },
    offlineCash() {
      const p = this.expert && (this.expert.pricing || {})
      const v = p && p.offline ? p.offline.cash : (this.expert && this.expert.offline_cash)
      return Number(v || 0)
    },
    slotsByDay() {
      const map = {}
      const sorted = this.slots
        .slice()
        .sort((a, b) => (a.start_time || 0) - (b.start_time || 0))
      for (const s of sorted) {
        if (s.status !== 'open') continue
        const d = toDate(s.start_time)
        if (!map[d]) map[d] = []
        map[d].push(s)
      }
      return map
    },
    selectedSlotText() {
      const s = this.slots.find((x) => x.id === this.selectedSlot)
      return s ? toDate(s.start_time, 'datetime') + (Number(s.location) === 1 || s.location === 'offline' ? ' 线下' : ' 线上') : ''
    }
  },
  onLoad(options) {
    this.expertId = Number(options.id || 0)
    this.loadData()
  },
  methods: {
    async loadData() {
      const results = await Promise.allSettled([chamber.expertDetail(this.expertId), chamber.expertSlots(this.expertId)])
      if (results[0].status === 'fulfilled') {
        this.expert = results[0].value
      } else {
        this.error = '大咖信息加载失败'
      }
      if (results[1].status === 'fulfilled') {
        this.slots = results[1].value || []
        this.slotsState = this.slots.length ? 'ready' : 'empty'
      } else {
        this.slotsState = 'error'
      }
      this.loading = false
    },
    slotTime(s) {
      const t = toDate(s.start_time, 'datetime')
      return t ? t.slice(11) : ''
    },
    selectSlot(s) {
      this.selectedSlot = s.id
      this.mode = Number(s.location) === 1 || s.location === 'offline' ? 'offline' : 'online'
    },
    submitAppointment() {
      if (!checkLogin()) {
        uni.navigateTo({ url: '/pages/login/index' })
        return
      }
      this.submitting = true
      chamber
        .createAppointment({ expert_id: this.expertId, slot_id: this.selectedSlot, mode: this.mode })
        .then(() => {
          uni.showToast({ title: '预约成功，请按档期赴约', icon: 'success' })
          this.booked = true
          this.selectedSlot = null
          this.loadData()
        })
        .catch((e) => {
          uni.showToast({ title: (e && e.msg) || '预约失败', icon: 'none' })
        })
        .finally(() => {
          this.submitting = false
        })
    }
  },
  goAiChat() {
    uni.navigateTo({ url: '/pages/chat/index?expert=' + this.expertId })
  }
}
</script>

<style lang="scss">
.expert-detail-page {
  padding: 32rpx 32rpx 140rpx;
}
.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.empty.small {
  padding: 40rpx 0;
}
.hero {
  display: flex;
  align-items: center;
  gap: 28rpx;
  padding: 36rpx;
}
.hero-avatar {
  width: 160rpx;
  height: 160rpx;
  border-radius: 48rpx;
  
  color: #ffd78f;
  font-size: 56rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.hero-info {
  flex: 1;
  min-width: 0;
}
.hero-name-row {
  display: flex;
  align-items: center;
  gap: 14rpx;
}
.hero-name {
  font-size: 38rpx;
  font-weight: 800;
  color: #ffffff;
  color: #273b59;
}
.hero-industry {
  font-size: 22rpx;
  color: #f6c982;
  background: rgba(255, 255, 255, 0.1);
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
}
.hero-ai {
  display: inline-flex;
  align-items: center;
  gap: 8rpx;
  margin-top: 14rpx;
  font-size: 22rpx;
  font-weight: 600;
  color: #f5c276;
  background: rgba(255, 255, 255, 0.1);
  padding: 10rpx 22rpx;
  border-radius: 999rpx;
}
.price-empty {
  text-align: center;
  font-size: 24rpx;
  color: #8d97a6;
  background: #f2f5f8;
  border-radius: 20rpx;
  padding: 32rpx 0;
}
.hero-title {
  font-size: 22rpx;
  color: #f5c276;
  display: block;
  margin-top: 10rpx;
}
.bio {
  margin-top: 24rpx;
  padding: 32rpx;
}
.bio-title {
  font-size: 26rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
  margin-bottom: 12rpx;
}
.bio-text {
  font-size: 24rpx;
  color: #516580;
  line-height: 1.7;
}
.section-head {
  display: flex;
  align-items: baseline;
  gap: 16rpx;
  margin: 36rpx 0 20rpx;
}
.section-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #273b59;
}
.section-sub {
  font-size: 22rpx;
  color: #8a94a3;
}
.pricing {
  display: flex;
  gap: 20rpx;
  padding: 28rpx;
}
.price-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10rpx;
  padding: 24rpx;
  background: #f7f5f0;
  border-radius: 20rpx;
}
.pi-icon {
  font-size: 40rpx;
}
.pi-label {
  font-size: 24rpx;
  color: #516580;
}
.pi-points {
  font-size: 30rpx;
  font-weight: 800;
  color: #b8751d;
}
.pi-cash {
  font-size: 22rpx;
  color: #8a94a3;
}
.slots {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.slot-day {
  padding: 28rpx;
}
.sd-date {
  font-size: 26rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
  margin-bottom: 16rpx;
}
.sd-list {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}
.slot-item {
  display: flex;
  align-items: center;
  gap: 10rpx;
  padding: 14rpx 24rpx;
  border-radius: 14rpx;
  border: 2rpx solid #f0ddc2;
  background: #fff;
}
.slot-item-active {
  border-color: #b8751d;
  background: #f6ead6;
}
.slot-item-disabled {
  opacity: 0.4;
}
.si-time {
  font-size: 26rpx;
  font-weight: 600;
  color: #273b59;
}
.si-mode {
  font-size: 20rpx;
  color: #8a94a3;
}
.book-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24rpx 40rpx calc(24rpx + env(safe-area-inset-bottom));
  background: #fff;
  box-shadow: 0 -8rpx 24rpx rgba(39, 59, 89, 0.08);
}
.book-info {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}
.bi-label {
  font-size: 20rpx;
  color: #8a94a3;
}
.bi-value {
  font-size: 26rpx;
  font-weight: 600;
  color: #273b59;
}
.book-btn {
  padding: 20rpx 56rpx;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 28rpx;
  font-weight: 700;
}
.book-btn-disabled {
  opacity: 0.6;
}
</style>
