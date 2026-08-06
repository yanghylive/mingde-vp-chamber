<template>
  <view class="experts-page">
    <!-- 页头 -->
    <view class="ph">
      <text class="ph-title">明德大咖</text>
      <text class="ph-sub">与时代的先行者，深度同行</text>
      <view class="search-box glass-control">
        <image class="ic ic-sm" src="/static/icons/ic-search-gold.png" mode="aspectFit" />
        <input v-model="search" class="s-input" placeholder="搜索大咖姓名 / 行业 / 领域" placeholder-class="ph" />
      </view>
    </view>

    <!-- 深蓝横幅 -->
    <view class="hero glass-dark">
      <view class="hero-top">
        <view class="hero-icon"><image class="ic ic-lg" src="/static/icons/ic-award-white.png" mode="aspectFit" /></view>
        <view>
          <view class="hero-badge">专家智库</view>
          <view class="hero-title">汇聚实战智慧</view>
          <view class="hero-sub">严选行业领军者，为关键决策提供可信洞见</view>
        </view>
      </view>
      <view class="hero-stats">
        <view v-for="s in stats" :key="s.label" class="{{'hs-item' + (s.border ? ' hs-border' : '')}}">
          <text class="hs-num">{{ loading ? '—' : s.value }}</text>
          <text class="hs-label">{{ s.label }}</text>
        </view>
      </view>
    </view>

    <!-- 推荐大咖 -->
    <view class="sec-head">
      <view class="sh-row">
        <view class="sh-icon"><image class="ic ic-md" src="/static/icons/ic-users-round-gold.png" mode="aspectFit" /></view>
        <view>
          <text class="sh-title">推荐大咖</text>
          <text class="sh-sub">导师 · 教练 · 行业领袖</text>
        </view>
      </view>
    </view>
    <scroll-view scroll-x enable-flex class="chips">
      <view class="chips-inner">
        <view
          v-for="c in categories"
          :key="c"
          class="{{'chip glass-control' + (category === c ? ' glass-control-active' : '')}}"
          @tap="category = c"
        >
          {{ c }}
        </view>
      </view>
    </scroll-view>

    <view v-if="loading" class="empty"><skeleton type="list" :rows="3" /></view>
    <view v-else-if="visible.length === 0" class="empty">暂无符合条件的大咖</view>
    <view v-else class="list">
      <view v-for="(e, idx) in visible" :key="e.id" class="expert card">
        <view class="{{'ex-avatar avatar-' + (idx % 4)}}">{{ e.first }}</view>
        <view class="ex-info">
          <view class="ex-head">
            <view>
              <text class="ex-name">{{ e.name }}</text>
              <view class="ex-title-row">
              <image class="ic ic-xs" src="/static/icons/ic-briefcase-business-gray.png" mode="aspectFit" />
              <text class="ex-title">{{ e.title || '明德大咖' }}{{ e.company ? ' · ' + e.company : '' }}</text>
            </view>
            </view>
          </view>
          <view class="ex-tags">
            <text v-if="e.industry" class="ex-tag">{{ e.industry }}</text>
            <text v-if="e.category" class="ex-tag ex-tag-2">{{ e.category }}</text>
          </view>
          <text v-if="e.bio || e.description" class="ex-bio">{{ (e.bio || e.description || '').slice(0, 60) }}</text>
          <!-- 定价条（对齐 H5） -->
          <view v-if="pricingReady(e)" class="ex-price">
            线上 {{ fmtPoints(e.online_points) }}积分 + {{ fmtMoney(e.online_cash) }} · 线下 {{ fmtPoints(e.offline_points) }}积分 + {{ fmtMoney(e.offline_cash) }}
          </view>
          <view v-else class="ex-price ex-price-muted">收费明细定价更新中，敬请期待</view>
          <!-- 按钮行（对齐 H5） -->
          <view class="ex-actions">
            <view class="ex-btn ex-btn-primary" @tap.stop="goDetail(e.id)">
              <image class="ic ic-sm" src="/static/icons/ic-calendar-check-white.png" mode="aspectFit" />
              <text>预约 1v1</text>
            </view>
            <view class="ex-btn ex-btn-ghost" @tap.stop="goChat(e.id)">
              <image class="ic ic-sm" src="/static/icons/ic-bot-gold.png" mode="aspectFit" />
              <text>大咖 AI 对话</text>
            </view>
          </view>
        </view>
      </view>
    </view>

    <!-- 与平台 AI 助手对话（对齐 H5） -->
    <view class="ai-card glass-dark" @tap="goChat">
      <view class="ai-icon"><image class="ic ic-md" src="/static/icons/ic-bot-white.png" mode="aspectFit" /></view>
      <view class="ai-info">
        <text class="ai-title">与平台 AI 助手对话</text>
        <text class="ai-sub">24h 在线 · 商会问答 / 活动咨询 / 使用指引</text>
      </view>
    </view>

    <!-- 金句卡（对齐 H5） -->
    <view class="quote-card">
      <view class="quote-mark">“</view>
      <view class="quote-body">
        <text class="quote-text">真正的成长，是让认知成为行动，让行动沉淀为长期价值。</text>
        <view class="quote-by">
          <image class="ic ic-xs" src="/static/icons/ic-graduation-cap-blue.png" mode="aspectFit" />
          <text>明德大咖智库</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { formatMoney, formatPoints } from '@/common/format'
