<template>
  <view class="home-page">
    <!-- 搜索 + 通知 -->
    <view class="top-bar">
      <view class="search-box" @tap="goSearch">
        <text class="search-icon">⌕</text>
        <text class="search-ph">搜索活动 / 大咖</text>
      </view>
      <view class="bell" @tap="goNotifications">
        <text class="bell-icon">🔔</text>
        <view v-if="hasUnread" class="dot" />
      </view>
    </view>

    <!-- 会员卡 -->
    <view class="member-card card" @tap="goMembership">
      <view class="mc-left">
        <view class="mc-tier">
          <text class="tier-badge">{{ currentTier.short }}</text>
          <text class="tier-name">{{ currentTier.name }}</text>
        </view>
        <view class="mc-points">
          <text class="points-num">{{ points }}</text>
          <text class="points-label">积分</text>
        </view>
      </view>
      <view class="mc-right" @tap.stop="goMembership">
        <text class="mc-btn">开通会员 ›</text>
      </view>
    </view>

    <!-- 5 宫格 -->
    <view class="grids card">
      <view v-for="g in grids" :key="g.label" class="grid-item" @tap="goTo(g.to)">
        <view :class="['grid-icon', 'grid-icon-' + (g.icon || 'default')]">
          <text>{{ gridGlyph(g.icon) }}</text>
        </view>
        <text class="grid-label">{{ g.label }}</text>
      </view>
    </view>

    <!-- 方向 chip -->
    <scroll-view scroll-x class="chips">
      <view
        v-for="(d, i) in DIRECTIONS"
        :key="d"
        :class="['chip', chip === i && 'chip-active']"
        @tap="chip = chip === i ? null : i"
      >
        {{ d }}
      </view>
    </scroll-view>

    <!-- 月历 -->
    <view class="section-head">
      <text class="section-title">活动月历</text>
    </view>
    <calendar :events="events" @select="onSelectDay" />

    <!-- 活动列表 -->
    <view class="section-head">
      <text class="section-title">{{ selectedEvents.length ? '当日活动' : '近期活动' }}</text>
      <text v-if="!selectedEvents.length" class="section-more" @tap="goEvents">全部 ›</text>
    </view>
    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="displayEvents.length === 0" class="empty">{{ selectedEvents.length ? '该日暂无活动' : '暂无活动' }}</view>
    <view v-else class="event-list">
      <view
        v-for="ev in displayEvents"
        :key="ev.id"
        class="event-item card"
        @tap="goEventDetail(ev.id)"
      >
        <view class="event-date">
          <text class="ed-day">{{ eventDay(ev) }}</text>
          <text class="ed-month">{{ eventMonth(ev) }}</text>
        </view>
        <view class="event-info">
          <text class="event-title">{{ ev.title }}</text>
          <text class="event-meta">{{ eventTime(ev) }} · {{ ev.location || '明德' }}</text>
        </view>
        <view v-if="ev.checkin_reward_points" class="reward-tag">+{{ ev.checkin_reward_points }}积分</view>
      </view>
    </view>

    <!-- 等级阶梯 -->
    <view class="ladder card" @tap="goMembership">
      <text class="section-title">会员等级</text>
      <view class="ladder-row">
        <view v-for="t in ladder" :key="t.tier" class="ladder-step" :class="{ 'ladder-active': t.tier === tierNum }">
          <text class="ls-short">{{ t.short }}</text>
          <text class="ls-name">{{ t.name }}</text>
        </view>
      </view>
    </view>

    <view class="footer">明德恒智 · PBC 企业家事业共同体</view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { fetchSiteConfig } from '@/common/site-config'
import { TIERS, tierToNumber, applyTierConfig } from '@/common/tier'
import { toDate } from '@/common/format'
import Calendar from '@/components/Calendar.vue'

const GRIDS = [
  { label: '官方活动', icon: 'event', to: '/pages/events/index' },
  { label: '大咖预约', icon: 'expert', to: '/pages/experts/index' },
  { label: '积分商城', icon: 'mall', to: '/pages/mall/index' },
  { label: 'AI 生态', icon: 'ai', to: '/pages/ai-ecosystem/index' },
  { label: '毕业认证', icon: 'graduate', to: '/pages/mine/graduate-verification' }
]

