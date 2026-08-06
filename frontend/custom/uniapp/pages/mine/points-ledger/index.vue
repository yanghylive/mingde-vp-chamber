<template>
  <view class="ledger-page">
    <page-header title="积分记录" eyebrow="获取与消费明细" />
    <view class="balance glass-dark">
      <view class="bal-deco" />
      <view class="bal-left">
        <view class="bal-label-row">
          <image class="ic ic-sm" src="/static/icons/ic-coins-gold.png" mode="aspectFit" />
          <text class="bal-label">当前积分余额</text>
        </view>
        <text class="bal-num">{{ balance != null ? formatPoints(balance) : '—' }}</text>
      </view>
      <view class="bal-right">
        <image class="ic ic-lg" src="/static/icons/ic-history-gold.png" mode="aspectFit" />
      </view>
    </view>

    <view class="section-title">
      <image class="ic ic-sm" src="/static/icons/ic-coins-gold.png" mode="aspectFit" />
      <view class="st-wrap">
        <text class="st-text">积分流水</text>
        <text class="st-eyebrow">每一分的来龙去脉</text>
      </view>
    </view>

    <view v-if="loading" class="empty">流水加载中…</view>
    <view v-else-if="list.length === 0" class="empty">暂无积分记录</view>
    <view v-else class="card ledger-list">
      <view v-for="(item, idx) in list" :key="item.id" class="{{'entry' + (idx > 0 ? ' entry-bd' : '')}}">
        <view class="{{'en-icon ' + (isEarn(item) ? 'en-earn' : 'en-spend')}}">
          <text>{{ isEarn(item) ? '+' : '-' }}</text>
        </view>
        <view class="en-info">
          <text class="en-reason">{{ reasonText(item) }}</text>
          <text class="en-meta">{{ sourceLabel(item) }} · {{ timeText(item.created_at) }}</text>
        </view>
        <view class="en-right">
          <text class="{{'en-points ' + (isEarn(item) ? 'en-plus' : 'en-minus')}}">
            {{ isEarn(item) ? '+' : '' }}{{ formatPoints(getDelta(item)) }}
          </text>
          <text v-if="item.balance_after != null" class="en-balance">余额 {{ formatPoints(item.balance_after) }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { toDate, formatPoints } from '@/common/format'

export default {
  components: { PageHeader },
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
    formatPoints(v) {
      return formatPoints(v)
    },
    async loadData() {
      var results = await Promise.allSettled([chamber.points(), chamber.mePointsLedger()])
      if (results[0].status === 'fulfilled') this.balance = results[0].value
      if (results[1].status === 'fulfilled') this.list = results[1].value || []
      this.loading = false
    },
    isEarn(item) {
      var d = this.getDelta(item)
      return d >= 0
    },
    getDelta(item) {
      return Number(item.delta != null ? item.delta : item.points || 0)
    },
    reasonText(item) {
      return item.desc || this.sourceLabel(item)
    },
    sourceLabel(item) {
      var typeMap = {
        checkin: '签到', exchange: '兑换', distribution: '分销', charity: '公益',
        coach: '做教练', roadshow: '路演', study: '学习', reward: '奖励',
        admin: '管理'
      }
      var key = String(item.source_type || item.type || item.source || '').toLowerCase()
      return typeMap[key] || item.reason || key || '积分变动'
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

/* Balance card */
.balance {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 40rpx;
  border-radius: 36rpx;
  margin-bottom: 32rpx;
}
.bal-deco {
  position: absolute;
  right: -64rpx;
  top: -80rpx;
  width: 288rpx;
  height: 288rpx;
  border-radius: 50%;
  border: 1rpx solid rgba(243, 188, 106, 0.2);
}
.bal-left {
  position: relative;
  z-index: 2;
}
.bal-label-row {
  display: flex;
  align-items: center;
  gap: 8rpx;
}
.bal-label {
  font-size: 24rpx;
  color: rgba(191, 219, 254, 0.7);
}
.bal-num {
  display: block;
  font-size: 60rpx;
  font-weight: 700;
  color: #f5c276;
  margin-top: 8rpx;
}
.bal-right {
  position: relative;
  z-index: 2;
  flex-shrink: 0;
}

/* Section title */
.section-title {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-bottom: 20rpx;
}
.st-wrap {
  display: flex;
  flex-direction: column;
}
.st-text {
  font-size: 28rpx;
  font-weight: 700;
  color: #17325b;
}
.st-eyebrow {
  font-size: 20rpx;
  color: #8a94a3;
}

.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}

/* Ledger list inside single card */
.ledger-list {
  padding: 0 32rpx;
}
.entry {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 28rpx 0;
}
.entry-bd {
  border-top: 1rpx solid #edf0f4;
}
.en-icon {
  width: 80rpx;
  height: 80rpx;
  border-radius: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 36rpx;
  font-weight: 700;
  flex-shrink: 0;
}
.en-earn {
  background: #e9f3ef;
  color: #3f715f;
}
.en-spend {
  background: #f6ecee;
  color: #a05a62;
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
  color: #17325b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.en-meta {
  font-size: 20rpx;
  color: #969fad;
}
.en-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  flex-shrink: 0;
}
.en-points {
  font-size: 28rpx;
  font-weight: 700;
}
.en-plus {
  color: #3f715f;
}
.en-minus {
  color: #a05a62;
}
.en-balance {
  font-size: 20rpx;
  color: #969fad;
  margin-top: 4rpx;
}
</style>
