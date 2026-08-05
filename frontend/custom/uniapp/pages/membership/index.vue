<template>
  <view class="membership-page">
    <!-- 当前等级卡 -->
    <view class="tier-card glass-dark">
      <view class="tc-top">
        <view>
          <view class="tc-name">{{ currentTier.name }}</view>
          <view class="tc-tagline">{{ currentTier.tagline }}</view>
        </view>
        <view class="tc-badge">{{ currentTier.short }}</view>
      </view>
      <view v-if="membership && membership.tier_expires_at" class="tc-expire">
        有效期至 {{ expiresText }}
      </view>
      <view class="tc-rights">
        <view v-for="r in currentTier.rights" :key="r" class="tc-right">
          <text class="tr-check">✓</text>
          <text>{{ r }}</text>
        </view>
      </view>
    </view>

    <!-- 开通会员（购买卡片） -->
    <view class="section-head">
      <text class="section-title">开通会员</text>
      <text class="section-sub">解锁更多权益</text>
    </view>
    <view v-if="plansLoading" class="empty">加载中…</view>
    <view v-else-if="plans.length === 0" class="empty">暂无可用套餐</view>
    <view v-else class="plans">
      <view v-for="p in plans" :key="p.code" :class="['plan-card', p.tier === 3 && 'plan-card-hot']">
        <view class="plan-head">
          <text class="plan-name">{{ p.name }}</text>
          <view v-if="p.tier === 3" class="plan-hot">推荐</view>
        </view>
        <view class="plan-price">
          <text class="pp-symbol">¥</text>
          <text class="pp-num">{{ priceNum(p.price) }}</text>
          <text class="pp-term">/年</text>
        </view>
        <view class="plan-rights">
          <view v-for="b in planBenefits(p)" :key="b" class="pr-item">
            <text class="pr-check">✓</text>
            <text>{{ b }}</text>
          </view>
        </view>
        <view class="plan-buy" @tap="buyPlan(p)">
          {{ p.tier === tierNum ? '当前等级' : '立即开通' }}
        </view>
      </view>
    </view>

    <!-- 全量等级阶梯 -->
    <view class="section-head" style="margin-top: 40rpx">
      <text class="section-title">会员等级</text>
    </view>
    <view v-for="t in ladder" :key="t.tier" :class="['ladder-card', t.tier === tierNum && 'ladder-card-current']">
      <view class="lc-head">
        <view class="lc-badge">{{ t.short }}</view>
        <view class="lc-name">{{ t.name }}</view>
        <view v-if="t.tier === tierNum" class="lc-current">当前</view>
      </view>
      <view class="lc-rights">
        <view v-for="r in t.rights" :key="r" class="lc-right">
          <text class="lc-check">✓</text>
          <text>{{ r }}</text>
        </view>
      </view>
    </view>

    <view class="notice">支付通道开通中，开通后自动升级会员等级</view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { TIERS, tierToNumber, applyTierConfig } from '@/common/tier'
import { toDate } from '@/common/format'
import { fetchSiteConfig } from '@/common/site-config'

export default {
  data() {
    return {
      membership: null,
      plans: [],
      plansLoading: true,
      ladder: TIERS,
      tierNum: 1
    }
  },
  computed: {
    currentTier() {
      return this.ladder.find((t) => t.tier === this.tierNum) || this.ladder[0]
    },
    expiresText() {
      const ts = this.membership && this.membership.tier_expires_at
      return ts ? toDate(ts) : ''
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
      fetchSiteConfig().then((cfg) => {
        if (cfg) this.ladder = applyTierConfig(cfg)
      })
      const jobs = [chamber.meMembership(), chamber.membershipPlans()]
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') {
        this.membership = results[0].value
        this.tierNum = tierToNumber(this.membership && this.membership.effective_tier, 1)
      }
      if (results[1].status === 'fulfilled') {
        this.plans = (results[1].value || []).filter((p) => p.eligible !== false)
      }
      this.plansLoading = false
    },
    priceNum(price) {
      const n = Number(price || 0)
      return Number.isInteger(n) ? String(n) : n.toFixed(2)
    },
    planBenefits(p) {
      if (p && Array.isArray(p.benefits) && p.benefits.length > 0) return p.benefits
      if (p && Array.isArray(p.benefits_list) && p.benefits_list.length > 0) return p.benefits_list
      const t = this.ladder.find((x) => x.tier === tierToNumber(p && p.tier))
      return t ? t.rights : []
    },
    buyPlan(p) {
      if (p.tier === this.tierNum) return
      uni.showModal({
        title: p.name,
        content: `支付通道开通中。开通后自动升级为 ${p.name}（${priceText(p)}/年）。`,
        confirmText: '知道了',
        showCancel: false
      })
    }
  }
}

