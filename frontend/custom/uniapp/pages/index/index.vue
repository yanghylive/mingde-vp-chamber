<template>
  <view class="home-page">
    <!-- ===== 头部：品牌 + 铃铛 + 搜索 ===== -->
    <view class="header-glass">
      <view class="hd-row">
        <view class="hd-brand">
          <view class="hd-logo">明</view>
          <view class="hd-name">
            <text>明德恒智</text>
            <text class="hd-crown">👑</text>
          </view>
        </view>
        <view class="bell glass-control" @tap="goNotifications">
          <text class="bell-icon">🔔</text>
          <view v-if="hasUnread" class="bell-dot" />
        </view>
      </view>
      <view class="search-box glass-control" @tap="goSearch">
        <text class="s-icon">🔍</text>
        <text class="s-ph">搜索活动 / 大咖 / 商品</text>
      </view>
    </view>

    <!-- ===== 会员状态卡 ===== -->
    <view class="section px-4">
      <view class="member-card card" @tap="goMembership">
        <view class="mc-avatar">{{ (displayName || '明')[0] }}</view>
        <view class="mc-info">
          <view class="mc-name-row">
            <text class="mc-name">{{ displayName }}</text>
            <view class="mc-badge">
              <text class="mb-crown">👑</text>
              <text>L{{ tierNum }}</text>
            </view>
          </view>
        </view>
        <view class="mc-points">
          <text class="mp-num gold-text">{{ points }}</text>
          <text class="mp-label">我的积分</text>
        </view>
      </view>
    </view>

    <!-- ===== 会员等级 ladder ===== -->
    <view class="section px-4">
      <view class="sec-row">
        <text class="sec-title">会员等级</text>
        <text class="sec-link" @tap="goMembership">查看全部权益</text>
      </view>
      <view class="ladder card">
        <view class="ladder-row">
          <view
            v-for="t in ladder"
            :key="t.tier"
            :class="['ladder-step', t.tier === tierNum && 'ladder-step-current']"
            @tap="goMembership"
          >
            <text v-if="t.tier === tierNum" class="ls-now">当前</text>
            <view :class="['ls-dot', t.tier <= tierNum ? 'ls-dot-open' : 'ls-dot-locked']">
              {{ t.short }}
            </view>
            <text :class="['ls-name', t.tier === tierNum && 'ls-name-current']">{{ t.name }}</text>
          </view>
        </view>
        <view class="ladder-foot">当前 L{{ tierNum }} · 持续参与活动、贡献与学习，逐级解锁更丰富权益</view>
      </view>
    </view>

    <!-- ===== 5 宫格快捷入口 ===== -->
    <view class="section px-4">
      <view class="grids">
        <view v-for="g in grids" :key="g.label" class="grid-item card" @tap="goTo(g.to)">
          <view :class="['gi-icon', 'gi-' + (g.icon || 'default')]">
            <text>{{ gridGlyph(g.icon) }}</text>
          </view>
          <text class="gi-label">{{ g.label }}</text>
        </view>
      </view>
    </view>

    <!-- ===== 官方活动：chips + 月历 + 活动列表 ===== -->
    <view class="section px-4">
      <view class="ev-head">
        <view>
          <view class="ev-title-row">
            <text class="ev-title-icon">📅</text>
            <text class="ev-title">官方活动</text>
          </view>
          <text class="ev-sub">高质量相聚，让思想彼此照亮</text>
        </view>
        <view class="ev-more" @tap="goEvents">
          <text>全部活动</text>
          <text class="ev-arrow">></text>
        </view>
      </view>

      <!-- 方向 chips -->
      <scroll-view scroll-x class="chips" enable-flex>
        <view
          :class="['chip', 'glass-control', chip === null && 'glass-control-active']"
          @tap="chip = null; applyChip()"
        >
          全部
        </view>
        <view
          v-for="c in CHIPS"
          :key="c.key"
          :class="['chip', 'glass-control', chip === c.key && 'glass-control-active']"
          @tap="chip = chip === c.key ? null : c.key; applyChip()"
        >
          {{ c.label }}
        </view>
      </scroll-view>

      <!-- 月历 -->
      <calendar :events="events" :month-count="events.length" @select="onSelectDay" />

      <!-- 活动列表 -->
      <view class="ev-list">
        <view v-if="loading" class="empty"><skeleton type="list" :rows="3" /></view>
        <view v-else-if="displayEvents.length === 0" class="empty">{{ selectedEvents.length ? '该日暂无活动' : '暂无活动' }}</view>
        <block v-else>
          <view v-for="ev in displayEvents.slice(0, 6)" :key="ev.id" class="ev-card card" @tap="goEventDetail(ev.id)">
            <view :class="['ev-left', metaTone(ev.event_type)]">
              <text class="ev-left-icon">{{ metaGlyph(ev.event_type) }}</text>
            </view>
            <view class="ev-right">
              <view class="ev-r-head">
                <text class="ev-r-title">{{ ev.title }}</text>
                <text v-if="ev.min_tier" class="ev-tier-badge">需 L{{ ev.min_tier }} 等级</text>
              </view>
              <view class="ev-r-line">
                <text class="ev-r-icon">🕐</text>
                <text class="ev-r-text">{{ evTime(ev) }}</text>
              </view>
              <view class="ev-r-line">
                <text class="ev-r-icon">📍</text>
                <text class="ev-r-addr">{{ ev.location_name || ev.address || '地址待定' }}</text>
                <view class="ev-nav" @tap.stop="openMap(ev)">
                  <text>🧭</text>
                  <text>导航</text>
                </view>
              </view>
              <view class="ev-tags">
                <text class="tag tag-type">{{ metaLabel(ev.event_type) }}</text>
                <text v-if="evPrice(ev)" class="tag tag-price">{{ evPrice(ev) }}</text>
                <text v-if="evReward(ev)" class="tag tag-reward">{{ evReward(ev) }}</text>
              </view>
              <view class="ev-actions">
                <view class="btn-primary ev-scan" @tap.stop="goCheckin(ev)">
                  <text>📷</text>
                  <text>扫码签到</text>
                </view>
                <view class="ev-detail" @tap.stop="goEventDetail(ev.id)">
                  <text>查看详情</text>
                  <text class="ev-arrow">></text>
                </view>
              </view>
            </view>
          </view>
          <view v-if="displayEvents.length > 6" class="ev-more-btn" @tap="goEvents">查看更多活动</view>
        </block>
      </view>
    </view>

    <!-- ===== 精选活动 ===== -->
    <view v-if="featured" class="section px-4">
      <view class="ev-head">
        <view>
          <view class="ev-title-row">
            <text class="ev-title-icon">✨</text>
            <text class="ev-title">精选活动</text>
          </view>
          <text class="ev-sub">本周值得参与的高质量连接</text>
        </view>
      </view>
      <view class="featured card" @tap="goEventDetail(featured.id)">
        <view class="fd-left">
          <view class="fd-badge">限量席位</view>
          <view>
            <view class="fd-meta">{{ fdDate(featured) }} · {{ featured.location_name || featured.address }}</view>
            <view class="fd-title">{{ featured.title }}</view>
          </view>
        </view>
        <view class="fd-right">
          <text class="fd-summary">{{ featured.summary || '暂无简介' }}</text>
          <view class="fd-foot">
            <view class="fd-rec">
              <text>📅</text>
              <text>本周推荐</text>
            </view>
            <view class="fd-btn" @tap.stop="goEventDetail(featured.id)">报名</view>
          </view>
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
import Skeleton from '@/components/Skeleton.vue'

