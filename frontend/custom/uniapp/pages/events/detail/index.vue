<template>
  <view class="event-detail-page">
    <page-header title="活动详情" eyebrow="明德恒智AI企商汇 · 官方活动" />
    <view v-if="loading" class="empty"><skeleton type="list" :rows="2" /></view>
    <view v-else-if="error" class="error-box">
      <text class="error-text">{{ error }}</text>
      <view class="btn-secondary err-btn" @tap="goBack">返回活动列表</view>
    </view>
    <block v-else-if="event">
      <!-- 头部封面 -->
      <view class="{{'hero' + (' ' + metaTone(event.event_type))}}">
        <view class="hero-ring r1" />
        <view class="hero-ring r2" />
        <view class="hero-badge">{{ metaLabel(event.event_type) }}</view>
        <view class="hero-title">{{ event.title }}</view>
        <view class="hero-summary">{{ event.summary || event.description }}</view>
        <view class="hero-tags">
          <text v-if="event.min_tier" class="htag htag-tier">需 L{{ event.min_tier }} 等级</text>
          <text v-if="event.checkin_reward_points" class="htag htag-reward">签到 +{{ event.checkin_reward_points }} 积分</text>
        </view>
      </view>

      <!-- 时间地点 -->
      <view class="card info-card">
        <view class="info-row">
          <view class="info-icon info-icon-time"><image class="ic ic-sm" src="/static/icons/ic-clock-3-orange.png" mode="aspectFit" /></view>
          <text class="info-text">{{ timeText }}</text>
        </view>
        <view class="info-row">
          <view class="info-icon info-icon-loc"><image class="ic ic-sm" src="/static/icons/ic-map-pin-orange.png" mode="aspectFit" /></view>
          <view class="info-loc">
            <text class="info-loc-name">{{ event.location_name || '待定地点' }}</text>
            <text v-if="event.address" class="info-loc-addr">{{ event.address }}</text>
          </view>
          <view class="info-nav" @tap="navigate">
            <image class="ic ic-xs" src="/static/icons/ic-navigation-blue.png" mode="aspectFit" />
            <text>导航</text>
          </view>
        </view>
      </view>

      <!-- 报名票种 -->
      <view class="sec-title">
        <text class="st-icon">票</text>
        <text class="st-text">报名票种</text>
      </view>
      <view class="tickets">
        <view v-for="t in tickets" :key="t.id" class="ticket card">
          <view class="tk-info">
            <text class="tk-name">{{ t.name || '标准票' }}</text>
            <text class="tk-seats">席 {{ ticketSeats(t) }}</text>
          </view>
          <view class="tk-right">
            <view class="tk-price">
              <text v-if="t.integral_price > 0" class="tk-integral">积 {{ t.integral_price }} 积分</text>
              <text class="tk-cash">{{ priceText(t) }}</text>
            </view>
            <view v-if="!VIRTUAL_PAY_DISABLED || isFreeTicket(t)" class="{{'tk-btn' + ((registered || registering) ? ' tk-btn-disabled' : '')}}" @tap="onRegister(t.id)">
              {{ registered ? '已报名' : registering ? '提交中…' : '报名' }}
            </view>
            <view v-else class="tk-btn tk-btn-disabled">即将开放</view>
          </view>
        </view>
      </view>

      <!-- 报名提示 -->
      <view v-if="msg" class="{{'msg' + (registered ? ' msg-ok' : ' msg-err')}}">{{ msg }}</view>

      <!-- 签到入口 -->
      <view class="checkin-card card" @tap="goCheckin">
        <view class="ci-icon"><image class="ic ic-md" src="/static/icons/ic-scan-line-white.png" mode="aspectFit" /></view>
        <view class="ci-info">
          <text class="ci-title">现场扫码签到</text>
          <text class="ci-sub">{{ event.checkin_reward_points ? '签到立得 +' + event.checkin_reward_points + ' 积分' : '凭活动二维码签到' }}</text>
        </view>
        <text class="ci-arrow">></text>
      </view>

      <!-- 活动详情 -->
      <view v-if="event.detail || event.description" class="sec-title">
        <text class="st-icon">详</text>
        <text class="st-text">活动详情</text>
      </view>
      <view v-if="event.detail || event.description" class="card desc-card">
        <text class="desc-text">{{ event.detail || event.description }}</text>
      </view>
    </block>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { toDate, fmtZhDateTime } from '@/common/format'
import Skeleton from '@/components/Skeleton.vue'
import { VIRTUAL_PAY_DISABLED } from '@/config/app'