function priceText(p) {
  const n = Number((p && p.price) || 0)
  return Number.isInteger(n) ? '¥' + n : '¥' + n.toFixed(2)
}
</script>

<style lang="scss">
.membership-page {
  padding: 32rpx 32rpx 60rpx;
}
.tier-card {
  border-radius: 28rpx;
  padding: 40rpx 36rpx;
  
  color: #fff;
  box-shadow: 0 12rpx 32rpx rgba(39, 59, 89, 0.25);
}
.tc-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.tc-name {
  font-size: 40rpx;
  font-weight: 800;
}
.tc-tagline {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.65);
  margin-top: 8rpx;
}
.tc-badge {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  font-size: 30rpx;
  font-weight: 800;
  padding: 10rpx 24rpx;
  border-radius: 999rpx;
}
.tc-expire {
  margin-top: 20rpx;
  font-size: 22rpx;
  color: #ffd78f;
}
.tc-rights {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
  margin-top: 28rpx;
}
.tc-right {
  display: flex;
  align-items: center;
  gap: 8rpx;
  background: rgba(255, 255, 255, 0.08);
  border-radius: 999rpx;
  padding: 10rpx 20rpx;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.9);
}
.tr-check {
  color: #ffd78f;
  font-weight: 700;
}
.section-head {
  display: flex;
  align-items: baseline;
  gap: 16rpx;
  margin: 36rpx 0 20rpx;
}
.section-title {
  font-size: 34rpx;
  font-weight: 700;
  color: #273b59;
}
.section-sub {
  font-size: 22rpx;
  color: #8a94a3;
}
.empty {
  text-align: center;
  padding: 60rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.plans {
  display: flex;
  gap: 20rpx;
}
.plan-card {
  flex: 1;
  background: #fff;
  border-radius: 28rpx;
  padding: 32rpx;
  border: 2rpx solid #f0ddc2;
  box-shadow: 0 8rpx 24rpx rgba(39, 59, 89, 0.05);
}
.plan-card-hot {
  border: 2rpx solid #b8751d;
  background: linear-gradient(180deg, #fffaf2, #fff);
}
.plan-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.plan-name {
  font-size: 30rpx;
  font-weight: 700;
  color: #273b59;
}
.plan-hot {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 18rpx;
  padding: 4rpx 12rpx;
  border-radius: 999rpx;
}
.plan-price {
  display: flex;
  align-items: baseline;
  margin: 20rpx 0;
}
.pp-symbol {
  font-size: 26rpx;
  color: #b8751d;
  font-weight: 700;
}
.pp-num {
  font-size: 56rpx;
  font-weight: 800;
  color: #b8751d;
}
.pp-term {
  font-size: 22rpx;
  color: #8a94a3;
  margin-left: 6rpx;
}
.plan-rights {
  display: flex;
  flex-direction: column;
  gap: 10rpx;
  min-height: 150rpx;
}
.pr-item {
  display: flex;
  align-items: center;
  gap: 8rpx;
  font-size: 22rpx;
  color: #516580;
}
.pr-check {
  color: #b8751d;
  font-weight: 700;
}
.plan-buy {
  margin-top: 20rpx;
  text-align: center;
  padding: 18rpx 0;
  border-radius: 999rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 26rpx;
  font-weight: 600;
}
.plan-card:first-child .plan-buy {
  background: #f1ede4;
  color: #516580;
}
.ladder-card {
  background: #fff;
  border-radius: 24rpx;
  padding: 28rpx 32rpx;
  margin-bottom: 20rpx;
  border: 2rpx solid transparent;
}
.ladder-card-current {
  border-color: #b8751d;
}
.lc-head {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
.lc-badge {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 20rpx;
  font-weight: 700;
  padding: 6rpx 14rpx;
  border-radius: 999rpx;
}
.lc-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
}
.lc-current {
  margin-left: auto;
  font-size: 20rpx;
  color: #b8751d;
  font-weight: 600;
}
.lc-rights {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 16rpx;
}
.lc-right {
  display: flex;
  align-items: center;
  gap: 6rpx;
  font-size: 22rpx;
  color: #516580;
  background: #f7f5f0;
  padding: 8rpx 16rpx;
  border-radius: 999rpx;
}
.lc-check {
  color: #b8751d;
}
.notice {
  text-align: center;
  font-size: 22rpx;
  color: #c0c6d0;
  padding: 40rpx 0 20rpx;
}
</style>
