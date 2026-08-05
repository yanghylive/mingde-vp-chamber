<template>
  <view class="events-page">
    <!-- 页头（对齐 H5 PageHeader：眉题 + 大标题 + 铃铛） -->
    <view class="ph">
      <view class="ph-row">
        <view>
          <view class="ph-eyebrow"><view class="ic ic-xs ic-crown-gold" />明德恒智AI企商汇</view>
          <text class="ph-title">官方活动</text>
          <text class="ph-sub">高质量相聚，让思想彼此照亮</text>
        </view>
        <view class="ph-bell glass-control" @tap="goNotifications"><view class="ic ic-sm ic-bell-dark" /></view>
      </view>
    </view>

    <!-- Banner 大卡（首个活动） -->
    <view v-if="banner" class="banner glass-dark" @tap="goDetail(banner.id)">
      <view class="bn-top">
        <view>
          <view class="bn-badge">{{ bannerType(banner) }}</view>
          <view class="bn-title">{{ banner.title }}</view>
          <view class="bn-summary">{{ banner.summary }}</view>
        </view>
      </view>
      <view class="bn-foot">
        <view class="bn-meta">
          <view class="bn-meta-row"><view class="ic ic-xs ic-calendar-days-white" /><text>{{ bannerDate(banner) }}</text></view>
          <view class="bn-meta-row"><view class="ic ic-xs ic-map-pin-white" /><text>{{ banner.location_name || banner.address }}</text></view>
        </view>
        <view class="{{'bn-btn' + (joined.includes(banner.id) ? ' bn-btn-joined' : '')}}" @tap.stop="toggle(banner.id)">
          {{ joined.includes(banner.id) ? '已报名' : '预约席位' }}
        </view>
      </view>
    </view>

    <!-- 精选活动 -->
    <view class="section">
      <view class="sec-head">
        <view class="sec-icon"><view class="ic ic-md ic-calendar-days-white" /></view>
        <view>
          <text class="sec-title">精选活动</text>
          <text class="sec-sub">官方策划，严选参与者</text>
        </view>
      </view>

      <!-- 类型 chips（动态生成，对齐 H5） -->
      <scroll-view scroll-x enable-flex class="chips">
        <view class="chips-inner">

        <view
          v-for="f in filters"
          :key="f"
          class="{{'chip glass-control' + (filter === f ? ' glass-control-active' : '')}}"
          @tap="filter = filter === f ? '推荐' : f"
        >
          {{ f }}
        </view>
        </view>
      </scroll-view>

      <!-- 活动列表 -->
      <view v-if="loading" class="empty"><skeleton type="list" :rows="3" /></view>
      <view v-else-if="loadError" class="empty" style="color: #d05b3f">{{ loadError }}</view>
      <view v-else-if="visible.length === 0" class="empty">暂无活动</view>
      <view v-else class="list">
        <view v-for="ev in visible" :key="ev.id" class="ev-card card" @tap="goDetail(ev.id)">
          <view class="ev-left">
            <text class="ev-month">{{ evMonth(ev) }}</text>
            <text class="ev-day">{{ evDay(ev) }}</text>
            <text class="ev-type">{{ evType(ev) }}</text>
          </view>
          <view class="ev-right">
            <text class="ev-title">{{ ev.title }}</text>
            <view class="ev-meta">
              <view class="ev-meta-row"><view class="ic ic-xs ic-clock-3-orange" /><text>{{ evTime(ev) }}</text></view>
              <view class="ev-meta-row"><view class="ic ic-xs ic-map-pin-orange" /><text>{{ ev.location_name || ev.address || '地点待定' }}</text></view>
            </view>
            <view class="ev-foot">
              <text class="ev-seats">{{ remaining(ev) }} 席可约</text>
              <view class="{{'ev-btn' + (joined.includes(ev.id) ? ' ev-btn-joined' : '')}}" @tap.stop="toggle(ev.id)">
                {{ joined.includes(ev.id) ? '已报名' : '立即报名' }}
              </view>
            </view>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { toDate, fmtZhMonthDay } from '@/common/format'
