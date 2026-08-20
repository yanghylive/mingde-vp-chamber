<template>
  <view class="home-page">
    <!-- ===== 头部：品牌 + 铃铛 + 搜索 ===== -->
    <view class="header-glass">
      <view class="hd-row">
        <view class="hd-brand">
          <view class="hd-logo">明</view>
          <view class="hd-name">
            <text>明德恒智AI企商汇</text>
            <image class="ic ic-xs" src="/static/icons/ic-crown-gold.png" mode="aspectFit" />
          </view>
        </view>
        <view class="bell glass-control" @tap="goNotifications">
          <image class="ic ic-sm" src="/static/icons/ic-bell-dark.png" mode="aspectFit" />
          <view v-if="hasUnread" class="bell-dot" />
        </view>
      </view>
      <view class="search-box glass-control">
        <image class="ic ic-sm" src="/static/icons/ic-search-gold.png" mode="aspectFit" />
        <input
          v-model="searchKw"
          class="s-input"
          placeholder="搜索活动 / 大咖 / 商品"
          placeholder-class="ph"
          confirm-type="search"
          @confirm="goSearchKw"
        />
      </view>
    </view>

    <!-- ===== 会员状态卡 ===== -->
    <view class="section px-4">
      <view class="member-card card" @tap="goMembership">
        <view class="mc-avatar">{{ avatarText }}</view>
        <view class="mc-info">
          <view class="mc-name-row">
            <text class="mc-name">{{ displayName }}</text>
            <view class="mc-badge">
              <image class="ic ic-xs" src="/static/icons/ic-crown-gold.png" mode="aspectFit" />
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

    <!-- ===== 新手任务卡（激活引导） ===== -->
    <view v-if="tasks.length > 0" class="section px-4">
      <view class="task-card card">
        <view class="task-head">
          <text class="task-title">新人大礼包 · 完成 3 步激活</text>
          <text class="task-progress">{{ taskDone }}/{{ tasks.length }}</text>
        </view>
        <view class="task-progress-bar">
          <view class="tpb-fill" :style="'width:' + taskPct + '%'" />
        </view>
        <view v-for="t in tasks" :key="t.key" class="task-item" @tap="goTask(t)">
          <view class="{{'task-dot' + (t.done ? ' task-dot-done' : '')}}">
            <text v-if="t.done" class="task-check">✓</text>
          </view>
          <view class="task-info">
            <text class="task-name">{{ t.name }}</text>
            <text class="task-desc">{{ t.desc }}</text>
          </view>
          <view class="task-go">
            <text class="{{'task-btn' + (t.done ? ' task-btn-done' : '')}}">{{ t.done ? '已完成' : '去完成' }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- ===== 小薇 · 今日 3 问（首屏第一动作） ===== -->
    <view class="section px-4">
      <view v-if="!AI_DISABLED" class="xw-card card" @tap="goCoaching">
        <view class="xw-head">
          <view class="xw-avatar">
            <image class="ic ic-sm" src="/static/icons/ic-sparkles-gold.png" mode="aspectFit" />
          </view>
          <view class="xw-title-wrap">
            <text class="xw-title">{{ xwBrand }} · 今日认知刷新</text>
            <text class="xw-sub">{{ xwMorning ? '3 条灵魂追问已就绪' : '每天 3 问，打破旧习惯' }}</text>
          </view>
          <view class="xw-arrow">
            <image class="ic ic-xs" src="/static/icons/ic-chevron-right-gray.png" mode="aspectFit" />
          </view>
        </view>
        <view v-if="xwMorning" class="xw-body">
          <view class="xw-q">
            <view class="xw-q-num">1</view>
            <text class="xw-q-text">{{ xwMorning.questions[0] }}</text>
          </view>
          <view v-if="xwMorning.questions[1]" class="xw-q">
            <view class="xw-q-num">2</view>
            <text class="xw-q-text xw-q-clamp">{{ xwMorning.questions[1] }}</text>
          </view>
          <view class="xw-foot">
            <text class="xw-chal">小挑战：{{ xwMorning.challenge }}</text>
            <text class="xw-cta">回应小薇 ›</text>
          </view>
        </view>
        <view v-else class="xw-body xw-empty">
          <text class="xw-empty-text">点击进入，生成今天的认知刷新</text>
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
            class="ladder-step {{t.tier === tierNum ? 'ladder-step-current' : ''}}"
            @tap="goMembership"
          >
            <text v-if="t.tier === tierNum" class="ls-now">当前</text>
            <view class="{{'ls-dot' + (t.tier <= tierNum ? ' ls-dot-open' : ' ls-dot-locked')}}">
              {{ t.short }}
            </view>
            <text class="{{'ls-name' + (t.tier === tierNum ? ' ls-name-current' : '')}}">{{ t.name }}</text>
          </view>
        </view>
        <view class="ladder-foot">当前 L{{ tierNum }} · 持续参与活动、贡献与学习，逐级解锁更丰富权益</view>
      </view>
    </view>

    <!-- ===== 5 宫格快捷入口 ===== -->
    <view class="section px-4">
      <view class="grids">
        <view v-for="g in grids" :key="g.label" class="grid-item card" @tap="goTo(g.to)">
          <view class="{{'gi-icon gi-' + (g.icon || 'default')}}">
            <image class="ic ic-md" :src="gridIconSrc(g.icon)" mode="aspectFit" />
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
            <image class="ic ic-md" src="/static/icons/ic-calendar-days-gold.png" mode="aspectFit" />
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
        <view class="chips-inner">
          <view
            class="{{'chip glass-control' + (chip === null ? ' glass-control-active' : '')}}"
            @tap="chip = null; applyChip()"
          >
            全部
          </view>
          <view
            v-for="c in CHIPS"
            :key="c.key"
            class="{{'chip glass-control' + (chip === c.key ? ' glass-control-active' : '')}}"
            @tap="chip = chip === c.key ? null : c.key; applyChip()"
          >
            {{ c.label }}
          </view>
        </view>
      </scroll-view>

      <!-- 月历 -->
      <calendar :events="events" @select="onSelectDay" />

      <!-- 活动列表 -->
      <view class="ev-list">
        <view v-if="loading" class="empty"><skeleton type="list" :rows="3" /></view>
        <view v-else-if="displayEvents.length === 0" class="empty">{{ selectedEvents.length ? '该日暂无活动' : '暂无活动' }}</view>
        <block v-else>
          <view v-for="ev in displayEvents.slice(0, 6)" :key="ev.id" class="ev-card card" @tap="goEventDetail(ev.id)">
            <view class="{{'ev-left' + (' ' + metaTone(ev.event_type))}}">
              <image class="ic ic-md" :src="iconPath(metaIcon(ev.event_type))" mode="aspectFit" />
            </view>
            <view class="ev-right">
              <view class="ev-r-head">
                <text class="ev-r-title">{{ ev.title }}</text>
                <text v-if="ev.min_tier" class="ev-tier-badge">需 L{{ ev.min_tier }} 等级</text>
              </view>
              <view class="ev-r-line">
                <image class="ic ic-sm" src="/static/icons/ic-clock-3-orange.png" mode="aspectFit" />
                <text class="ev-r-text">{{ evTime(ev) }}</text>
              </view>
              <view class="ev-r-line">
                <image class="ic ic-sm" src="/static/icons/ic-map-pin-orange.png" mode="aspectFit" />
                <text class="ev-r-addr">{{ ev.location_name || ev.address || '地址待定' }}</text>
                <view class="ev-nav" @tap.stop="openMap(ev)">
                  <image class="ic ic-xs" src="/static/icons/ic-navigation-blue.png" mode="aspectFit" />
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
                  <image class="ic ic-sm" src="/static/icons/ic-scan-line-white.png" mode="aspectFit" />
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
            <image class="ic ic-md" src="/static/icons/ic-sparkles-gold.png" mode="aspectFit" />
            <text class="ev-title">精选活动</text>
          </view>
          <text class="ev-sub">本周值得参与的高质量连接</text>
        </view>
      </view>
      <view class="featured card" @tap="goEventDetail(featured.id)">
        <view class="fd-left">
          <view class="fd-badge">限量席位</view>
          <view>
            <view class="fd-meta">{{ fdDate(featured) }} · {{ featured.location_name || featured.address || '地点待定' }}</view>
            <view class="fd-title">{{ featured.title }}</view>
          </view>
        </view>
        <view class="fd-right">
          <text class="fd-summary">{{ featured.summary || '暂无简介' }}</text>
          <view class="fd-foot">
            <view class="fd-rec">
              <image class="ic ic-xs" src="/static/icons/ic-calendar-check-gold.png" mode="aspectFit" />
              <text>本周推荐</text>
            </view>
            <view class="fd-btn" @tap.stop="goEventDetail(featured.id)">报名</view>
          </view>
        </view>
      </view>
    </view>


    <!-- ===== 本周精选（对齐 H5：2 列网格） ===== -->
    <view class="section px-4">
      <view class="ev-head">
        <view>
          <view class="ev-title-row">
            <image class="ic ic-md" src="/static/icons/ic-calendar-days-gold.png" mode="aspectFit" />
            <text class="ev-title">本周精选</text>
          </view>
          <text class="ev-sub">为你的成长节奏精心编排</text>
        </view>
        <view class="ev-more" @tap="goEvents">
          <text>全部活动</text>
          <text class="ev-arrow">></text>
        </view>
      </view>
      <view v-if="loading" class="empty"><skeleton type="list" :rows="2" /></view>
      <view v-else-if="events.length === 0" class="empty">暂无活动</view>
      <view v-else class="week-grid">
        <view v-for="ev in events.slice(0, 4)" :key="ev.id" class="week-card card" @tap="goEventDetail(ev.id)">
          <view class="{{'week-thumb ' + metaTone(ev.event_type)}}">
            <image class="ic ic-lg" :src="iconPath(metaIcon(ev.event_type))" mode="aspectFit" />
          </view>
          <view class="week-info">
            <text class="week-title">{{ ev.title }}</text>
            <text class="week-meta">{{ weekDate(ev) }} · {{ ev.location_name || ev.address || '地点待定' }}</text>
            <view class="week-badge">{{ metaLabel(ev.event_type) }}</view>
          </view>
        </view>
      </view>
    </view>

    <view class="footer">明德恒智AI企商汇 · PBC 企业家事业共同体</view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { track } from '@/libs/track'
import { fetchSiteConfig } from '@/common/site-config'
import { TIERS, tierToNumber, applyTierConfig } from '@/common/tier'
import { toDate, fmtZhDateTime, fmtHHmm, formatMoney } from '@/common/format'
import Calendar from '@/components/Calendar.vue'
import Skeleton from '@/components/Skeleton.vue'
import { VIRTUAL_PAY_DISABLED, AI_DISABLED } from '@/config/app'

const EVENT_META = {
  personal_growth: { label: '个人成长', glyph: '成', tone: 'tone-growth', icon: 'graduation-cap' },
  business_industry: { label: '事业行业', glyph: '事', tone: 'tone-industry', icon: 'building-2' },
  charity: { label: '公益慈善', glyph: '益', tone: 'tone-charity', icon: 'heart-handshake' }
}
const DEFAULT_META = { label: '官方活动', glyph: '活', tone: 'tone-default', icon: 'calendar-check' }

const CHIPS = [
  { key: 'personal_growth', label: '个人成长' },
  { key: 'business_industry', label: '事业行业' },
  { key: 'charity', label: '公益慈善' }
]

export default {
  components: { Calendar, Skeleton },
  data() {
    return {
      AI_DISABLED: AI_DISABLED,
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
      searchKw: '',
      hasUnread: false,
      tierNum: 1,
      grids: [],
      ladder: TIERS,
      CHIPS,
      xwBrand: "小薇",
      xwMorning: null,
      tasks: [],
      taskRegistrations: []
    }
  },
  computed: {
    avatarText() {
      return (this.displayName || '明').slice(0, 1)
    },
    currentTier() {
      return this.ladder.find((t) => t.tier === this.tierNum) || this.ladder[0]
    },
    displayName() {
      return (this.profile && (this.profile.real_name || this.profile.nickname)) || '明德会员'
    },
    taskDone() {
      return this.tasks.filter((t) => t.done).length
    },
    taskPct() {
      return this.tasks.length ? Math.round((this.taskDone / this.tasks.length) * 100) : 0
    }
  },
  onLoad(options) {
    // 分享回流：携带邀请码则暂存（注册 bootstrap 时绑定推荐关系）
    if (options && options.invite) {
      try { uni.setStorageSync('invite_code', options.invite) } catch (e) {}
    }
    // 首启欢迎引导已下线（2026-08-18 大王决策：去掉整个开屏页，直接进首页）
  },
  onShow() {
    this.loadData()
    this.loadCoaching()
  },
  onPullDownRefresh() {
    this.loadData().finally(() => uni.stopPullDownRefresh())
  },
  methods: {
    async loadCoaching() {
      try {
        const res = await chamber.coachingToday()
        const d = res || {}
        this.xwBrand = (d.brand && d.brand.name) || "小薇"
        this.xwMorning = d.morning || null
      } catch (e) {
        this.xwMorning = null
      }
    },

    goCoaching() {
      if (AI_DISABLED) return
      uni.navigateTo({ url: "/pages/coaching/index" })
    },

    async loadData() {
      fetchSiteConfig().then((cfg) => {
        if (!cfg) return
        this.ladder = applyTierConfig(cfg)
        const hg = cfg.home_grids
        if (Array.isArray(hg) && hg.length > 0) {
          // config 只有 label/to（to 是短路径如 /events），与 DEFAULT_GRIDS 长路径匹配并补齐 icon/to
          this.grids = hg.map((g) => {
            const def = DEFAULT_GRIDS.find((d) => d.to === g.to || d.to.indexOf(g.to) >= 0) || DEFAULT_GRIDS[0]
            return { label: (g.label || def.label), to: def.to, icon: def.icon }
          }).filter((g) => !AI_DISABLED || g.to !== '/pages/ai-ecosystem/index')
        } else {
          this.grids = DEFAULT_GRIDS.filter((g) => !AI_DISABLED || g.to !== '/pages/ai-ecosystem/index')
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
      this.loadTasks()
      this.loading = false
    },
    async loadTasks() {
      if (!checkLogin()) {
        this.tasks = []
        return
      }
      const profileDone = !!(this.profile && (this.profile.real_name || this.profile.nickname))
      let regDone = this.taskRegistrations.length > 0
      let twinDone = false
      try {
        const saved = uni.getStorageSync('chat_2') || uni.getStorageSync('chat_default')
        twinDone = !!(saved && JSON.parse(saved).length > 0)
      } catch (e) {}
      if (!regDone) {
        try {
          const regs = await chamber.myEventRegistrations()
          this.taskRegistrations = regs || []
          regDone = regs.length > 0
        } catch (e) {}
      }
      this.tasks = [
        { key: 'profile', name: '完善个人资料', desc: '让大咖认识你', done: profileDone, to: '/pages/mine/index?edit=1' },
        { key: 'register', name: '报名一场活动', desc: '开启商会第一步', done: regDone, to: '/pages/events/index' },
        { key: 'twin', name: '认识行业大咖', desc: '浏览导师/教练/行业领袖主页', done: twinDone, to: '/pages/experts/index' }
      ]
      if (this.taskDone >= this.tasks.length) {
        try { uni.removeStorageSync('task_card_dismissed') } catch (e) {}
      }
    },
    goTask(t) {
      if (t.done) return
      track('task_click', { key: t.key })
      if (!t.to) return
      const path = t.to.split('?')[0]
      const tabPages = ['/pages/index/index', '/pages/events/index', '/pages/mall/index', '/pages/experts/index', '/pages/mine/index']
      if (tabPages.includes(path)) {
        // tabBar 页面必须 switchTab（navigateTo 会静默失败）；profile 编辑意图经 storage 传递
        if (t.key === 'profile') {
          try { uni.setStorageSync('mine_enter_edit', '1') } catch (e) {}
        }
        uni.switchTab({ url: path })
      } else {
        uni.navigateTo({ url: t.to })
      }
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
      const s = fmtZhDateTime(ev.start_time)
      const e = fmtHHmm(ev.end_time)
      if (!s) return '时间待定'
      return e ? s + ' — ' + e : s
    },
    evPrice(ev) {
      const t = ev.tickets && ev.tickets[0]
      if (!t) return ''
      const p = Number(t.price || 0)
      return p > 0 ? formatMoney(p) : ''
    },
    evReward(ev) {
      if (ev.checkin_reward_points) return '签到 +' + ev.checkin_reward_points + ' 积分'
      if (ev.checkin_reward_contribution) return '签到 +' + ev.checkin_reward_contribution + ' 贡献'
      return ''
    },
    fdDate(ev) {
      return toDate(ev.start_time)
    },
    weekDate(ev) {
      const d = toDate(ev.start_time)
      if (!d) return ''
      return d.slice(5, 7) + '月' + d.slice(8, 10) + '日'
    },
    gridGlyph(icon) {
      const map = { event: '活', expert: '咖', mall: '商', ai: 'AI', graduate: '认', default: '·' }
      return map[icon] || map.default
    },
    gridIcon(icon) {
      // 宫格图标 -> lucide 图标类（配色随 tone：金色块/蓝色块/灰块）
      const map = {
        event: 'ic-calendar-check-gold',
        membership: 'ic-users-blue',
        mall: 'ic-store-gold',
        expert: 'ic-sparkles-blue',
        ai: 'ic-bot-gray',
        graduate: 'ic-graduation-cap-blue',
        default: 'ic-star-gold'
      }
      return map[icon] || map.default
    },
    iconPath(name) { return '/static/icons/' + name + '.png' },
    gridIconSrc(icon) {
      // 宫格图标 -> 本地 PNG 路径（image 组件，微信 100% 支持）
      const name = this.gridIcon(icon)
      return '/static/icons/' + name + '.png'
    },
    metaIcon(t) {
      // 活动类型 -> lucide 图标类（白字，用在深色色块上）
      const map = {
        personal_growth: 'ic-graduation-cap-white',
        business_industry: 'ic-building-2-white',
        charity: 'ic-heart-handshake-white',
        default: 'ic-calendar-check-white'
      }
      return map[t] || map.default
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
      if (!path || !path.startsWith('/pages/')) return
      // tabBar 页必须用 switchTab（微信限制：navigateTo 打不开 tabBar 页）
      const TAB_PAGES = ['/pages/index/index', '/pages/events/index', '/pages/mall/index', '/pages/experts/index', '/pages/mine/index']
      if (TAB_PAGES.includes(path)) uni.switchTab({ url: path })
      else uni.navigateTo({ url: path })
    },
    goSearch() {
      uni.navigateTo({ url: '/pages/search/index' })
    },
    goSearchKw() {
      const kw = (this.searchKw || '').trim()
      uni.navigateTo({ url: '/pages/search/index' + (kw ? '?q=' + encodeURIComponent(kw) : '') })
    },
    goNotifications() {
      uni.navigateTo({ url: '/pages/mine/notifications/index' })
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
  { label: '会员中心', icon: 'membership', to: '/pages/membership/index' },
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
  padding-top: env(safe-area-inset-top);
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
  flex-shrink: 0;
  white-space: nowrap;
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
/* ===== 本周精选 2 列网格 ===== */
.week-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
  margin-top: 8rpx;
}
.week-card {
  /* 微信 wxss 不认 % + rpx 混合 calc；48.5% 浏览器取整溢出会换行，48% 稳定两列 */
  width: 48%;
  overflow: hidden;
}
.week-thumb {
  height: 160rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.week-info {
  padding: 20rpx 24rpx 24rpx;
}
.week-title {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  font-size: 26rpx;
  font-weight: 700;
  color: #203756;
  line-height: 1.5;
  min-height: 78rpx;
}
.week-meta {
  display: block;
  font-size: 20rpx;
  color: #8994a5;
  margin-top: 10rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.week-badge {
  display: inline-block;
  font-size: 20rpx;
  color: #6b7889;
  background: #eef0f3;
  padding: 4rpx 14rpx;
  border-radius: 999rpx;
  margin-top: 14rpx;
}
.footer {
  text-align: center;
  color: #a7afbb;
  font-size: 20rpx;
  margin-top: 48rpx;
  padding: 0 0 20rpx;
}
.empty {
  text-align: center;
  padding: 40rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}

/* ===== 小薇今日3问卡片 ===== */
.xw-card {
  background: linear-gradient(135deg, #fff8ec, #fff);
  border: 1rpx solid #f0ddc2;
  border-radius: 28rpx;
  padding: 28rpx;
  box-sizing: border-box;
  box-shadow: 0 8rpx 32rpx rgba(185, 130, 40, 0.08);
}
.xw-head {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
.xw-avatar {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #d99b49, #a9651e);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 6rpx 16rpx rgba(185, 110, 29, 0.25);
}
.xw-title-wrap {
  flex: 1;
  min-width: 0;
}
.xw-title {
  display: block;
  font-size: 28rpx;
  font-weight: 700;
  color: #17325b;
}
.xw-sub {
  display: block;
  font-size: 20rpx;
  color: #a9651e;
  margin-top: 4rpx;
}
.xw-arrow {
  flex-shrink: 0;
}
.xw-body {
  margin-top: 20rpx;
  border-top: 1rpx dashed #e8d5b0;
  padding-top: 20rpx;
}
.xw-q {
  display: flex;
  gap: 14rpx;
  align-items: flex-start;
  margin-bottom: 14rpx;
}
.xw-q-num {
  width: 34rpx;
  height: 34rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #d99b49, #a9651e);
  color: #fff;
  font-size: 20rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2rpx;
}
.xw-q-text {
  flex: 1;
  font-size: 24rpx;
  color: #203454;
  line-height: 1.7;
}
.xw-q-clamp {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.xw-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  margin-top: 6rpx;
}
.xw-chal {
  flex: 1;
  font-size: 22rpx;
  color: #5c6b80;
}
.xw-cta {
  flex-shrink: 0;
  font-size: 22rpx;
  font-weight: 600;
  color: #a9651e;
}
.xw-empty {
  text-align: center;
}
.xw-empty-text {
  font-size: 22rpx;
  color: #8a94a3;
}

/* ===== 新手任务卡 ===== */
.task-card {
  padding: 28rpx;
}
.task-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16rpx;
}
.task-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #17233d;
}
.task-progress {
  font-size: 24rpx;
  color: #a9651e;
}
.task-progress-bar {
  height: 10rpx;
  border-radius: 5rpx;
  background: #f0e6d8;
  overflow: hidden;
  margin-bottom: 24rpx;
}
.tpb-fill {
  height: 100%;
  border-radius: 5rpx;
  background: linear-gradient(90deg, #c87922, #eba94e);
  transition: width 0.3s;
}
.task-item {
  display: flex;
  align-items: center;
  padding: 16rpx 0;
}
.task-dot {
  width: 40rpx;
  height: 40rpx;
  border-radius: 50%;
  border: 2rpx solid #c9b396;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 20rpx;
  flex-shrink: 0;
}
.task-dot-done {
  border-color: #c87922;
  background: linear-gradient(90deg, #c87922, #eba94e);
}
.task-check {
  color: #fff;
  font-size: 24rpx;
}
.task-info {
  flex: 1;
}
.task-name {
  display: block;
  font-size: 28rpx;
  color: #17233d;
}
.task-desc {
  display: block;
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 2rpx;
}
.task-go {
  margin-left: 16rpx;
}
.task-btn {
  font-size: 24rpx;
  color: #a9651e;
  border: 1rpx solid #c87922;
  border-radius: 24rpx;
  padding: 8rpx 24rpx;
}
.task-btn-done {
  color: #8a94a3;
  border-color: #d5d9e0;
}
</style>
