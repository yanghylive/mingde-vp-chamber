<template>
  <view class="mall-page">
    <!-- 页头 -->
    <view class="ph">
      <text class="ph-title">积分商城</text>
      <text class="ph-sub">我的积分 {{ points == null ? '—' : fmtPoints(points) }}</text>
      <view class="search-box glass-control">
        <view class="ic ic-sm ic-search-gold" />
        <input v-model="keyword" class="s-input" placeholder="搜索课程 / 沙龙 / 实物" placeholder-class="ph" />
      </view>
    </view>

    <!-- 积分规则提示条 -->
    <view class="rule-bar glass-control" @tap="rulesOpen = true">
      <view class="ic ic-sm ic-info-gold" />
      <text class="rb-text">积分规则：1 元 = 10 积分，积分不足可用现金补差价</text>
      <text class="rb-arrow">></text>
    </view>

    <!-- 分类 chips -->
    <scroll-view scroll-x enable-flex class="chips">
      <view class="chips-inner">

      <view
        v-for="c in categoryOptions"
        :key="c"
        class="{{'chip glass-control' + (tab === c ? ' glass-control-active' : '')}}"
        @tap="tab = c"
      >
        {{ c }}
      </view>
    </scroll-view>

    <!-- 人气臻选 -->
    <view class="sec-head">
      <view class="sh-row">
        <view class="sh-icon"><view class="ic ic-sm ic-shopping-bag-gold" /></view>
        <view>
          <text class="sh-title">人气臻选</text>
          <text class="sh-sub">积分好礼，兑换品质生活</text>
        </view>
      </view>
    </view>

    <view v-if="loading" class="empty"><skeleton type="grid" :rows="4" /></view>
    <view v-else-if="visible.length === 0" class="empty">暂无相关商品</view>
    <view v-else class="grid">
      <view v-for="p in visible" :key="p.id" class="product card" @tap="openConfirm(p)">
        <view class="{{'p-img' + (' ' + catTone(p.category))}}">
          <view class="{{&#39;ic ic-md &#39; + catIcon(p.category)}}" />
        </view>
        <view class="p-cat">{{ normalizeCategory(p.category) }}</view>
        <text class="p-name">{{ p.name || p.store_name || '未命名商品' }}</text>
        <view class="p-price-row">
          <text class="p-points">{{ fmtPoints(p.integral_price) }}<text class="p-unit"> 积分</text></text>
          <text v-if="Number(p.price) > 0" class="p-cash">{{ fmtCash(p) }}</text>
        </view>
        <view class="p-btn" @tap.stop="openConfirm(p)">立即兑换</view>
      </view>
    </view>

    <!-- 积分获取路径 -->
    <view class="sec-head">
      <view class="sh-row">
        <view class="sh-icon"><view class="ic ic-md ic-medal-white" /></view>
        <view>
          <text class="sh-title">积分获取路径</text>
          <text class="sh-sub">贡献越多，收获越多</text>
        </view>
      </view>
      <view class="sh-link" @tap="rulesOpen = true">规则 ></view>
    </view>
    <view class="card paths-card">
      <view v-for="(p, i) in paths" :key="i" class="{{'path-row' + (i < paths.length - 1 ? ' path-row-border' : '')}}">
        <view class="pr-icon"><view class="{{'ic ic-sm ' + pathIconCls(p.icon)}}" /></view>
        <view class="pr-info">
          <text class="pr-title">{{ p.title }}</text>
          <text class="pr-desc">{{ p.desc }}</text>
        </view>
        <view class="pr-points">+{{ p.points }}<text class="pr-unit">{{ pathUnit(p.icon) }}</text></view>
      </view>
    </view>

    <!-- 兑换确认弹窗 -->
    <view v-if="confirmTarget" class="sheet-mask" @tap="confirmTarget = null">
      <view class="sheet" @tap.stop>
        <view class="sheet-bar" />
        <view class="sheet-head">
          <text class="sheet-title">兑换商品</text>
          <view class="sheet-close" @tap="confirmTarget = null">×</view>
        </view>
        <view class="sheet-product">{{ confirmTarget.name || confirmTarget.store_name }}</view>
        <view class="sheet-cost">
          {{ fmtPoints(needPoints) }} 积分 · 价值 {{ fmtCash(confirmTarget) }}
        </view>
        <view class="sheet-detail">
          <view class="sd-row">
            <text class="sd-label">我的积分余额</text>
            <text class="sd-value">{{ fmtPoints(points) }}</text>
          </view>
          <view class="sd-row">
            <text class="sd-label">本次消耗积分</text>
            <text class="sd-value">{{ fmtPoints(needPoints) }}</text>
          </view>
          <view class="sd-row">
            <text class="sd-label">差价现金</text>
            <text class="{{'sd-value' + (needCash > 0 ? ' sd-gold' : ' sd-green')}}">{{ needCash > 0 ? formatMoney(needCash) : '无需补差价' }}</text>
          </view>
        </view>
        <view v-if="needCash > 0" class="sheet-short">积分不足，可补差价 {{ formatMoney(needCash) }}（当前余额 {{ fmtPoints(points) }} / 需 {{ fmtPoints(needPoints) }}）</view>
        <view class="sheet-btns">
          <view class="btn-secondary sb" @tap="confirmTarget = null">取消</view>
          <view class="{{'btn-primary sb' + (exchanging ? ' sb-disabled' : '')}}" @tap="handleConfirm">
            {{ exchanging ? '兑换中…' : needCash > 0 ? '混合支付 · 积分 + ' + formatMoney(needCash) : '积分支付' }}
          </view>
        </view>
      </view>
    </view>

    <!-- 积分规则弹窗 -->
    <view v-if="rulesOpen" class="sheet-mask" @tap="rulesOpen = false">
      <view class="sheet" @tap.stop>
        <view class="sheet-bar" />
        <view class="sheet-head">
          <text class="sheet-title">积分规则</text>
          <view class="sheet-close" @tap="rulesOpen = false">×</view>
        </view>
        <view class="rule-content">
          <view class="rule-item">· 1 元 = 10 积分，积分可用于商城兑换</view>
          <view class="rule-item">· 积分不足时可用现金补差价（自动计算）</view>
          <view class="rule-item">· 参与活动、贡献、学习可获得积分</view>
          <view class="rule-item">· 兑换后不可退，请联系客服处理</view>
        </view>
        <view class="btn-primary rule-ok" @tap="rulesOpen = false">我知道了</view>
      </view>
    </view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { formatMoney, formatPoints } from '@/common/format'
