<template>
  <view class="search-page">
    <view class="search-bar">
      <view class="search-box">
        <view class="ic ic-sm ic-search-gold" />
        <input
          v-model="keyword"
          class="s-input"
          focus
          placeholder="搜索活动 / 大咖"
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
      <view v-else-if="events.length === 0 && experts.length === 0" class="empty">未找到相关内容</view>
      <view v-else class="results">
        <view v-if="events.length" class="group">
          <text class="g-title">活动</text>
          <view v-for="ev in events" :key="ev.id" class="item card" @tap="goEvent(ev.id)">
            <text class="item-name">{{ ev.title }}</text>
            <text class="item-arrow">></text>
          </view>
        </view>
        <view v-if="experts.length" class="group">
          <text class="g-title">大咖</text>
          <view v-for="e in experts" :key="e.id" class="item card" @tap="goExpert(e.id)">
            <text class="item-name">{{ e.name }} {{ e.industry ? '· ' + e.industry : '' }}</text>
            <text class="item-arrow">></text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'

export default {
  data() {
    return {
      keyword: '',
      searched: false,
      loading: false,
      events: [],
      experts: [],
      hot: ['大咖讲堂', '路演', '陈明远']
    }
  },
  methods: {
    async doSearch() {
      const kw = this.keyword.trim()
      if (!kw) return
      this.searched = true
      this.loading = true
      const results = await Promise.allSettled([chamber.events(), chamber.experts()])
      if (results[0].status === 'fulfilled') {
        this.events = (results[0].value || []).filter((ev) => (ev.title || '').indexOf(kw) >= 0)
      }
      if (results[1].status === 'fulfilled') {
        this.experts = (results[1].value || []).filter(
          (e) => (e.name || '').indexOf(kw) >= 0 || (e.industry || '').indexOf(kw) >= 0
        )
      }
      this.loading = false
    },
    goBack() {
      uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/index/index' }) })
    },
    goEvent(id) {
      uni.navigateTo({ url: '/pages/events/detail/index?id=' + id })
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
  border-radius: 999rpx;
  padding: 20rpx 28rpx;
  box-shadow: 0 4rpx 16rpx rgba(39, 59, 89, 0.04);
}
.s-icon {
  color: #b8751d;
  font-size: 32rpx;
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
.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.results {
  margin-top: 32rpx;
}
.group {
  margin-bottom: 32rpx;
}
.g-title {
  font-size: 28rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
  margin-bottom: 16rpx;
}
.item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 26rpx 28rpx;
  margin-bottom: 16rpx;
}
.item-name {
  font-size: 28rpx;
  color: #273b59;
}
.item-arrow {
  color: #c0c6d0;
  font-size: 32rpx;
}
</style>