import Skeleton from '@/components/Skeleton.vue'

const TYPE_LABEL = {
  workshop: '工作坊',
  summit: '峰会',
  salon: '私享会',
  closed_meeting: '闭门会',
  training: '研学',
  forum: '论坛',
  charity: '公益',
  default: '活动'
}

export default {
  components: { Skeleton },
  data() {
    return {
      events: [],
      loading: true,
      loadError: '',
      filter: '推荐',
      joined: []
    }
  },
  computed: {
    banner() {
      return this.events.length ? this.events[0] : null
    },
    // 动态 chips：推荐 + 从活动数据提取类型（对齐 H5）
    filters() {
      const set = {}
      this.events.forEach((e) => {
        const label = TYPE_LABEL[e.event_type] || TYPE_LABEL.default
        set[label] = true
      })
      return ['推荐'].concat(Object.keys(set))
    },
    visible() {
      if (this.filter === '推荐') return this.events
      return this.events.filter((ev) => (TYPE_LABEL[ev.event_type] || TYPE_LABEL.default) === this.filter)
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
      try {
        this.events = await chamber.events()
        // 已报名状态
        const regs = await chamber.myEventRegistrations().catch(() => [])
        if (Array.isArray(regs)) {
          this.joined = regs.filter((r) => r.status !== 'cancelled').map((r) => r.event_id || r.id)
        }
      } catch (e) {
        this.loadError = (e && e.message) || '加载失败，请重试'
      }
      this.loading = false
    },
    bannerType(ev) {
      return TYPE_LABEL[ev.event_type] || '年度盛会'
    },
    bannerDate(ev) {
      return fmtZhMonthDay(ev.start_time)
    },
    evType(ev) {
      return TYPE_LABEL[ev.event_type] || ev.event_type || '活动'
    },
    evMonth(ev) {
      return toDate(ev.start_time).slice(5, 7) + '月'
    },
    evDay(ev) {
      return toDate(ev.start_time).slice(8, 10)
    },
    evTime(ev) {
      return fmtZhMonthDay(ev.start_time)
    },
    remaining(ev) {
      const t = ev.tickets && ev.tickets[0]
      return t && t.remaining !== undefined ? t.remaining : (ev.remaining || 0)
    },
    toggle(id) {
      if (!checkLogin()) {
        uni.navigateTo({ url: '/pages/login/index' })
        return
      }
      if (this.joined.includes(id)) return
      // 报名
      chamber
        .registerEvent(id)
        .then(() => {
          this.joined.push(id)
          uni.showToast({ title: '报名成功', icon: 'success' })
        })
        .catch(() => {})
    },
    goDetail(id) {
      uni.navigateTo({ url: '/pages/events/detail/index?id=' + id })
    },
    goNotifications() {
      uni.navigateTo({ url: '/pages/mine/notifications/index' })
    }
  }
}
</script>

<style lang="scss">
.events-page {
  padding: 24rpx 32rpx 60rpx;
  min-height: 100vh;
}
.ph {
  padding-top: env(safe-area-inset-top);
  padding: 8rpx 0 24rpx;
}
.ph-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}
.ph-eyebrow {
  font-size: 18rpx;
  font-weight: 700;
  letter-spacing: 4rpx;
  color: #a9671f;
  margin-bottom: 8rpx;
}
.ph-title {
  display: block;
  font-size: 44rpx;
  font-weight: 800;
  color: #17233d;
}
.ph-sub {
  display: block;
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 8rpx;
}
.ph-bell {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  font-size: 30rpx;
  font-weight: 700;
  color: #16335d;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 8rpx;
}

