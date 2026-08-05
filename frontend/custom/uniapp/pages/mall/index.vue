<template>
  <view class="mall-page">
    <view class="points-bar card">
      <view class="pb-left">
        <text class="pb-label">我的积分</text>
        <text class="pb-num gold">{{ points }}</text>
      </view>
      <view class="pb-right">
        <text class="pb-link" @tap="goPointsPaths">积分获取 ›</text>
        <text class="pb-link" @tap="goLedger">明细 ›</text>
      </view>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="list.length === 0" class="empty">暂无商品</view>
    <view v-else class="grid">
      <view v-for="p in list" :key="p.id" class="product card" @tap="openConfirm(p)">
        <view class="p-img">
          <text>{{ (p.store_name || '商品').slice(0, 1) }}</text>
        </view>
        <text class="p-name">{{ p.store_name }}</text>
        <view class="p-price-row">
          <text class="p-points">{{ p.integral_price || p.points_price || 0 }} 积分</text>
          <text v-if="p.price > 0" class="p-cash">+ ¥{{ cashOf(p) }}</text>
        </view>
      </view>
    </view>

    <!-- 兑换确认弹窗 -->
    <view v-if="confirmTarget" class="modal-mask" @tap="confirmTarget = null">
      <view class="modal" @tap.stop>
        <view class="modal-title">兑换商品</view>
        <view class="modal-product">{{ confirmTarget.store_name }}</view>
        <view class="modal-cost">
          需积分 {{ needPoints }}<text v-if="needCash > 0"> · 补差价 {{ formatMoney(needCash) }}</text>
        </view>
        <view v-if="needCash > 0" class="modal-short">
          当前余额 {{ points }} / 需 {{ needPoints }}
        </view>
        <view class="modal-btns">
          <view class="btn-secondary mbtn" @tap="confirmTarget = null">取消</view>
          <view
            :class="['btn-primary', 'mbtn', exchanging && 'mbtn-disabled']"
            @tap="handleConfirm"
          >
            {{ exchanging ? '兑换中…' : needCash > 0 ? `混合支付 · 积分 + ${formatMoney(needCash)}` : '积分支付' }}
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { formatMoney } from '@/common/format'
import { fetchSiteConfig } from '@/common/site-config'

export default {
  data() {
    return {
      list: [],
      points: 0,
      loading: true,
      confirmTarget: null,
      exchanging: false,
      pointsToYuan: 10
    }
  },
  computed: {
    needPoints() {
      return Number((this.confirmTarget && (this.confirmTarget.integral_price || this.confirmTarget.points_price)) || 0)
    },
    needCash() {
      const short = Math.max(0, this.needPoints - this.points)
      return Math.ceil(short / (this.pointsToYuan || 10))
    }
  },
  onShow() {
    this.loadData()
  },
  methods: {
    async loadData() {
      fetchSiteConfig().then((cfg) => {
        if (cfg && cfg.points_ratio && Number(cfg.points_ratio) > 0) {
          this.pointsToYuan = Number(cfg.points_ratio)
        }
      })
      const jobs = [chamber.products(), chamber.points()]
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') this.list = results[0].value || []
      if (results[1].status === 'fulfilled') this.points = results[1].value
      this.loading = false
    },
    cashOf(p) {
      const n = Number(p.price || 0)
      return Number.isInteger(n) ? String(n) : n.toFixed(2)
    },
    openConfirm(p) {
      if (!checkLogin()) {
        uni.navigateTo({ url: '/pages/login/index' })
        return
      }
      this.confirmTarget = p
    },
    async handleConfirm() {
      if (!this.confirmTarget || this.exchanging) return
      this.exchanging = true
      try {
        const order = await chamber.exchangeProduct(
          this.confirmTarget.id,
          Math.min(this.needPoints, this.points),
          this.needCash > 0 ? this.needCash.toFixed(2) : '0.00'
        )
        this.confirmTarget = null
        uni.showToast({ title: '兑换成功', icon: 'success' })
        setTimeout(() => {
          uni.navigateTo({ url: '/pages/mall/exchange-success?id=' + this.confirmTarget.id })
        }, 600)
      } catch (e) {
      } finally {
        this.exchanging = false
      }
    },
    goPointsPaths() {
      uni.navigateTo({ url: '/pages/mall/points-paths' })
    },
    goLedger() {
      uni.navigateTo({ url: '/pages/mine/points-ledger' })
    }
  }
}
</script>

<style scoped lang="scss">
.mall-page {
  padding: 24rpx 32rpx 60rpx;
}
.points-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 28rpx 32rpx;
  margin-bottom: 24rpx;
}
.pb-left {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
}
.pb-label {
  font-size: 24rpx;
  color: #8a94a3;
}
.pb-num {
  font-size: 40rpx;
  font-weight: 800;
  color: #b8751d;
}
.pb-right {
  display: flex;
  gap: 20rpx;
}
.pb-link {
  font-size: 24rpx;
  color: #ad6b22;
}
.empty {
  text-align: center;
  padding: 100rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
.grid {
  display: flex;
  flex-wrap: wrap;
  gap: 20rpx;
}
.product {
  width: calc(50% - 10rpx);
  padding: 24rpx;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}
.p-img {
  height: 200rpx;
  border-radius: 16rpx;
  background: linear-gradient(135deg, #fff0dc, #f6e2c2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 64rpx;
  color: #b8751d;
  font-weight: 700;
}
.p-name {
  font-size: 26rpx;
  color: #273b59;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.p-price-row {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
}
.p-points {
  font-size: 26rpx;
  font-weight: 800;
  color: #b8751d;
}
.p-cash {
  font-size: 22rpx;
  color: #8a94a3;
}
.modal-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 99;
}
.modal {
  width: 600rpx;
  background: #fff;
  border-radius: 28rpx;
  padding: 40rpx 36rpx;
}
.modal-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #273b59;
  text-align: center;
}
.modal-product {
  text-align: center;
  font-size: 28rpx;
  color: #516580;
  margin-top: 20rpx;
}
.modal-cost {
  text-align: center;
  font-size: 28rpx;
  font-weight: 700;
  color: #b8751d;
  margin-top: 24rpx;
}
.modal-short {
  text-align: center;
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 12rpx;
}
.modal-btns {
  display: flex;
  gap: 20rpx;
  margin-top: 36rpx;
}
.mbtn {
  flex: 1;
  font-size: 28rpx;
  padding: 20rpx 0;
}
.mbtn-disabled {
  opacity: 0.6;
}
</style>
