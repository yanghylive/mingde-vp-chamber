<template>
  <view class="search-page">
    <page-header title="搜索" />
    <view class="search-bar">
      <view class="search-box">
        <image class="ic ic-sm" src="/static/icons/ic-search-gold.png" mode="aspectFit" />
        <input
          v-model="keyword"
          class="s-input"
          focus
          placeholder="搜索活动 / 大咖 / 商品"
          placeholder-class="ph"
          confirm-type="search"
          @confirm="doSearch"
        />
      </view>
      <text class="s-cancel" @tap="goBack">取消</text>
    </view>

    <view v-if="!searched" class="suggestions">
      <text class="sug-title">热门搜索</text>
      <view class="sug-tags">
        <view v-for="k in hot" :key="k" class="sug-tag" @tap="keyword = k; doSearch()">{{ k }}</view>
      </view>
    </view>

    <view v-else>
      <view v-if="loading" class="empty">搜索中…</view>
      <view v-else-if="events.length === 0 && experts.length === 0 && products.length === 0" class="empty">
        <image class="ic ic-lg empty-icon" src="/static/icons/ic-search-gold.png" mode="aspectFit" />
        <text class="empty-text">未找到与「{{ keyword }}」相关的内容</text>
        <view class="btn-secondary empty-btn" @tap="goHome"><text>返回首页</text></view>
      </view>
      <view v-else class="results">
        <text v-if="total > 0" class="result-count">共找到 <text class="rc-num">{{ total }}</text> 条与「{{ keyword }}」相关的结果</text>

        <view v-if="events.length" class="group">
          <view class="g-title-row">
            <image class="ic ic-sm" src="/static/icons/ic-graduation-cap-gold.png" mode="aspectFit" />
            <text class="g-title">活动（{{ events.length }}）</text>
          </view>
          <view v-for="ev in events" :key="ev.id" class="item card" @tap="goEvent(ev.id)">
            <view class="ev-icon">
              <image class="ic ic-sm" src="/static/icons/ic-graduation-cap-white.png" mode="aspectFit" />
            </view>
            <view class="ev-info">
              <text class="ev-name">{{ ev.title }}</text>
              <view class="ev-meta">
                <image class="ic ic-xs" src="/static/icons/ic-clock-3-orange.png" mode="aspectFit" />
                <text class="ev-meta-text">{{ ev.start_time ? formatTime(ev.start_time) : '时间待定' }}</text>
              </view>
              <view class="ev-meta">
                <image class="ic ic-xs" src="/static/icons/ic-map-pin-orange.png" mode="aspectFit" />
                <text class="ev-meta-text">{{ ev.location_name || ev.address || '地址待定' }}</text>
              </view>
              <view v-if="ev.event_type" class="ev-badge">{{ typeLabel(ev.event_type) }}</view>
            </view>
          </view>
        </view>

        <view v-if="experts.length" class="group">
          <view class="g-title-row">
            <image class="ic ic-sm" src="/static/icons/ic-users-blue.png" mode="aspectFit" />
            <text class="g-title">大咖（{{ experts.length }}）</text>
          </view>
          <view v-for="e in experts" :key="e.id" class="item card" @tap="goExpert(e.id)">
            <view class="ex-avatar">
              <text class="ex-initial">{{ (e.name || '?').charAt(0) }}</text>
            </view>
            <view class="ex-info">
              <text class="ex-name">{{ e.name }}</text>
              <view v-if="e.title" class="ex-meta">
                <image class="ic ic-xs" src="/static/icons/ic-link-2-gold.png" mode="aspectFit" />
                <text class="ex-meta-text">{{ e.title }}</text>
              </view>
              <text v-if="e.company || e.industry || e.bio" class="ex-sub">{{ [e.company, e.industry, e.bio].filter(function(x) { return x }).join(' · ') }}</text>
            </view>
          </view>
        </view>

        <view v-if="products.length" class="group">
          <view class="g-title-row">
            <image class="ic ic-sm" src="/static/icons/ic-gift-gold.png" mode="aspectFit" />
            <text class="g-title">商品（{{ products.length }}）</text>
          </view>
          <view class="prod-grid">
            <view v-for="p in products" :key="p.id" class="prod-card card" @tap="goMall(p)">
              <view class="prod-img">
                <image class="ic ic-md" src="/static/icons/ic-gift-gold.png" mode="aspectFit" />
              </view>
              <text class="prod-name">{{ p.name || p.store_name }}</text>
              <text class="prod-price">¥{{ p.cash_price || p.cash || 0 }}</text>
            </view>
          </view>
        </view>
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
      keyword: '',
      searched: false,
      loading: false,
      events: [],
      experts: [],
      products: [],
      hot: ['大咖讲堂', '路演', '陈明远']
    }
  },
  computed: {
    total() {
      return this.events.length + this.experts.length + this.products.length
    }
  },
  onLoad(options) {
    if (options && options.q) {
      this.keyword = decodeURIComponent(options.q)
      this.doSearch()
    }
  },
  methods: {
    formatTime(ts) {
      return toDate(ts, 'datetime')
    },
    typeLabel(t) {
      var map = { personal_growth: '个人成长', industry: '事业行业', charity: '公益慈善' }
      return map[t] || '官方活动'
    },
    async doSearch() {
      var kw = this.keyword.trim()
      if (!kw) return
      this.searched = true
      this.loading = true
      // 服务端搜索：关键词传给后端，后端 LIKE 匹配返回过滤结果
      var results = await Promise.allSettled([
        chamber.events({ q: kw }),
        chamber.experts({ q: kw }),
        chamber.products({ q: kw })
      ])
      if (results[0].status === 'fulfilled') {
        this.events = (results[0].value || []).slice(0, 5)
      }
      if (results[1].status === 'fulfilled') {
        this.experts = (results[1].value || []).slice(0, 5)
      }
      if (results[2].status === 'fulfilled') {
        this.products = (results[2].value || []).slice(0, 4)
      }
      this.loading = false
    },
    goBack() {
      uni.navigateBack({ fail: function() { uni.switchTab({ url: '/pages/index/index' }) } })
    },
    goHome() {
      uni.switchTab({ url: '/pages/index/index' })
    },
    goEvent(id) {
      uni.navigateTo({ url: '/pages/events/detail/index?id=' + id })
    },
    goMall(p) {
      uni.switchTab({ url: '/pages/mall/index' })
    },
    goExpert(id) {
      uni.navigateTo({ url: '/pages/experts/detail/index?id=' + id })
    }
  }
}
</script>

