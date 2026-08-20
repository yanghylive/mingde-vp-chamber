<template>
  <view class="membership-page">
    <!-- 当前会籍卡 -->
    <view class="tier-card glass-dark">
      <view class="tc-ring" />
      <view class="tc-row">
        <view class="tc-avatar">{{ avatarText }}</view>
        <view class="tc-info">
          <view class="tc-name-row">
            <text class="tc-name">{{ displayName }}</text>
            <view class="tc-badge">
              <image class="ic ic-xs" src="/static/icons/ic-crown-gold.png" mode="aspectFit" />
              <text>{{ currentTier.short }}</text>
            </view>
          </view>
          <text class="tc-sub">{{ currentTier.name }}{{ expiresText }}</text>
        </view>
        <view class="tc-level">
          <text class="tcl-num">L{{ tierNum }}</text>
          <text class="tcl-label">当前等级</text>
        </view>
      </view>
      <view class="tc-tip">
        <image class="ic ic-sm tct-icon" src="/static/icons/ic-shield-check-gold.png" mode="aspectFit" />
        <text class="tct-text">{{ currentTier.short }} 已解锁 {{ currentTier.rights.length }} 项专属权益，持续精进解锁更高等级。</text>
      </view>
    </view>

    <!-- 等级权益 -->
    <view class="sec-head">
      <view class="sh-row">
        <view class="sh-icon"><image class="ic ic-sm" src="/static/icons/ic-medal-gold.png" mode="aspectFit" /></view>
        <text class="sh-title">等级权益</text>
      </view>
    </view>
    <view class="ladder-list">
      <view
        v-for="t in ladder"
        :key="t.tier"
        class="{{'ladder-item card' + (t.tier === tierNum ? ' ladder-item-current' : '')}}"
      >
        <view class="li-head" @tap="expanded = expanded === t.tier ? null : t.tier">
          <view class="{{'li-dot dot-' + t.tier}}">{{ t.short }}</view>
          <view class="li-info">
            <view class="li-name-row">
              <text class="li-name">{{ t.name }}</text>
              <text v-if="t.tier === tierNum" class="li-current">当前等级</text>
            </view>
            <text class="li-tagline">{{ t.tagline }}</text>
          </view>
          <view class="li-count">
            <text>{{ t.rights.length }} 项</text>
            <view class="{{'ic ic-xs ic-chevron-down-gray li-chevron' + (expanded === t.tier ? ' li-chevron-open' : '')}}" />
          </view>
        </view>
        <view v-if="expanded === t.tier" class="li-rights">
          <view v-for="r in t.rights" :key="r" class="li-right">
            <view class="lir-check"><image class="ic ic-xs" src="/static/icons/ic-check-green.png" mode="aspectFit" /></view>
            <text class="lir-text">{{ r }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- 升级路径 -->
    <view class="upgrade card">
      <view class="up-icon"><image class="ic ic-md" src="/static/icons/ic-crown-blue.png" mode="aspectFit" /></view>
      <view class="up-info">
        <text class="up-title">升级路径</text>
        <text class="up-sub">持续参与活动、贡献与学习，逐级解锁更丰富权益。</text>
      </view>
      <view class="up-btn" @tap="goMine">去我的</view>
    </view>

    <!-- 开通会员 -->
    <block v-if="plans.length > 0">
      <view class="sec-head">
        <text class="sh-title">开通会员</text>
        <text class="sh-note">年费制 · 到期可续费</text>
      </view>
      <view class="plans">
        <view
          v-for="plan in plans"
          :key="plan.code"
          class="{{'plan-card card' + (planTierNum(plan) === 3 ? ' plan-card-hot' : '')}}"
        >
          <view class="plan-head">
            <text class="plan-name">{{ plan.name }}</text>
            <text v-if="planTierNum(plan) === 3" class="plan-hot">推荐</text>
          </view>
          <view class="plan-price">
            <text class="pp-symbol">¥</text>
            <text class="pp-num">{{ priceNum(plan.price) }}</text>
            <text class="pp-term">/年</text>
          </view>
          <view class="plan-rights">
            <view v-for="b in planBenefits(plan)" :key="b" class="pr-item">
              <view class="pr-check" />
              <text>{{ b }}</text>
            </view>
          </view>
          <view v-if="!VIRTUAL_PAY_DISABLED" class="{{'plan-buy' + (planTierNum(plan) <= tierNum ? ' plan-buy-owned' : '')}}" @tap="onBuy(plan)">
            {{ planTierNum(plan) <= tierNum ? '当前等级' : '开通 ' + plan.name + '（¥' + priceNum(plan.price) + '/年）' }}
          </view>
          <view v-else class="plan-buy plan-buy-owned">即将开放</view>
        </view>
      </view>
    </block>

    <view class="notice">支付通道整改中，开通功能即将开放，敬请期待</view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { requestWechatPayment, pollWechatPayStatus } from '@/common/pay'
import { requestVirtualPayment } from '@/common/vpay'
import { checkLogin } from '@/libs/login'
import { TIERS, tierToNumber, applyTierConfig } from '@/common/tier'
import { toDate } from '@/common/format'
import { fetchSiteConfig } from '@/common/site-config'
import { VIRTUAL_PAY_DISABLED } from '@/config/app'

export default {
  data() {
    return {
      VIRTUAL_PAY_DISABLED: VIRTUAL_PAY_DISABLED,
      profile: null,
      membership: null,
      plans: [],
      expanded: null,
      ladder: TIERS,
      tierNum: 1
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
    expiresText() {
      const ts = this.membership && this.membership.tier_expires_at
      return ts ? ' · 有效期至 ' + toDate(ts) : ''
    }
  },
  onLoad() {
    // 游客可浏览会籍计划，购买时才要求登录（微信审核：不得强制登录才能体验）
    this.loadData()
  },
  methods: {
    async loadData() {
      fetchSiteConfig().then((cfg) => {
        if (cfg) this.ladder = applyTierConfig(cfg)
      })
      const logged = checkLogin()
      const jobs = [chamber.membershipPlans()]
      if (logged) jobs.push(chamber.meProfile(), chamber.meMembership())
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') {
        this.plans = (results[0].value || []).filter((p) => p.eligible !== false)
      }
      if (logged && results[1].status === 'fulfilled') this.profile = results[1].value
      if (logged && results[2].status === 'fulfilled') {
        this.membership = results[2].value
        this.tierNum = tierToNumber(this.membership && this.membership.effective_tier, 1)
      }
    },
    planTierNum(plan) {
      return Number(String(plan.tier).replace(/\D/g, '')) || 0
    },
    priceNum(price) {
      const n = Number(price || 0)
      return n.toFixed(2)
    },
    planBenefits(plan) {
      // 对齐 H5：显示前 3 条
      if (plan && Array.isArray(plan.benefits) && plan.benefits.length > 0) return plan.benefits.slice(0, 3)
      if (plan && Array.isArray(plan.benefits_list) && plan.benefits_list.length > 0) return plan.benefits_list.slice(0, 3)
      const t = this.ladder.find((x) => x.tier === this.planTierNum(plan))
      return t ? t.rights.slice(0, 3) : []
    },
    onBuy(plan) {
      if (!checkLogin()) {
        uni.navigateTo({ url: '/pages/login/index' })
        return
      }
      const pt = this.planTierNum(plan)
      if (pt <= this.tierNum) return
      if (plan.eligible === false) {
        uni.showModal({
          title: plan.name,
          content: plan.ineligible_reason || '当前暂不可购买',
          confirmText: '知道了',
          showCancel: false
        })
        return
      }
      // 创建会员开通订单（幂等）→ 微信支付（APIv3 直连，与 3010 ai-content 同一套逻辑）
      uni.showLoading({ title: '创建订单...', mask: true })
      chamber
        .membershipCheckout({
          plan_code: plan.code,
          plan_version: Number(plan.version) || 1,
          expected_amount: String(plan.price),
          currency: plan.currency || 'CNY'
        })
        .then(async (res) => {
          uni.hideLoading()
          const orderNo = (res && res.order_no) || ''
          if (!orderNo) {
            uni.showToast({ title: '订单创建异常，请稍后重试', icon: 'none' })
            return
          }
          // 拉取虚拟支付单（Midas）→ wx.requestVirtualPayment → 回调确认 → 轮询到 paid
          const payRes = await chamber.vpayCreateOrder({
            business_type: 'membership',
            order_no: orderNo,
            idempotency_key: 'vpay:' + orderNo,
            plan_tier: this.planTierNum(plan)
          }).catch(() => null)
          if (!payRes || !payRes.signData) {
            uni.showToast({ title: (payRes && payRes.message) || '虚拟支付未配置完成，暂不可用', icon: 'none' })
            return
          }
          uni.showLoading({ title: '拉起支付...', mask: true })
          const payResult = await requestVirtualPayment(payRes)
          uni.hideLoading()
          if (payResult.status === 'paid') {
            uni.showToast({ title: '支付成功，权益已开通', icon: 'success' })
            this.loadPlans()
            return
          }
          if (payResult.status === 'cancelled') {
            uni.showToast({ title: '已取消支付', icon: 'none' })
            return
          }
          // 可能已支付但回调未到：轮询确认
          uni.showLoading({ title: '确认支付结果...', mask: true })
          const polled = await pollWechatPayStatus(payRes.out_trade_no)
          uni.hideLoading()
          if (polled.status === 'paid') {
            uni.showToast({ title: '支付成功，权益已开通', icon: 'success' })
            this.loadPlans()
          } else {
            uni.showToast({ title: '支付未完成，可在订单中继续', icon: 'none' })
          }
        })
        .catch((error) => {
          uni.hideLoading()
          uni.showToast({ title: (error && error.msg) || '创建订单失败', icon: 'none' })
        })
    },
    goMine() {
      uni.switchTab({ url: '/pages/mine/index' })
    }
  }
}
</script>

<style lang="scss">
.membership-page {
  padding-top: env(safe-area-inset-top);
  padding: 24rpx 32rpx 60rpx;
  min-height: 100vh;
}

/* 会籍卡 */
.tier-card {
  position: relative;
  overflow: hidden;
  border-radius: 44rpx;
  padding: 40rpx 36rpx;
  color: #fff;
}
.tc-ring {
  position: absolute;
  top: -56rpx;
  right: -40rpx;
  width: 256rpx;
  height: 256rpx;
  border-radius: 50%;
  border: 1rpx solid rgba(243, 188, 106, 0.2);
}
.tc-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 24rpx;
}
.tc-avatar {
  width: 112rpx;
  height: 112rpx;
  border-radius: 50%;
  border: 4rpx solid #f2bd6b;
  background: linear-gradient(135deg, #d99b49, #81531f);
  color: #fff;
  font-size: 40rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tc-info {
  flex: 1;
  min-width: 0;
}
.tc-name-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
}
.tc-name {
  font-size: 34rpx;
  font-weight: 600;
}
.tc-badge {
  display: flex;
  align-items: center;
  gap: 4rpx;
  background: #f0b35b;
  color: #173253;
  font-size: 20rpx;
  font-weight: 700;
  padding: 4rpx 12rpx;
  border-radius: 8rpx;
}
.tc-badge-crown {
  font-size: 18rpx;
}
.tc-sub {
  display: block;
  font-size: 20rpx;
  color: rgba(255, 255, 255, 0.65);
  margin-top: 8rpx;
}
.tc-level {
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 24rpx;
  padding: 16rpx 24rpx;
  text-align: center;
  -webkit-backdrop-filter: blur(10px);
  backdrop-filter: blur(10px);
}
.tcl-num {
  display: block;
  font-size: 34rpx;
  font-weight: 700;
  color: #f5c276;
}
.tcl-label {
  display: block;
  font-size: 16rpx;
  color: rgba(255, 255, 255, 0.6);
  margin-top: 2rpx;
}
.tc-tip {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 12rpx;
  margin-top: 28rpx;
  padding: 20rpx 24rpx;
  border-radius: 28rpx;
  background: rgba(255, 255, 255, 0.1);
  -webkit-backdrop-filter: blur(10px);
  backdrop-filter: blur(10px);
}
.tct-icon {
  font-size: 26rpx;
  color: #f5c276;
  flex-shrink: 0;
}
.tct-text {
  font-size: 20rpx;
  color: rgba(255, 255, 255, 0.8);
  line-height: 1.6;
}

/* 节标题 */
.sec-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
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
  font-size: 34rpx;
  font-weight: 700;
  color: #17325b;
}
.sh-note {
  font-size: 20rpx;
  color: #8a94a3;
}