import { checkLogin } from '@/libs/login'
import { fetchSiteConfig } from '@/common/site-config'
import Skeleton from '@/components/Skeleton.vue'

const CATEGORY_ALIAS = {
  course: '课程', courses: '课程', curriculum: '课程', training: '课程', workshop: '课程', lesson: '课程',
  salon: '沙龙', salon_activity: '沙龙', meeting: '沙龙',
  product: '实物', goods: '实物', material: '实物', physical: '实物', 实物: '实物',
  service: '服务', consulting: '服务'
}

function normalizeCategory(cat) {
  if (!cat) return '其他'
  const key = String(cat).toLowerCase().trim()
  return CATEGORY_ALIAS[key] || String(cat)
}

export default {
  components: { Skeleton },
  data() {
    return {
      list: [],
      paths: [],
      points: null,
      loading: true,
      confirmTarget: null,
      exchanging: false,
      pointsToYuan: 10,
      keyword: '',
      tab: '全部',
      categoryOptions: ['全部', '课程', '沙龙', '实物', '服务'],
      rulesOpen: false
    }
  },
  computed: {
    needPoints() {
      return Number((this.confirmTarget && (this.confirmTarget.integral_price || 0)) || 0)
    },
    needCash() {
      const short = Math.max(0, this.needPoints - (this.points || 0))
      return Math.ceil(short / (this.pointsToYuan || 10))
    },
    visible() {
      const kw = this.keyword.trim().toLowerCase()
      return this.list.filter((p) => {
        const catOk = this.tab === '全部' || normalizeCategory(p.category) === this.tab
        const nameOk = !kw || ((p.name || '') + ' ' + (p.store_name || '')).toLowerCase().indexOf(kw) >= 0
        return catOk && nameOk
      })
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
      const jobs = [chamber.products(), chamber.points(), chamber.pointsPaths()]
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') {
        this.list = results[0].value || []
        const cats = ['全部']
        for (const p of this.list) {
          const c = normalizeCategory(p.category)
          if (c !== '其他' && cats.indexOf(c) === -1) cats.push(c)
        }
        this.categoryOptions = cats
      }
      if (results[1].status === 'fulfilled') this.points = results[1].value
      if (results[2].status === 'fulfilled') this.paths = results[2].value || []
      this.loading = false
    },
    normalizeCategory,
    catGlyph(cat) {
      const map = { 课程: '课', 沙龙: '沙', 公益: '益', 路演: '演', 商品: '品', 服务: '务' }
      return map[normalizeCategory(cat)] || '品'
    },
    catTone(cat) {
      const map = { 课程: 'tone-course', 沙龙: 'tone-salon', 公益: 'tone-charity', 路演: 'tone-roadshow', 商品: 'tone-product', 服务: 'tone-service' }
      return map[normalizeCategory(cat)] || 'tone-product'
    },
    catIcon(cat) {
      // 商品分类图标（lucide，色随 tone：课程蓝/沙龙金/公益绿/服务紫）
      const map = {
        课程: 'ic-graduation-cap-blue',
        沙龙: 'ic-ticket-percent-gold',
        公益: 'ic-heart-handshake-green',
        路演: 'ic-sparkles-gold',
        商品: 'ic-gift-gold',
        服务: 'ic-handshake-blue',
        全部: 'ic-sparkles-blue'
      }
      return map[normalizeCategory(cat)] || 'ic-gift-gold'
    },
    pathIconCls(icon) {
      // 积分路径图标（lucide 金色系）
      const map = { coach: 'ic-graduation-cap-gold', charity: 'ic-heart-handshake-gold', roadshow: 'ic-presentation-gold', distribution: 'ic-user-plus-gold', study: 'ic-star-gold', medal: 'ic-medal-gold' }
      return map[icon] || 'ic-medal-gold'
    },
    fmtPoints(v) {
      return formatPoints(v)
    },
    fmtCash(p) {
      const price = Number((p && p.price) || 0)
      return price > 0 ? formatMoney(price) : ''
    },
    cashOf(p) {
      const n = Number(p.price || 0)
      return Number.isInteger(n) ? String(n) : n.toFixed(2)
    },
    pathGlyph(icon) {
      const map = { coach: '学', charity: '益', roadshow: '演', distribution: '推', study: '习', medal: '奖' }
      return map[icon] || '奖'
    },
    pathUnit(icon) {
      return icon === 'distribution' ? '/人' : '/次'
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
          Math.min(this.needPoints, this.points || 0),
          this.needCash > 0 ? this.needCash.toFixed(2) : '0.00'
        )
        const orderId = (order && (order.order_no || order.id)) || ''
        this.confirmTarget = null
        uni.navigateTo({
          url: '/pages/mall/exchange-success/index?points=' + this.needPoints + '&cash=' + (this.needCash > 0 ? this.needCash : 0) + '&name=' + encodeURIComponent(this.confirmTarget.name || this.confirmTarget.store_name || '') + '&order=' + orderId
        })
        this.loadData()
      } catch (e) {
      } finally {
        this.exchanging = false
      }
    }
  }
}
</script>

