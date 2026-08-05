<template>
  <view class="experts-page">
    <!-- 页头 -->
    <view class="ph">
      <text class="ph-title">明德大咖</text>
      <text class="ph-sub">与时代的先行者，深度同行</text>
      <view class="search-box glass-control">
        <text class="s-icon">搜</text>
        <input v-model="search" class="s-input" placeholder="搜索大咖姓名 / 行业 / 领域" placeholder-class="ph" />
      </view>
    </view>

    <!-- 深蓝横幅 -->
    <view class="hero glass-dark">
      <view class="hero-top">
        <view class="hero-icon">师</view>
        <view>
          <view class="hero-badge">专家智库</view>
          <view class="hero-title">汇聚实战智慧</view>
          <view class="hero-sub">严选行业领军者，为关键决策提供可信洞见</view>
        </view>
      </view>
      <view class="hero-stats">
        <view v-for="s in stats" :key="s.label" :class="['hs-item', s.border && 'hs-border']">
          <text class="hs-num">{{ loading ? '—' : s.value }}</text>
          <text class="hs-label">{{ s.label }}</text>
        </view>
      </view>
    </view>

    <!-- 推荐大咖 -->
    <view class="sec-head">
      <view class="sh-row">
        <text class="sh-icon">咖</text>
        <view>
          <text class="sh-title">推荐大咖</text>
          <text class="sh-sub">导师 · 教练 · 行业领袖</text>
        </view>
      </view>
    </view>
    <scroll-view scroll-x class="chips">
      <view
        v-for="c in categories"
        :key="c"
        :class="['chip', 'glass-control', category === c && 'glass-control-active']"
        @tap="category = c"
      >
        {{ c }}
      </view>
    </scroll-view>

    <view v-if="loading" class="empty"><skeleton type="list" :rows="3" /></view>
    <view v-else-if="visible.length === 0" class="empty">暂无符合条件的大咖</view>
    <view v-else class="list">
      <view v-for="(e, idx) in visible" :key="e.id" class="expert card" @tap="goDetail(e.id)">
        <view :class="['ex-avatar', 'avatar-' + (idx % 4)]">{{ (e.name || '明')[0] }}</view>
        <view class="ex-info">
          <view class="ex-head">
            <view>
              <text class="ex-name">{{ e.name }}</text>
              <text class="ex-title">{{ e.title || '明德大咖' }}</text>
            </view>
          </view>
          <view class="ex-tags">
            <text v-if="e.industry" class="ex-tag">{{ e.industry }}</text>
            <text v-if="e.category" class="ex-tag ex-tag-2">{{ e.category }}</text>
          </view>
          <text v-if="e.bio || e.description" class="ex-bio">{{ (e.bio || e.description || '').slice(0, 40) }}</text>
        </view>
        <text class="ex-arrow">></text>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import Skeleton from '@/components/Skeleton.vue'

const CATEGORIES = ['全部', '知名导师', '行业领袖', '企业家教练', '投资人']

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
        const catOk = this.category === '全部' || (e.category || '').indexOf(this.category) >= 0 || (e.industry || '').indexOf(this.category) >= 0
        const qOk = !q || ((e.name || '') + ' ' + (e.industry || '') + ' ' + (e.title || '')).toLowerCase().indexOf(q) >= 0
        return catOk && qOk
      })
    },
    stats() {
      return [
        { label: '签约大咖', value: this.experts.length, border: false },
        { label: '知名导师', value: this.experts.filter((e) => (e.category || '').indexOf('导师') >= 0).length, border: true },
        { label: '行业领袖', value: this.experts.filter((e) => (e.category || '').indexOf('领袖') >= 0).length, border: true }
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
    async loadData() {
      try {
        this.experts = await chamber.experts()
      } catch (e) {}
      this.loading = false
    },
    goDetail(id) {
      uni.navigateTo({ url: '/pages/experts/detail/index?id=' + id })
    }
  }
}
</script>

<style lang="scss">
.experts-page {
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
  font-size: 44rpx;
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
  font-size: 30rpx;
  font-weight: 700;
  color: #1e3656;
  display: block;
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
  color: #a0a8b5;
  display: block;
  margin-top: 12rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ex-arrow {
  color: #c0c6d0;
  font-size: 32rpx;
  flex-shrink: 0;
}
</style>
