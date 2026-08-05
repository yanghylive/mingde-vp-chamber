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
          <view class="en-head">
          <text class="en-reason">{{ reasonText(item) }}</text>
          <text v-if="item.balance_after != null" class="en-balance">余额 {{ item.balance_after }}</text>
        </view>
          <text class="en-time">{{ timeText(item.created_at) }}</text>
        </view>
        <text class="{{'en-points' + ((item.points || 0) >= 0 ? ' en-plus' : ' en-minus')}}">
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
    reasonText(item) {
      const typeMap = { checkin: '签到', exchange: '兑换', distribution: '分销', charity: '公益', study: '学习', reward: '奖励', admin: '系统调整' }
      const key = String(item.type || item.source || '').toLowerCase()
      return typeMap[key] || item.reason || item.type || '积分变动'
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
.en-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
}
.en-balance {
  font-size: 20rpx;
  color: #9aa3b0;
  flex-shrink: 0;
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
