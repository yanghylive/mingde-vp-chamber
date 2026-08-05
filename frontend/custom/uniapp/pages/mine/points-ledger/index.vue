<template>
  <view class="ledger-page">
    <view class="balance glass-dark">
      <text class="bal-label">当前积分</text>
      <text class="bal-num">{{ balance != null ? balance : '—' }}</text>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="list.length === 0" class="empty">暂无积分记录</view>
    <view v-else class="list">
      <view v-for="item in list" :key="item.id" class="entry card">
        <view class="en-icon">{{ (item.points || 0) >= 0 ? '➕' : '➖' }}</view>
        <view class="en-info">
          <text class="en-reason">{{ item.reason || item.type || '积分变动' }}</text>
          <text class="en-time">{{ timeText(item.created_at) }}</text>
        </view>
        <text :class="['en-points', (item.points || 0) >= 0 ? 'en-plus' : 'en-minus']">
          {{ (item.points || 0) >= 0 ? '+' : '' }}{{ item.points }}
        </text>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { toDate } from '@/common/format'

export default {
  data() {
    return {
      balance: null,
      list: [],
      loading: true
    }
  },
  onLoad() {
    this.loadData()
  },
  methods: {
    async loadData() {
      const results = await Promise.allSettled([chamber.points(), chamber.mePointsLedger()])
      if (results[0].status === 'fulfilled') this.balance = results[0].value
      if (results[1].status === 'fulfilled') this.list = results[1].value || []
      this.loading = false
    },
    timeText(ts) {
      return toDate(ts, 'datetime')
    }
  }
}
</script>

<style lang="scss">
.ledger-page {
  padding: 24rpx 32rpx 60rpx;
}
.balance {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40rpx;
  
  margin-bottom: 24rpx;
}
.bal-label {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.6);
}
.bal-num {
  font-size: 64rpx;
  font-weight: 800;
  color: #ffd78f;
  margin-top: 8rpx;
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
.entry {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 26rpx;
}
.en-icon {
  font-size: 32rpx;
}
.en-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}
.en-reason {
  font-size: 26rpx;
  color: #273b59;
}
.en-time {
  font-size: 20rpx;
  color: #c0c6d0;
}
.en-points {
  font-size: 30rpx;
  font-weight: 800;
}
.en-plus {
  color: #b8751d;
}
.en-minus {
  color: #516580;
}
</style>