import Skeleton from '@/components/Skeleton.vue'

const CATEGORIES = ['全部', '知名导师', '知名教练', '行业领袖']
const ROLE_KEYWORDS = {
  '知名导师': /导师|mentor/i,
  '知名教练': /教练|coach/i,
  '行业领袖': /领袖|投资人|创始|总裁|CEO|董事|会长|秘书长/i
}

export default {
  components: { Skeleton },
  data() {
    return {
      experts: [],
      loading: true,
      search: '',
      category: '全部',
      categories: CATEGORIES
    }
  },
  computed: {
    visible() {
      const q = this.search.trim().toLowerCase()
      return this.experts.filter((e) => {
        const catOk = this.matchCategory(e)
        const qOk = !q || ((e.name || '') + ' ' + (e.industry || '') + ' ' + (e.title || '')).toLowerCase().indexOf(q) >= 0
        return catOk && qOk
      })
    },
    stats() {
      // 对齐 H5：用 matchCategory 计算（title/company/industry/bio/category 关键词）
      const self = this
      return [
        { label: '签约大咖', value: this.experts.length, border: false },
        { label: '知名导师', value: this.experts.filter((e) => self.matchCategory(e, '知名导师')).length, border: true },
        { label: '行业领袖', value: this.experts.filter((e) => self.matchCategory(e, '行业领袖')).length, border: true }
      ]
    }
  },
  onShow() {
    this.loadData()
  },
  onPullDownRefresh() {
    this.loadData().finally(() => uni.stopPullDownRefresh())
  },
  methods: {
    matchCategory(e, cat) {
      const c = cat || this.category
      if (c === '全部') return true
      const role = String(e.role || '').toLowerCase()
      if (c === '知名导师' && role === 'mentor') return true
      if (c === '知名教练' && role === 'coach') return true
      if (c === '行业领袖' && role === 'industry_leader') return true
      const haystack = (e.title || '') + ' ' + (e.company || '') + ' ' + (e.industry || '') + ' ' + (e.bio || '') + ' ' + (e.category || '')
      return ROLE_KEYWORDS[c].test(haystack)
    },
    async loadData() {
      try {
        this.experts = (await chamber.experts()).map(function(e){ e.first = (e.name || '明').slice(0, 1); return e })
      } catch (e) {}
      this.loading = false
    },
    goDetail(id) {
      uni.navigateTo({ url: '/pages/experts/detail/index?id=' + id })
    },
    goChat(expertId) {
      uni.navigateTo({ url: '/pages/chat/index' + (expertId ? '?expert=' + expertId : '') })
    },
    pricingReady(e) {
      return Number(e.online_points || 0) > 0 || Number(e.online_cash || 0) > 0 || Number(e.offline_points || 0) > 0 || Number(e.offline_cash || 0) > 0
    },
    fmtPoints(v) {
      return formatPoints(v)
    },
    fmtMoney(v) {
      return formatMoney(v)
    }
  }
}
</script>