const TYPE_META = {
  personal_growth: { label: '个人成长', tone: 'tone-growth' },
  business_industry: { label: '事业行业', tone: 'tone-industry' },
  charity: { label: '公益慈善', tone: 'tone-charity' },
  salon: { label: '交流沙龙', tone: 'tone-default' },
  lecture: { label: '大咖讲堂', tone: 'tone-default' }
}
const DEFAULT_META = { label: '官方活动', tone: 'tone-default' }

export default {
  components: { PageHeader, Skeleton },
  data() {
    return {
      VIRTUAL_PAY_DISABLED: VIRTUAL_PAY_DISABLED,
      eventId: 0,
      event: null,
      loading: true,
      error: '',
      registering: false,
      registered: false,
      msg: ''
    }
  },
  computed: {
    tickets() {
      const ev = this.event
      if (!ev) return []
      if (ev.tickets && ev.tickets.length) return ev.tickets
      return [{ id: ev.id, name: '标准票', price: 0, integral_price: 0 }]
    },
    timeText() {
      const ev = this.event
      if (!ev || !ev.start_time) return '时间待定'
      const s = fmtZhDateTime(ev.start_time)
      const e = ev.end_time ? fmtZhDateTime(ev.end_time) : ''
      if (!s) return '时间待定'
      return e ? s + ' — ' + e : s
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
    metaLabel(t) {
      return (TYPE_META[t] || DEFAULT_META).label
    },
    metaTone(t) {
      return (TYPE_META[t] || DEFAULT_META).tone
    },
    ticketSeats(t) {
      if (t.reserved_count != null && t.capacity) return (t.capacity - t.reserved_count) + ' 席可约' + (t.min_tier ? ' · 需 L' + t.min_tier : '')
      return t.min_tier ? '需 L' + t.min_tier : '名额有限'
    },
    priceText(t) {
      const p = Number(t.price || 0)
      return p > 0 ? (Number.isInteger(p) ? '¥' + p : '¥' + p.toFixed(2)) : '免费'
    },
    isFreeTicket(t) {
      return Number(t.price || 0) <= 0 && Number(t.integral_price || 0) <= 0
    },
    navigate() {
      const ev = this.event
      const lat = Number(ev.latitude || 0)
      const lng = Number(ev.longitude || 0)
      if (lat && lng) {
        uni.openLocation({
          latitude: lat,
          longitude: lng,
          name: ev.location_name || ev.address || ev.title,
          address: ev.address || ev.location_name || '',
          scale: 16,
          fail: () => uni.showToast({ title: '打开地图失败', icon: 'none' })
        })
      } else {
        uni.showModal({
          title: '活动地点',
          content: (ev.address || ev.location_name || '') + '\n\n（暂未配置导航坐标，可复制地址自行搜索）',
          confirmText: '复制地址',
          cancelText: '知道了',
          success: (res) => {
            if (res.confirm) uni.setClipboardData({ data: ev.address || ev.location_name || '' })
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
        .then((res) => {
          // 付费活动：后端返回 payment_required，需引导支付而非"报名成功"
          if (res && res.payment_required) {
            this.registered = false
            this.msg = '报名待支付，请完成付款后生效'
            uni.showModal({
              title: '待支付',
              content: '本活动需支付费用，报名已保留，请前往订单完成支付。',
              confirmText: '知道了',
              showCancel: false
            })
            return
          }
          this.registered = true
          this.msg = '报名成功，请在活动当天凭票签到'
          uni.showToast({ title: '报名成功', icon: 'success' })
        })
        .catch((e) => {
          this.msg = (e && e.msg) || '报名失败'
        })
        .finally(() => {
          this.registering = false
        })
    },
    goCheckin() {
      uni.navigateTo({ url: '/pages/events/checkin/index?event=' + this.eventId })
    },
    goBack() {
      uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/events/index' }) })
    }
  }
}
</script>

<style lang="scss">
.event-detail-page {
  padding: 24rpx 32rpx 80rpx;
  min-height: 100vh;
}
.empty {
  padding: 40rpx 0;
}
.error-box {
  text-align: center;
  padding: 120rpx 0;
}
.error-text {
  font-size: 28rpx;
  color: #b44444;
}
.err-btn {
  margin-top: 32rpx;
  padding: 18rpx 48rpx;
  display: inline-block;
}

/* 头部封面 */
.hero {
  position: relative;
  overflow: hidden;
  border-radius: 44rpx;
  padding: 40rpx 36rpx;
  color: #fff;
}
.tone-growth {
  background: linear-gradient(145deg, #1a4778, #102b50);
}
.tone-industry {
  background: linear-gradient(145deg, #b77a34, #82531f);
}
.tone-charity {
  background: linear-gradient(145deg, #477467, #294d43);
}
.tone-default {
  background: linear-gradient(145deg, rgba(12, 37, 72, 0.91), rgba(23, 66, 108, 0.78));
}
.hero-ring {
  position: absolute;
  border-radius: 50%;
  border: 1rpx solid rgba(255, 255, 255, 0.2);
  pointer-events: none;
}
.hero-ring.r1 {
  width: 256rpx;
  height: 256rpx;
  top: -64rpx;
  right: -48rpx;
}
.hero-ring.r2 {
  width: 160rpx;
  height: 160rpx;
  top: 32rpx;
  right: 40rpx;
  border-color: rgba(255, 255, 255, 0.12);
}
.hero-badge {
  display: inline-block;
  background: rgba(255, 255, 255, 0.15);
  color: #f6c77e;
  font-size: 22rpx;
  padding: 6rpx 16rpx;
  border-radius: 8rpx;
  border: 1rpx solid rgba(255, 255, 255, 0.15);
}
.hero-title {
  font-size: 40rpx;
  font-weight: 600;
  line-height: 1.4;
  margin-top: 20rpx;
}
.hero-summary {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.75);
  line-height: 1.6;
  margin-top: 16rpx;
}
.hero-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 24rpx;
}
.htag {
  font-size: 20rpx;
  padding: 6rpx 14rpx;
  border-radius: 8rpx;
}
.htag-tier {
  background: #fff2df;
  color: #a8691f;
}
.htag-reward {
  background: rgba(255, 255, 255, 0.12);
  color: #f6c77e;
}

/* 时间地点卡 */
.info-card {
  margin-top: 28rpx;
  padding: 28rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}
.info-row {
  display: flex;
  align-items: center;
  gap: 20rpx;
}
.info-icon {
  width: 72rpx;
  height: 72rpx;
  border-radius: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26rpx;
  font-weight: 700;
  flex-shrink: 0;
}
.info-icon-time {
  background: #e9f0f9;
  color: #285181;
}
.info-icon-loc {
  background: #fff0dc;
  color: #bd7627;
}
.info-text {
  flex: 1;
  font-size: 26rpx;
  color: #34455f;
}
.info-loc {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.info-loc-name {
  font-size: 26rpx;
  color: #34455f;
}
.info-loc-addr {
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 4rpx;
}
.info-nav {
  display: flex;
  align-items: center;
  gap: 6rpx;
  background: #eaf0f8;
  color: #24507f;
  font-size: 22rpx;
  font-weight: 600;
  padding: 12rpx 20rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
}

/* 票种 */
.sec-title {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin: 36rpx 0 20rpx;
}
.st-icon {
  width: 40rpx;
  height: 40rpx;
  border-radius: 12rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 22rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.st-text {
  font-size: 32rpx;
  font-weight: 700;
  color: #17325b;
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
  gap: 20rpx;
}
.tk-info {
  min-width: 0;
}
.tk-name {
  font-size: 28rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
}
.tk-seats {
  font-size: 22rpx;
  color: #8a94a3;
  display: block;
  margin-top: 8rpx;
}
.tk-right {
  display: flex;
  align-items: center;
  gap: 20rpx;
  flex-shrink: 0;
}
.tk-price {
  text-align: right;
}
.tk-integral {
  font-size: 20rpx;
  color: #b36f22;
  display: block;
}
.tk-cash {
  font-size: 30rpx;
  font-weight: 800;
  color: #c57620;
  display: block;
}
.tk-btn {
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  font-size: 24rpx;
  font-weight: 600;
  padding: 16rpx 32rpx;
  border-radius: 16rpx;
  box-shadow: 0 10rpx 24rpx rgba(185, 110, 29, 0.2);
}
.tk-btn-disabled {
  opacity: 0.5;
}

/* 报名提示 */
.msg {
  margin-top: 24rpx;
  padding: 20rpx 28rpx;
  border-radius: 16rpx;
  font-size: 22rpx;
  font-weight: 500;
}
.msg-ok {
  background: #e9f3ef;
  color: #3f715f;
}
.msg-err {
  background: #fdeeee;
  color: #b44444;
}

/* 签到入口 */
.checkin-card {
  margin-top: 28rpx;
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 28rpx;
  background: linear-gradient(135deg, #fffaf2, #fff);
  border: 1rpx solid #f0ddc2;
}
.ci-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 32rpx;
  background: #e9f0fb;
  color: #28517f;
  font-size: 30rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ci-info {
  flex: 1;
  min-width: 0;
}
.ci-title {
  font-size: 28rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
}
.ci-sub {
  font-size: 20rpx;
  color: #8a94a3;
  display: block;
  margin-top: 4rpx;
}
.ci-arrow {
  color: #c0c6d0;
  font-size: 32rpx;
}

/* 详情 */
.desc-card {
  padding: 28rpx;
}
.desc-text {
  font-size: 26rpx;
  color: #516580;
  line-height: 1.8;
  white-space: pre-wrap;
}
</style>