<style lang="scss">
.search-page {
  padding: 24rpx 32rpx 60rpx;
}
.search-bar {
  display: flex;
  align-items: center;
  gap: 20rpx;
}
.search-box {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 12rpx;
  background: #fff;
  border-radius: 32rpx;
  padding: 20rpx 28rpx;
  box-shadow: 0 4rpx 16rpx rgba(39, 59, 89, 0.04);
}
.s-input {
  flex: 1;
  font-size: 28rpx;
  color: #273b59;
}
.ph {
  color: #c0c6d0;
}
.s-cancel {
  font-size: 26rpx;
  color: #516580;
}

/* Suggestions */
.suggestions {
  margin-top: 40rpx;
}
.sug-title {
  font-size: 26rpx;
  color: #8a94a3;
}
.sug-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
  margin-top: 24rpx;
}
.sug-tag {
  padding: 14rpx 32rpx;
  border-radius: 999rpx;
  background: #fff;
  color: #516580;
  font-size: 26rpx;
}

/* Empty results */
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 80rpx 0;
}
.empty-icon {
  margin-bottom: 24rpx;
  opacity: 0.4;
}
.empty-text {
  font-size: 26rpx;
  color: #c2cbd6;
  text-align: center;
}
.empty-btn {
  margin-top: 32rpx;
  padding: 16rpx 40rpx;
  border-radius: 24rpx;
  font-size: 26rpx;
  font-weight: 600;
}

/* Results */
.results {
  margin-top: 32rpx;
}
.result-count {
  display: block;
  font-size: 24rpx;
  color: #8a94a3;
  margin-bottom: 24rpx;
}
.rc-num {
  font-weight: 700;
  color: #a9651e;
}
.group {
  margin-bottom: 40rpx;
}
.g-title-row {
  display: flex;
  align-items: center;
  gap: 8rpx;
  margin-bottom: 16rpx;
}
.g-title {
  font-size: 30rpx;
  font-weight: 700;
  color: #17325b;
}

/* Event items */
.item {
  display: flex;
  align-items: flex-start;
  gap: 24rpx;
  padding: 28rpx;
  margin-bottom: 16rpx;
}
.ev-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 32rpx;
  background: linear-gradient(135deg, #1a4778, #102b50);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ev-info {
  flex: 1;
  min-width: 0;
}
.ev-name {
  display: block;
  font-size: 26rpx;
  font-weight: 700;
  color: #17325b;
}
.ev-meta {
  display: flex;
  align-items: center;
  gap: 6rpx;
  margin-top: 8rpx;
}
.ev-meta-text {
  font-size: 20rpx;
  color: #8a94a3;
}
.ev-badge {
  display: inline-flex;
  margin-top: 8rpx;
  background: #eef0f3;
  color: #6b7889;
  font-size: 20rpx;
  padding: 4rpx 16rpx;
  border-radius: 999rpx;
}

/* Expert items */
.ex-avatar {
  width: 88rpx;
  height: 88rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #b77a34, #82531f);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ex-initial {
  font-size: 36rpx;
  font-weight: 700;
  color: #fff;
}
.ex-info {
  flex: 1;
  min-width: 0;
}
.ex-name {
  display: block;
  font-size: 26rpx;
  font-weight: 700;
  color: #17325b;
}
.ex-meta {
  display: flex;
  align-items: center;
  gap: 6rpx;
  margin-top: 6rpx;
}
.ex-meta-text {
  font-size: 20rpx;
  color: #a56d2c;
}
.ex-sub {
  display: block;
  font-size: 20rpx;
  color: #8a94a3;
  margin-top: 6rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Product grid */
.prod-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 24rpx;
}
.prod-card {
  width: calc(50% - 12rpx);
  padding: 24rpx;
  box-sizing: border-box;
}
.prod-img {
  width: 100%;
  height: 128rpx;
  border-radius: 24rpx;
  background: linear-gradient(135deg, #e7eef8, #d3e0ef);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 12rpx;
}
.prod-name {
  display: block;
  font-size: 24rpx;
  font-weight: 700;
  color: #17325b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prod-price {
  display: block;
  font-size: 22rpx;
  font-weight: 700;
  color: #c57620;
  margin-top: 4rpx;
}
</style>