<style lang="scss">
.mall-page {
  padding-top: env(safe-area-inset-top);
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

.sheet-detail {
  background: #f4f7fb;
  border-radius: 24rpx;
  padding: 24rpx;
  margin-top: 20rpx;
}
.sd-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8rpx 0;
  font-size: 24rpx;
}
.sd-label {
  color: #617087;
}
.sd-value {
  color: #17325b;
  font-weight: 700;
}
.sd-gold {
  color: #c57620;
}
.sd-green {
  color: #2e7d4f;
}
.rule-ok {
  margin: 24rpx 32rpx 40rpx;
}
/* 规则条 */
.rule-bar {
  display: flex;
  align-items: center;
  gap: 14rpx;
  border-radius: 24rpx;
  padding: 24rpx 28rpx;
  margin-top: 24rpx;
}
.rb-icon {
  width: 40rpx;
  height: 40rpx;
  border-radius: 12rpx;
  background: #f6ead6;
  color: #b87325;
  font-size: 22rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.rb-text {
  flex: 1;
  font-size: 22rpx;
  color: #617087;
}
.rb-arrow {
  color: #b9c2cd;
  font-size: 26rpx;
}

/* 分类 */
.chips {
  margin: 24rpx -32rpx 0;
  padding-bottom: 8rpx;
}
.chips-inner {
  display: flex;
  gap: 16rpx;
  padding: 0 32rpx;
}
.chip {
  display: inline-block;
  padding: 16rpx 32rpx;
  border-radius: 999rpx;
  font-size: 24rpx;
  font-weight: 600;
  color: #617087;
  flex-shrink: 0;
  white-space: nowrap;
}

/* 节标题 */
.sec-head {
  display: flex;
  align-items: flex-end;
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
  display: block;
  font-size: 34rpx;
  font-weight: 700;
  color: #17325b;
}
.sh-sub {
  display: block;
  font-size: 22rpx;
  color: #8994a6;
  margin-top: 4rpx;
}
.sh-link {
  font-size: 22rpx;
  color: #ad6b22;
}

/* 商品网格 */
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
}
.p-img {
  height: 224rpx;
  border-radius: 28rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.pi-glyph {
  font-size: 56rpx;
  font-weight: 700;
}
.tone-course { background: linear-gradient(135deg, #e9f0f9, #d5e2f0); color: #285181; }
.tone-salon { background: linear-gradient(135deg, #fff0dc, #f6e2c2); color: #bd7627; }
.tone-charity { background: linear-gradient(135deg, #e8f0ec, #d3e2d9); color: #477467; }
.tone-roadshow { background: linear-gradient(135deg, #f0e9f7, #ddd0ec); color: #6b5b95; }
.tone-product { background: linear-gradient(135deg, #fff0dc, #f6e2c2); color: #bd7627; }
.tone-service { background: linear-gradient(135deg, #e9f0f9, #d5e2f0); color: #285181; }
.p-cat {
  display: inline-block;
  background: #fff2df;
  color: #ac691e;
  font-size: 20rpx;
  padding: 4rpx 12rpx;
  border-radius: 8rpx;
  margin-top: 20rpx;
  width: fit-content;
}
.p-name {
  font-size: 28rpx;
  font-weight: 700;
  color: #213653;
  line-height: 1.4;
  margin-top: 12rpx;
  min-height: 72rpx;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}
.p-price-row {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
  margin-top: 12rpx;
}
.p-points {
  font-size: 30rpx;
  font-weight: 700;
  color: #c57620;
}
.p-unit {
  font-size: 20rpx;
  font-weight: 600;
  color: #9aa3b0;
}
.p-cash {
  font-size: 20rpx;
  color: #a6aeb9;
  text-decoration: line-through;
}
.p-btn {
  margin-top: 20rpx;
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
  border-radius: 24rpx;
  font-size: 24rpx;
  font-weight: 600;
  text-align: center;
  padding: 16rpx 0;
  border-radius: 16rpx;
  box-shadow: 0 10rpx 24rpx rgba(185, 110, 29, 0.2);
}

/* 积分路径 */
.paths-card {
  padding: 8rpx 28rpx;
}
.path-row {
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 24rpx 0;
}
.path-row-border {
  border-bottom: 1rpx dashed #e6ebf1;
}
.pr-icon {
  width: 80rpx;
  height: 80rpx;
  border-radius: 24rpx;
  background: #fff0dc;
  color: #bc7423;
  font-size: 30rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.pr-info {
  flex: 1;
  min-width: 0;
}
.pr-title {
  font-size: 26rpx;
  font-weight: 700;
  color: #273b59;
  display: block;
}
.pr-desc {
  font-size: 20rpx;
  color: #939cab;
  display: block;
  margin-top: 4rpx;
}
.pr-points {
  font-size: 26rpx;
  font-weight: 700;
  color: #c57620;
  flex-shrink: 0;
}
.pr-unit {
  font-size: 20rpx;
  font-weight: 500;
  color: #9aa3b0;
}

/* 弹窗 */
.sheet-mask {
  position: fixed;
  inset: 0;
  background: rgba(11, 26, 46, 0.45);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  z-index: 99;
  -webkit-backdrop-filter: blur(4px);
  backdrop-filter: blur(4px);
}
.sheet {
  width: 100%;
  max-width: 1040rpx;
  background: #fff;
  border-radius: 44rpx 44rpx 0 0;
  padding: 24rpx 40rpx calc(48rpx + env(safe-area-inset-bottom));
  box-shadow: 0 -20px 60px rgba(10, 32, 60, 0.25);
}
.sheet-bar {
  width: 96rpx;
  height: 12rpx;
  border-radius: 999rpx;
  background: #dce3ec;
  margin: 0 auto 24rpx;
}
.sheet-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24rpx;
}
.sheet-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #17325b;
}
.sheet-close {
  width: 56rpx;
  height: 56rpx;
  border-radius: 50%;
  background: #eef1f5;
  color: #7c8898;
  font-size: 36rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sheet-product {
  text-align: center;
  font-size: 28rpx;
  color: #516580;
  margin-top: 16rpx;
}
.sheet-cost {
  text-align: center;
  font-size: 28rpx;
  font-weight: 700;
  color: #b8751d;
  margin-top: 24rpx;
}
.sheet-short {
  text-align: center;
  font-size: 22rpx;
  color: #8a94a3;
  margin-top: 12rpx;
}
.sheet-btns {
  display: flex;
  gap: 20rpx;
  margin-top: 36rpx;
}
.sb {
  flex: 1;
  font-size: 28rpx;
  padding: 22rpx 0;
}
.sb-disabled {
  opacity: 0.6;
}
.rule-content {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
  padding: 8rpx 0 24rpx;
}
.rule-item {
  font-size: 26rpx;
  color: #516580;
  line-height: 1.6;
}
.empty {
  text-align: center;
  padding: 80rpx 0;
  color: #c0c6d0;
  font-size: 26rpx;
}
</style>