<style lang="scss">
.experts-page {
  padding-top: env(safe-area-inset-top);
  padding: 24rpx 32rpx 60rpx;
  min-height: 100vh;
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
.search-box {
  display: flex;
  align-items: center;
  gap: 12rpx;
  border-radius: 24rpx;
  padding: 20rpx 28rpx;
  margin-top: 20rpx;
}
.s-icon {
  color: #b87325;
  font-size: 26rpx;
}
.s-input {
  flex: 1;
  font-size: 26rpx;
  color: #203454;
}
.ph {
  color: #7f8b9c;
}

/* 深蓝横幅 */
.hero {
  border-radius: 44rpx;
  padding: 40rpx 36rpx;
  color: #fff;
  margin-top: 24rpx;
}
.hero-top {
  display: flex;
  align-items: center;
  gap: 28rpx;
}
.hero-icon {
  width: 128rpx;
  height: 128rpx;
  border-radius: 44rpx;
  background: linear-gradient(135deg, #f3bf73, #b87325);
  color: #fff;
  font-size: 48rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 12rpx 32rpx rgba(184, 115, 37, 0.35);
}
.hero-badge {
  display: inline-block;
  background: rgba(255, 255, 255, 0.1);
  color: #f6c982;
  font-size: 20rpx;
  padding: 4rpx 14rpx;
  border-radius: 8rpx;
}
.hero-title {
  font-size: 34rpx;
  font-weight: 600;
  margin-top: 12rpx;
}
.hero-sub {
  font-size: 20rpx;
  color: rgba(255, 255, 255, 0.7);
  line-height: 1.6;
  margin-top: 8rpx;
}
.hero-stats {
  display: flex;
  margin-top: 36rpx;
  padding-top: 28rpx;
  border-top: 1rpx solid rgba(255, 255, 255, 0.1);
}
.hs-item {
  flex: 1;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}
.hs-border {
  border-left: 1rpx solid rgba(255, 255, 255, 0.1);
  border-right: 1rpx solid rgba(255, 255, 255, 0.1);
}
.hs-num {
  font-size: 36rpx;
  font-weight: 700;
  color: #f5bd69;
}
.hs-label {
  font-size: 18rpx;
  color: rgba(255, 255, 255, 0.55);
}

/* 推荐大咖 */
.sec-head {
  margin: 36rpx 0 20rpx;
}
.sh-row {
  display: flex;
  align-items: center;
  gap: 14rpx;
}
.sh-icon {
  width: 44rpx;
  height: 44rpx;
  border-radius: 12rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 22rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sh-title {
  display: block;
  font-size: 34rpx;
  font-weight: 700;
  color: #17325b;
}
.sh-sub {
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
  flex-shrink: 0;
  white-space: nowrap;
}
.empty {
  text-align: center;
  padding: 80rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}

/* 大咖卡 */
.list {
  margin-top: 24rpx;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.expert {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 28rpx;
}
.ex-avatar {
  width: 128rpx;
  height: 128rpx;
  border-radius: 40rpx;
  color: #fff;
  font-size: 40rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 12rpx 32rpx rgba(39, 59, 89, 0.2);
}
.avatar-0 { background: linear-gradient(135deg, #1a4778, #102b50); }
.avatar-1 { background: linear-gradient(135deg, #b77a34, #82531f); }
.avatar-2 { background: linear-gradient(135deg, #477467, #294d43); }
.avatar-3 { background: linear-gradient(135deg, #35557e, #20364f); }
.ex-info {
  flex: 1;
  min-width: 0;
}
.ex-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}
.ex-name {
  font-size: 32rpx;
  font-weight: 700;
  color: #1e3656;
  display: block;
}
.ex-title-row {
  display: flex;
  align-items: center;
  gap: 6rpx;
  margin-top: 6rpx;
}
.ex-title {
  font-size: 20rpx;
  color: #a56d2c;
  display: block;
  margin-top: 6rpx;
}
.ex-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 14rpx;
}
.ex-tag {
  font-size: 20rpx;
  color: #b8751d;
  background: #f6ead6;
  padding: 4rpx 14rpx;
  border-radius: 999rpx;
}
.ex-tag-2 {
  color: #285181;
  background: #e9f0f9;
}
.ex-bio {
  font-size: 22rpx;
  color: #7b8798;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  line-height: 1.8;
  margin-top: 16rpx;
}
.ex-price {
  font-size: 20rpx;
  color: #a06a2d;
  background: #fff6e8;
  border-radius: 24rpx;
  padding: 16rpx 24rpx;
  margin-top: 16rpx;
  line-height: 1.6;
}
.ex-price-muted {
  color: #8d97a6;
  background: #f2f5f8;
}
.ex-actions {
  display: flex;
  gap: 16rpx;
  margin-top: 24rpx;
  padding-top: 24rpx;
  border-top: 1rpx solid #eef1f5;
}
.ex-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8rpx;
  font-size: 24rpx;
  font-weight: 600;
  padding: 16rpx 0;
  border-radius: 14rpx;
}
.ex-btn-primary {
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  box-shadow: 0 8rpx 20rpx rgba(185, 110, 29, 0.2);
}
.ex-btn-ghost {
  background: #f1f4f8;
  color: #15305b;
  border: 1rpx solid #dfe6ee;
}
.ex-arrow {
  color: #c0c6d0;
  font-size: 32rpx;
  flex-shrink: 0;
}

/* AI 助手卡（深蓝玻璃） */
.ai-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  border-radius: 36rpx;
  padding: 32rpx;
  color: #fff;
  margin-top: 32rpx;
}
.ai-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 24rpx;
  background: rgba(255, 255, 255, 0.15);
  color: #f3bd70;
  font-size: 30rpx;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ai-info {
  flex: 1;
  min-width: 0;
}
.ai-title {
  display: block;
  font-size: 28rpx;
  font-weight: 700;
}
.ai-sub {
  display: block;
  font-size: 20rpx;
  color: rgba(255, 255, 255, 0.7);
  margin-top: 8rpx;
  line-height: 1.6;
}

/* 金句卡 */
.quote-card {
  display: flex;
  gap: 20rpx;
  border-radius: 32rpx;
  margin-top: 24rpx;
  padding: 36rpx;
  background: linear-gradient(135deg, #fffaf2, #ffffff);
  border: 1rpx solid #f0ddc2;
}
.quote-mark {
  font-size: 56rpx;
  font-weight: 700;
  color: #d18a35;
  line-height: 1;
  flex-shrink: 0;
}
.quote-body {
  flex: 1;
  min-width: 0;
}
.quote-text {
  display: block;
  font-size: 28rpx;
  font-weight: 600;
  line-height: 1.8;
  color: #30425d;
}
.quote-by {
  display: flex;
  align-items: center;
  gap: 8rpx;
  margin-top: 16rpx;
}
.quote-by text:first-child {
  width: 36rpx;
  height: 36rpx;
  border-radius: 10rpx;
  background: #e9f0f9;
  color: #285181;
  font-size: 20rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.quote-by text:last-child {
  font-size: 18rpx;
  color: #9a7a50;
}
</style>