/* Banner 深蓝大卡 */
.banner {
  border-radius: 44rpx;
  padding: 40rpx 36rpx;
  color: #fff;
}
.bn-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}
.bn-badge {
  display: inline-block;
  background: #e49a41;
  color: #fff;
  font-size: 20rpx;
  font-weight: 600;
  padding: 6rpx 16rpx;
  border-radius: 8rpx;
}
.bn-title {
  font-size: 36rpx;
  font-weight: 700;
  margin-top: 20rpx;
}
.bn-summary {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.7);
  margin-top: 16rpx;
}
.bn-deco {
  font-size: 72rpx;
  color: rgba(243, 189, 112, 0.45);
  flex-shrink: 0;
}
.bn-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 36rpx;
  padding-top: 28rpx;
  border-top: 1rpx solid rgba(255, 255, 255, 0.1);
}
.bn-meta {
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.bn-meta-row {
  display: flex;
  align-items: center;
  gap: 10rpx;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.8);
}
.bn-btn {
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  font-size: 24rpx;
  font-weight: 600;
  padding: 16rpx 36rpx;
  border-radius: 16rpx;
  box-shadow: 0 10rpx 24rpx rgba(185, 110, 29, 0.2);
}
.bn-btn-joined {
  background: rgba(255, 255, 255, 0.15);
  box-shadow: none;
}

/* 精选活动 */
.section {
  margin-top: 40rpx;
}
.sec-head {
  display: flex;
  align-items: center;
  gap: 16rpx;
  margin-bottom: 20rpx;
}
.sec-icon {
  width: 56rpx;
  height: 56rpx;
  border-radius: 16rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 26rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sec-title {
  display: block;
  font-size: 34rpx;
  font-weight: 700;
  color: #17325b;
}
.sec-sub {
  display: block;
  font-size: 20rpx;
  color: #8994a6;
  margin-top: 4rpx;
}
.chips {
  margin: 0 -32rpx;
  padding-bottom: 8rpx;
}
.chips-inner {
  display: flex;
  gap: 16rpx;
  padding: 0 32rpx;
}
.chip {
  display: inline-block;
  padding: 16rpx 32rpx;
  border-radius: 999rpx;
  font-size: 24rpx;
  font-weight: 600;
  color: #617087;
}
.list {
  margin-top: 24rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}
.empty {
  text-align: center;
  padding: 60rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}

/* 活动卡：左侧日期块 + 右侧详情 */
.ev-card {
  display: flex;
  overflow: hidden;
  min-height: 240rpx;
}
.ev-left {
  width: 32%;
  background: #173c69;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #fff;
  flex-shrink: 0;
}
.ev-month {
  font-size: 20rpx;
  opacity: 0.7;
}
.ev-day {
  font-size: 56rpx;
  font-weight: 300;
  margin-top: 8rpx;
}
.ev-type {
  margin-top: 16rpx;
  background: rgba(255, 255, 255, 0.15);
  font-size: 18rpx;
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
}
.ev-right {
  flex: 1;
  min-width: 0;
  padding: 28rpx;
  display: flex;
  flex-direction: column;
}
.ev-title {
  font-size: 30rpx;
  font-weight: 700;
  color: #203755;
}
.ev-meta {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  margin-top: 20rpx;
}
.ev-meta-row {
  display: flex;
  align-items: center;
  gap: 10rpx;
  font-size: 22rpx;
  color: #778397;
}
.ev-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
  padding-top: 20rpx;
}
.ev-seats {
  font-size: 20rpx;
  color: #a06a2d;
}
.ev-btn {
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  font-size: 24rpx;
  font-weight: 600;
  padding: 14rpx 32rpx;
  border-radius: 16rpx;
  box-shadow: 0 10rpx 24rpx rgba(185, 110, 29, 0.2);
}
.ev-btn-joined {
  background: rgba(255, 255, 255, 0.58);
  color: #15305b;
  box-shadow: none;
  border: 1rpx solid rgba(185, 201, 218, 0.4);
}
</style>