/* 等级权益 */
.ladder-list {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.ladder-item {
  overflow: hidden;
}
.ladder-item-current {
  border: 2rpx solid #d98a2d;
}
.li-head {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 28rpx;
}
.li-dot {
  width: 80rpx;
  height: 80rpx;
  border-radius: 50%;
  color: #fff;
  font-size: 24rpx;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.dot-1 { background: linear-gradient(135deg, #8a94a3, #6b7686); }
.dot-2 { background: linear-gradient(135deg, #d98a2d, #b8751d); }
.dot-3 { background: linear-gradient(135deg, #c87922, #a05c15); }
.dot-4 { background: linear-gradient(135deg, #173c69, #0d2549); }
.li-info {
  flex: 1;
  min-width: 0;
}
.li-name-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
}
.li-name {
  font-size: 28rpx;
  font-weight: 700;
  color: #24395a;
}
.li-current {
  font-size: 18rpx;
  background: #fff2df;
  color: #a8691f;
  padding: 2rpx 12rpx;
  border-radius: 8rpx;
}
.li-tagline {
  font-size: 20rpx;
  color: #8a94a3;
  display: block;
  margin-top: 4rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.li-count {
  display: flex;
  align-items: center;
  gap: 8rpx;
  font-size: 22rpx;
  font-weight: 700;
  color: #a9651e;
  flex-shrink: 0;
}
.li-chevron {
  font-size: 24rpx;
  transition: transform 0.2s;
}
.li-chevron-open {
  transform: rotate(180deg);
}
.li-rights {
  border-top: 1rpx solid #eef1f5;
  padding: 20rpx 28rpx;
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.li-right {
  display: flex;
  align-items: center;
  gap: 12rpx;
  font-size: 24rpx;
  color: #4a5b72;
}
.lir-check {
  width: 36rpx;
  height: 36rpx;
  border-radius: 50%;
  background: #e9f3ef;
  color: #3f715f;
  font-size: 18rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* 升级路径 */
.upgrade {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 28rpx;
  margin-top: 28rpx;
  background: linear-gradient(135deg, #fffaf2, #fff);
  border: 1rpx solid #f0ddc2;
}
.up-icon {
  width: 88rpx;
  height: 88rpx;
  border-radius: 32rpx;
  background: #e9f0fb;
  color: #28517f;
  font-size: 30rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.up-info {
  flex: 1;
  min-width: 0;
}
.up-title {
  font-size: 28rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
}
.up-sub {
  font-size: 20rpx;
  color: #8a94a3;
  display: block;
  margin-top: 6rpx;
  line-height: 1.5;
}
.up-btn {
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.58);
  color: #15305b;
  border: 1rpx solid rgba(185, 201, 218, 0.4);
  font-size: 24rpx;
  font-weight: 600;
  padding: 14rpx 32rpx;
  border-radius: 16rpx;
}

/* 开通会员 */
.plans {
  display: flex;
  gap: 20rpx;
}
.plan-card {
  flex: 1;
  padding: 32rpx 28rpx;
  border: 2rpx solid #d9e2f0;
}
.plan-card-hot {
  border-color: #f0c27a;
}
.plan-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.plan-name {
  font-size: 28rpx;
  font-weight: 700;
  color: #24395a;
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
  font-size: 24rpx;
  color: #c57620;
  font-weight: 700;
}
.pp-num {
  font-size: 56rpx;
  font-weight: 800;
  color: #c57620;
}
.pp-term {
  font-size: 20rpx;
  color: #8a94a3;
  margin-left: 6rpx;
}
.plan-rights {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
  min-height: 160rpx;
}
.pr-item {
  display: flex;
  align-items: center;
  gap: 10rpx;
  font-size: 20rpx;
  color: #5c6b80;
}
.pr-check {
  width: 24rpx;
  height: 24rpx;
  position: relative;
  flex-shrink: 0;
  margin-top: 4rpx;
}
.pr-check::after {
  content: '';
  position: absolute;
  left: 4rpx;
  top: 2rpx;
  width: 12rpx;
  height: 6rpx;
  border-left: 3rpx solid #d18a35;
  border-bottom: 3rpx solid #d18a35;
  transform: rotate(-45deg);
}
.plan-buy {
  margin-top: 20rpx;
  text-align: center;
  padding: 16rpx 0;
  border-radius: 24rpx;
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  font-size: 24rpx;
  font-weight: 600;
  box-shadow: 0 10rpx 24rpx rgba(185, 110, 29, 0.2);
}
.plan-buy-owned {
  background: #f1ede4;
  color: #516580;
  box-shadow: none;
}
.notice {
  text-align: center;
  font-size: 20rpx;
  color: #c0c6d0;
  padding: 40rpx 0 20rpx;
}
</style>