const EVENT_META = {
  personal_growth: { label: '个人成长', glyph: '🎓', tone: 'tone-growth' },
  business_industry: { label: '事业行业', glyph: '🏢', tone: 'tone-industry' },
  charity: { label: '公益慈善', glyph: '🤝', tone: 'tone-charity' }
}
const DEFAULT_META = { label: '官方活动', glyph: '📅', tone: 'tone-default' }

const CHIPS = [
  { key: 'personal_growth', label: '个人成长' },
  { key: 'business_industry', label: '事业行业' },
  { key: 'charity', label: '公益慈善' }
]

export default {
  components: { Calendar, Skeleton },
  data() {
    return {
      events: [],
      filteredEvents: [],
      displayEvents: [],
      selectedEvents: [],
      featured: null,
      points: 0,
      membership: null,
      profile: null,
      loading: true,
      chip: null,
      hasUnread: false,
      tierNum: 1,
      grids: [],
      ladder: TIERS,
      CHIPS
    }
  },
  computed: {
    currentTier() {
      return this.ladder.find((t) => t.tier === this.tierNum) || this.ladder[0]
    },
    displayName() {
      return (this.profile && (this.profile.real_name || this.profile.nickname)) || '明德会员'
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
      fetchSiteConfig().then((cfg) => {
        if (!cfg) return
        this.ladder = applyTierConfig(cfg)
        const hg = cfg.home_grids
        if (Array.isArray(hg) && hg.length > 0) {
          this.grids = hg.map((g) => Object.assign({ icon: 'default', to: '/pages/index/index' }, g))
        } else {
          this.grids = DEFAULT_GRIDS
        }
      })

      const jobs = [chamber.events()]
      if (checkLogin()) {
        jobs.push(chamber.meProfile(), chamber.meMembership(), chamber.points(), chamber.meNotifications())
      }
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') {
        this.events = results[0].value || []
        this.featured = this.events[0] || null
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
      if (this.chip === null) {
        this.filteredEvents = this.events
      } else {
        this.filteredEvents = this.events.filter((ev) => ev.event_type === this.chip)
      }
      if (!this.selectedEvents.length) this.displayEvents = this.filteredEvents
    },
    onSelectDay(list) {
      this.selectedEvents = list || []
      this.displayEvents = this.selectedEvents.length ? this.selectedEvents : this.filteredEvents
    },
    // ---- H5 活动卡辅助 ----
    meta(ev) {
      return EVENT_META[ev.event_type] || DEFAULT_META
    },
    metaLabel(t) {
      return (EVENT_META[t] || DEFAULT_META).label
    },
    metaGlyph(t) {
      return (EVENT_META[t] || DEFAULT_META).glyph
    },
    metaTone(t) {
      return (EVENT_META[t] || DEFAULT_META).tone
    },
    evTime(ev) {
      const s = toDate(ev.start_time, 'datetime')
      const e = toDate(ev.end_time, 'datetime')
      if (!s) return '时间待定'
      return e ? s + ' — ' + e.slice(11) : s
    },
    evPrice(ev) {
      const t = ev.tickets && ev.tickets[0]
      if (!t) return ''
      const p = Number(t.price || 0)
      return p > 0 ? (Number.isInteger(p) ? '¥' + p : '¥' + p.toFixed(2)) : ''
    },
    evReward(ev) {
      if (ev.checkin_reward_points) return '签到 +' + ev.checkin_reward_points + ' 积分'
      if (ev.checkin_reward_contribution) return '签到 +' + ev.checkin_reward_contribution + ' 贡献'
      return ''
    },
    fdDate(ev) {
      return toDate(ev.start_time)
    },
    gridGlyph(icon) {
      const map = { event: '📅', expert: '✨', mall: '🛍', ai: '🤖', graduate: '🎓', default: '⭐' }
      return map[icon] || map.default
    },
    // ---- 导航 ----
    openMap(ev) {
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
    // ---- 跳转 ----
    goTo(path) {
      if (path && path.startsWith('/pages/')) uni.navigateTo({ url: path })
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
      uni.navigateTo({ url: '/pages/events/detail/index?id=' + id })
    },
    goCheckin(ev) {
      uni.navigateTo({ url: '/pages/events/checkin/index?event=' + ev.id })
    }
  }
}

const DEFAULT_GRIDS = [
  { label: '官方活动', icon: 'event', to: '/pages/events/index' },
  { label: '会员中心', icon: 'default', to: '/pages/membership/index' },
  { label: '积分商城', icon: 'mall', to: '/pages/mall/index' },
  { label: '大咖主页', icon: 'expert', to: '/pages/experts/index' },
  { label: 'AI生态', icon: 'ai', to: '/pages/ai-ecosystem/index' }
]
</script>

<style lang="scss">
.home-page {
  min-height: 100vh;
  padding-bottom: 40rpx;
}
.section {
  margin-top: 24rpx;
}

/* ===== 头部 ===== */
.header-glass {
  position: sticky;
  top: 0;
  z-index: 20;
  padding: 20rpx 32rpx 16rpx;
  background: rgba(238, 243, 249, 0.72);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  backdrop-filter: blur(20px) saturate(160%);
}
.hd-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.hd-brand {
  display: flex;
  align-items: center;
  gap: 12rpx;
}
.hd-logo {
  width: 64rpx;
  height: 64rpx;
  border-radius: 20rpx;
  background: linear-gradient(135deg, #e3a24e, #b87325);
  color: #fff;
  font-size: 30rpx;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 8rpx 20rpx rgba(185, 110, 29, 0.26);
}
.hd-name {
  display: flex;
  align-items: center;
  gap: 6rpx;
  font-size: 30rpx;
  font-weight: 800;
  letter-spacing: 2rpx;
  color: #10284d;
}
.hd-crown {
  font-size: 24rpx;
}
.bell {
  position: relative;
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}
.bell-icon {
  font-size: 30rpx;
}
.bell-dot {
  position: absolute;
  right: 12rpx;
  top: 12rpx;
  width: 12rpx;
  height: 12rpx;
  border-radius: 50%;
  background: #e05d3f;
  border: 4rpx solid #fff;
}
.search-box {
  display: flex;
  align-items: center;
  gap: 12rpx;
  border-radius: 24rpx;
  padding: 20rpx 28rpx;
  margin-top: 16rpx;
}
.s-icon {
  color: #b87325;
  font-size: 30rpx;
}
.s-ph {
  color: #7f8b9c;
  font-size: 26rpx;
}

/* ===== 会员状态卡 ===== */
.member-card {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 24rpx 28rpx;
}
.mc-avatar {
  width: 88rpx;
  height: 88rpx;
  border-radius: 50%;
  border: 4rpx solid #f2bd6b;
  background: linear-gradient(135deg, #d99b49, #81531f);
  color: #fff;
  font-size: 30rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.mc-info {
  flex: 1;
  min-width: 0;
}
.mc-name-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
}
.mc-name {
  max-width: 240rpx;
  font-size: 28rpx;
  font-weight: 700;
  color: #203755;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mc-badge {
  display: flex;
  align-items: center;
  gap: 4rpx;
  background: #f0b35b;
  padding: 4rpx 12rpx;
  border-radius: 8rpx;
  font-size: 20rpx;
  font-weight: 700;
  color: #173253;
}
.mb-crown {
  font-size: 18rpx;
}
.mc-points {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}
.mp-num {
  font-size: 36rpx;
  font-weight: 800;
  line-height: 1;
}
.mp-label {
  font-size: 18rpx;
  color: #8a94a3;
  margin-top: 4rpx;
}

/* ===== 会员等级 ===== */
.sec-row {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin-bottom: 12rpx;
}
.sec-title {
  font-size: 26rpx;
  font-weight: 700;
  color: #17325b;
}
.sec-link {
  font-size: 22rpx;
  color: #ad6b22;
}
.ladder {
  border-radius: 36rpx;
  padding: 24rpx;
}
.ladder-row {
  display: flex;
  justify-content: space-between;
}
.ladder-step {
  position: relative;
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8rpx;
  padding: 16rpx 8rpx;
  border-radius: 24rpx;
}
.ladder-step-current {
  background: #fff6e8;
}
.ls-now {
  position: absolute;
  top: -8rpx;
  right: 8rpx;
  background: #e05d3f;
  color: #fff;
  font-size: 16rpx;
  font-weight: 700;
  padding: 4rpx 10rpx;
  border-radius: 999rpx;
}
.ls-dot {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  font-size: 20rpx;
  font-weight: 800;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ls-dot-open {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
}
.ls-dot-locked {
  background: linear-gradient(135deg, #e3e7ec, #c8cfd8);
}
.ls-name {
  font-size: 20rpx;
  font-weight: 600;
  color: #5c6b80;
}
.ls-name-current {
  color: #ad6b22;
}
.ladder-foot {
  margin-top: 12rpx;
  padding-top: 12rpx;
  border-top: 1rpx solid #eef1f5;
  text-align: center;
  font-size: 20rpx;
  color: #8d97a6;
}

/* ===== 5 宫格 ===== */
.grids {
  display: flex;
  gap: 16rpx;
}
.grid-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12rpx;
  padding: 24rpx 8rpx;
  border-radius: 32rpx;
}
.gi-icon {
  width: 72rpx;
  height: 72rpx;
  border-radius: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32rpx;
}
.gi-event {
  background: #fff0dc;
}
.gi-mall {
  background: #fff0dc;
}
.gi-expert {
  background: #e9f0f9;
}
.gi-ai {
  background: #eef0f3;
}
.gi-default {
  background: #e9f0f9;
}
.gi-label {
  font-size: 22rpx;
  font-weight: 700;
  color: #263a59;
}

/* ===== 官方活动 ===== */
.ev-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin: 36rpx 0 24rpx;
}
.ev-title-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
}
.ev-title-icon {
  font-size: 32rpx;
}
.ev-title {
  font-size: 34rpx;
  font-weight: 700;
  color: #17325b;
}
.ev-sub {
  display: block;
  font-size: 22rpx;
  color: #8994a6;
  margin-top: 8rpx;
}
.ev-more {
  display: flex;
  align-items: center;
  font-size: 22rpx;
  font-weight: 500;
  color: #ad6b22;
}
.ev-arrow {
  font-size: 28rpx;
  margin-left: 4rpx;
}
.chips {
  white-space: nowrap;
  margin: 0 -32rpx;
  padding: 0 32rpx 8rpx;
}
.chip {
  display: inline-block;
  padding: 16rpx 32rpx;
  border-radius: 999rpx;
  font-size: 24rpx;
  font-weight: 600;
  color: #617087;
  margin-right: 16rpx;
}

/* ===== 活动卡 ===== */
.ev-list {
  margin-top: 24rpx;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}
.ev-card {
  display: flex;
  overflow: hidden;
}
.ev-left {
  width: 128rpx;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
}
.ev-left-icon {
  font-size: 56rpx;
}
.tone-growth {
  background: linear-gradient(135deg, #1a4778, #102b50);
}
.tone-industry {
  background: linear-gradient(135deg, #b77a34, #82531f);
}
.tone-charity {
  background: linear-gradient(135deg, #477467, #294d43);
}
.tone-default {
  background: linear-gradient(135deg, #35557e, #20364f);
}
.ev-right {
  flex: 1;
  min-width: 0;
  padding: 28rpx;
}
.ev-r-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16rpx;
}
.ev-r-title {
  flex: 1;
  min-width: 0;
  font-size: 30rpx;
  font-weight: 700;
  color: #203755;
  line-height: 1.4;
}
.ev-tier-badge {
  flex-shrink: 0;
  background: #fff2df;
  color: #a8691f;
  font-size: 20rpx;
  padding: 4rpx 12rpx;
  border-radius: 8rpx;
}
.ev-r-line {
  display: flex;
  align-items: center;
  gap: 8rpx;
  margin-top: 12rpx;
  font-size: 22rpx;
  color: #778397;
}
.ev-r-icon {
  font-size: 24rpx;
  flex-shrink: 0;
}
.ev-r-addr {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ev-nav {
  display: flex;
  align-items: center;
  gap: 4rpx;
  background: #eaf0f8;
  color: #24507f;
  font-size: 20rpx;
  font-weight: 600;
  padding: 8rpx 16rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
}
.ev-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 16rpx;
}
.tag {
  font-size: 20rpx;
  padding: 6rpx 14rpx;
  border-radius: 8rpx;
}
.tag-type {
  background: #eef0f3;
  color: #6b7889;
}
.tag-price {
  background: #fff0dc;
  color: #a8691f;
}
.tag-reward {
  background: #e9f0fb;
  color: #28517f;
}
.ev-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 24rpx;
}
.ev-scan {
  display: flex;
  align-items: center;
  gap: 8rpx;
  padding: 16rpx 24rpx;
  font-size: 24rpx;
  border-radius: 16rpx;
}
.ev-detail {
  display: flex;
  align-items: center;
  font-size: 22rpx;
  font-weight: 600;
  color: #ad6b22;
}
.ev-more-btn {
  text-align: center;
  padding: 24rpx 0;
  font-size: 24rpx;
  font-weight: 600;
  color: #a9651e;
}

/* ===== 精选活动 ===== */
.featured {
  display: flex;
  overflow: hidden;
}
.fd-left {
  width: 38%;
  background: linear-gradient(145deg, #183d6d, #0d2549);
  color: #fff;
  padding: 32rpx 24rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  box-sizing: border-box;
}
.fd-badge {
  width: fit-content;
  background: #eaa14b;
  color: #fff;
  font-size: 20rpx;
  font-weight: 600;
  padding: 6rpx 16rpx;
  border-radius: 8rpx;
}
.fd-meta {
  font-size: 20rpx;
  color: rgba(255, 255, 255, 0.65);
}
.fd-title {
  font-size: 30rpx;
  font-weight: 600;
  line-height: 1.4;
  margin-top: 8rpx;
}
.fd-right {
  flex: 1;
  padding: 32rpx;
  display: flex;
  flex-direction: column;
}
.fd-summary {
  font-size: 24rpx;
  color: #66748a;
  line-height: 1.6;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  overflow: hidden;
}
.fd-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
  padding-top: 24rpx;
}
.fd-rec {
  display: flex;
  align-items: center;
  gap: 6rpx;
  font-size: 22rpx;
  color: #c37722;
}
.fd-btn {
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  font-size: 24rpx;
  font-weight: 600;
  padding: 14rpx 36rpx;
  border-radius: 16rpx;
  box-shadow: 0 10rpx 24rpx rgba(185, 110, 29, 0.2);
}
.footer {
  text-align: center;
  color: #c0c6d0;
  font-size: 22rpx;
  padding: 60rpx 0 20rpx;
}
.empty {
  text-align: center;
  padding: 40rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
</style>