const DIRECTIONS = ['全部', '个人成长', '事业行业', '公益慈善']

export default {
  components: { Calendar },
  data() {
    return {
      events: [],
      filteredEvents: [],
      displayEvents: [],
      selectedEvents: [],
      points: 0,
      membership: null,
      profile: null,
      loading: true,
      chip: 0,
      hasUnread: false,
      tierNum: 1,
      grids: GRIDS,
      ladder: TIERS
    }
  },
  computed: {
    currentTier() {
      return this.ladder.find((t) => t.tier === this.tierNum) || this.ladder[0]
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
      // 站点配置（grids + ladder）
      fetchSiteConfig().then((cfg) => {
        if (cfg) {
          const hg = cfg.home_grids
          if (Array.isArray(hg) && hg.length > 0) {
            this.grids = hg.map((g) => Object.assign({ icon: 'default', to: '/pages/index/index' }, g))
          }
          this.ladder = applyTierConfig(cfg)
          this.tierNum = tierToNumber(this.membership && this.membership.effective_tier, 1)
        }
      })

      const jobs = [chamber.events()]
      if (checkLogin()) {
        jobs.push(chamber.meProfile(), chamber.meMembership(), chamber.points(), chamber.meNotifications())
      }
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') {
        this.events = results[0].value || []
        this.applyChip()
      }
      if (results.length > 1 && results[1].status === 'fulfilled') this.profile = results[1].value
      if (results.length > 2 && results[2].status === 'fulfilled') {
        this.membership = results[2].value
        this.tierNum = tierToNumber(this.membership && this.membership.effective_tier, 1)
      }
      if (results.length > 3 && results[3].status === 'fulfilled') this.points = results[3].value
      if (results.length > 4 && results[4].status === 'fulfilled') {
        const list = results[4].value || []
        this.hasUnread = list.some((n) => !n.is_read)
      }
      this.loading = false
    },
    applyChip() {
      if (this.chip === null || this.chip === 0) {
        this.filteredEvents = this.events
      } else {
        const dir = DIRECTIONS[this.chip]
        this.filteredEvents = this.events.filter((ev) => (ev.direction || ev.event_type || '').indexOf(dir) >= 0)
      }
      // 未选中日期时，列表展示当前过滤结果
      if (!this.selectedEvents.length) {
        this.displayEvents = this.filteredEvents
      }
    },
    onSelectDay(list) {
      this.selectedEvents = list || []
      this.displayEvents = this.selectedEvents.length ? this.selectedEvents : this.filteredEvents
    },
    eventDay(ev) {
      return toDate(ev.start_time).slice(8, 10)
    },
    eventMonth(ev) {
      return toDate(ev.start_time).slice(5, 7) + '月'
    },
    eventTime(ev) {
      return toDate(ev.start_time, 'datetime')
    },
    gridGlyph(icon) {
      const map = { event: '🎯', expert: '👤', mall: '🛍', ai: '🤖', graduate: '🎓', default: '✦' }
      return map[icon] || map.default
    },
    goTo(path) {
      if (path && path.startsWith('/pages/')) {
        uni.navigateTo({ url: path })
      }
    },
    goSearch() {
      uni.navigateTo({ url: '/pages/search/index' })
    },
    goNotifications() {
      uni.navigateTo({ url: '/pages/mine/notifications' })
    },
    goMembership() {
      if (!checkLogin()) {
        uni.navigateTo({ url: '/pages/login/index' })
        return
      }
      uni.navigateTo({ url: '/pages/membership/index' })
    },
    goEvents() {
      uni.switchTab({ url: '/pages/events/index' })
    },
    goEventDetail(id) {
      uni.navigateTo({ url: '/pages/events/detail?id=' + id })
    }
  }
}
</script>

