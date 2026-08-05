<template>
  <view class="friends-page">
    <!-- 筛选 chips -->
    <view class="chips">
      <view
        v-for="(t, i) in tierFilters"
        :key="t"
        :class="['chip', tierFilter === t && 'chip-active']"
        @tap="tierFilter = t; applyFilter()"
      >
        {{ t }}
      </view>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="filtered.length === 0" class="empty">暂无好友</view>
    <view v-else class="list">
      <view v-for="f in filtered" :key="f.id" class="friend card">
        <view class="f-avatar">{{ (f.nickname || f.real_name || '友').slice(0, 1) }}</view>
        <view class="f-info">
          <text class="f-name">{{ f.nickname || f.real_name || '明德会员' }}</text>
          <text class="f-meta">{{ f.industry || f.region || '' }}</text>
        </view>
        <view :class="['f-status', f.status === 'accepted' ? 'f-accepted' : 'f-pending']">
          {{ f.status === 'accepted' ? '已通过' : '待确认' }}
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'

export default {
  data() {
    return {
      friends: [],
      filtered: [],
      loading: true,
      tierFilter: '全部',
      tierFilters: ['全部', 'L1', 'L2', 'L3', 'L4']
    }
  },
  onLoad() {
    if (!checkLogin()) {
      uni.navigateTo({ url: '/pages/login/index' })
      return
    }
    this.loadData()
  },
  methods: {
    async loadData() {
      try {
        this.friends = await chamber.meFriends()
      } catch (e) {}
      this.applyFilter()
      this.loading = false
    },
    applyFilter() {
      if (this.tierFilter === '全部') {
        this.filtered = this.friends
      } else {
        const tier = Number(this.tierFilter.replace('L', ''))
        this.filtered = this.friends.filter((f) => Number(f.tier) === tier)
      }
    }
  }
}
</script>

<style scoped lang="scss">
.friends-page {
  padding: 24rpx 32rpx 60rpx;
}
.chips {
  display: flex;
  gap: 16rpx;
  margin-bottom: 24rpx;
  overflow-x: auto;
  white-space: nowrap;
}
.chip {
  flex-shrink: 0;
  padding: 14rpx 30rpx;
  border-radius: 999rpx;
  background: #fff;
  color: #516580;
  font-size: 26rpx;
  box-shadow: 0 4rpx 12rpx rgba(39, 59, 89, 0.04);
}
.chip-active {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-weight: 600;
}
.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.friend {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 26rpx;
}
.f-avatar {
  width: 88rpx;
  height: 88rpx;
  border-radius: 24rpx;
  background: linear-gradient(135deg, #fff0dc, #f6e2c2);
  color: #b8751d;
  font-size: 36rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.f-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}
.f-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
}
.f-meta {
  font-size: 22rpx;
  color: #8a94a3;
}
.f-status {
  font-size: 22rpx;
  padding: 8rpx 20rpx;
  border-radius: 999rpx;
}
.f-accepted {
  color: #4c8a3f;
  background: #f0f7ec;
}
.f-pending {
  color: #c57620;
  background: #f6ead6;
}
</style>