<style lang="scss">
.home-page {
  padding: 24rpx 32rpx 60rpx;
}
.top-bar {
  display: flex;
  align-items: center;
  gap: 20rpx;
  margin-bottom: 24rpx;
}
.search-box {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 12rpx;
  background: #fff;
  border-radius: 999rpx;
  padding: 20rpx 28rpx;
  box-shadow: 0 4rpx 16rpx rgba(39, 59, 89, 0.04);
}
.search-icon {
  color: #b8751d;
  font-size: 32rpx;
}
.search-ph {
  color: #c0c6d0;
  font-size: 26rpx;
}
.bell {
  position: relative;
  width: 80rpx;
  height: 80rpx;
  background: #fff;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4rpx 16rpx rgba(39, 59, 89, 0.04);
}
.bell-icon {
  font-size: 32rpx;
}
.dot {
  position: absolute;
  top: 18rpx;
  right: 20rpx;
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
  background: #e5484d;
}
.member-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 36rpx;
  background: linear-gradient(135deg, #2c3e50, #273b59);
  box-shadow: 0 12rpx 32rpx rgba(39, 59, 89, 0.25);
}
.mc-left {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.mc-tier {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
.tier-badge {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 22rpx;
  font-weight: 700;
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
}
.tier-name {
  color: #fff;
  font-size: 30rpx;
  font-weight: 600;
}
.mc-points {
  display: flex;
  align-items: baseline;
  gap: 8rpx;
}
.points-num {
  color: #ffd78f;
  font-size: 48rpx;
  font-weight: 800;
}
.points-label {
  color: rgba(255, 255, 255, 0.6);
  font-size: 24rpx;
}
.mc-btn {
  color: #ffd78f;
  font-size: 26rpx;
  font-weight: 600;
}
.grids {
  display: flex;
  justify-content: space-between;
  padding: 32rpx 24rpx;
  margin-top: 24rpx;
}
.grid-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12rpx;
  flex: 1;
}
.grid-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 24rpx;
  background: #fff0dc;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40rpx;
}
.grid-label {
  font-size: 24rpx;
  color: #516580;
}
.chips {
  margin: 24rpx 0;
  white-space: nowrap;
}
.chip {
  display: inline-block;
  padding: 14rpx 32rpx;
  border-radius: 999rpx;
  background: #fff;
  color: #516580;
  font-size: 26rpx;
  margin-right: 16rpx;
  box-shadow: 0 4rpx 12rpx rgba(39, 59, 89, 0.04);
}
.chip-active {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-weight: 600;
}
.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 32rpx 0 20rpx;
}
.section-title {
  font-size: 34rpx;
  font-weight: 700;
  color: #273b59;
}
.section-more {
  font-size: 24rpx;
  color: #ad6b22;
}
.empty {
  text-align: center;
  padding: 80rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.event-list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.event-item {
  display: flex;
  align-items: center;
  padding: 24rpx;
  gap: 20rpx;
}
.event-date {
  width: 96rpx;
  height: 96rpx;
  border-radius: 20rpx;
  background: linear-gradient(135deg, #fff0dc, #f6e2c2);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ed-day {
  font-size: 36rpx;
  font-weight: 800;
  color: #b8751d;
}
.ed-month {
  font-size: 20rpx;
  color: #ad6b22;
}
.event-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.event-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.event-meta {
  font-size: 22rpx;
  color: #8a94a3;
}
.reward-tag {
  background: #f6ead6;
  color: #b8751d;
  font-size: 20rpx;
  padding: 6rpx 12rpx;
  border-radius: 8rpx;
  flex-shrink: 0;
}
.ladder {
  margin-top: 32rpx;
  padding: 32rpx;
}
.ladder-row {
  display: flex;
  justify-content: space-between;
  margin-top: 24rpx;
}
.ladder-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8rpx;
  opacity: 0.5;
}
.ladder-active {
  opacity: 1;
}
.ls-short {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 22rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ls-name {
  font-size: 20rpx;
  color: #516580;
}
.footer {
  text-align: center;
  color: #c0c6d0;
  font-size: 22rpx;
  padding: 60rpx 0 20rpx;
}
</style>
