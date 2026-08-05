<template>
  <view class="mine-page">
    <!-- profile 深色卡 -->
    <view class="profile glass-dark">
      <view class="pf-ring" />
      <view class="pf-row">
        <view class="pf-avatar">{{ avatarText }}</view>
        <view class="pf-info">
          <view class="pf-name-row">
            <text class="pf-name">{{ displayName }}</text>
            <view class="pf-badge">
              <text class="pf-badge-crown">V</text>
              <text>{{ currentTier.name }}</text>
            </view>
          </view>
          <text class="pf-sub">{{ currentTier.name }} · 番号 {{ memberNo }}{{ expiresText }}</text>
        </view>
        <view class="pf-edit" @tap="goProfile">编辑资料</view>
      </view>
      <view class="pf-bottom">
        <view>
          <text class="pfb-label">当前会籍</text>
          <text class="pfb-value">{{ currentTier.name }}</text>
        </view>
        <view class="pfb-right">
          <text class="pfb-label">权益</text>
          <text class="pfb-value2">{{ activeTermsCount }} 项进行中</text>
        </view>
      </view>
    </view>

    <!-- 4 格统计 -->
    <view class="stats card">
      <view class="stat" @tap="goPointsLedger">
        <text class="stat-num">{{ points != null ? points : '—' }}</text>
        <text class="stat-label">积分</text>
      </view>
      <view class="stat">
        <text class="stat-num">{{ contribution != null ? contribution : '—' }}</text>
        <text class="stat-label">贡献值</text>
      </view>
      <view class="stat" @tap="goFriends">
        <text class="stat-num">{{ friendsCount }}</text>
        <text class="stat-label">好友</text>
      </view>
      <view class="stat" @tap="goDistribution">
        <text class="stat-num">{{ distributionCount }}</text>
        <text class="stat-label">分销</text>
      </view>
    </view>

    <!-- 我的服务 -->
    <view class="sec-head">
      <view class="sh-row">
        <text class="sh-icon">荐</text>
        <view>
          <text class="sh-title">我的服务</text>
          <text class="sh-sub">专属记录与权益中心</text>
        </view>
      </view>
    </view>
    <view class="card menu">
      <view
        v-for="(m, i) in MENU_MAIN"
        :key="m.label"
        class="{{'menu-item' + (i < MENU_MAIN.length - 1 ? ' menu-item-border' : '')}}"
        @tap="goTo(m.to)"
      >
        <view class="{{'mi-icon' + (' ' + m.color)}}">{{ m.glyph }}</view>
        <view class="mi-info">
          <text class="mi-label">{{ m.label }}</text>
          <text class="mi-sub">{{ m.sub }}</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
    </view>

    <!-- 第二菜单组 -->
    <view class="card menu" style="margin-top: 24rpx">
      <view class="menu-item menu-item-border" @tap="goEvents">
        <view class="mi-icon c-orange">票</view>
        <view class="mi-info">
          <text class="mi-label">我的活动</text>
          <text class="mi-sub">{{ registrationsCount }} 场报名记录</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
      <view class="menu-item menu-item-border" @tap="goProfile">
        <view class="mi-icon c-blue">料</view>
        <view class="mi-info">
          <text class="mi-label">我的资料</text>
          <text class="mi-sub">{{ profileText }}</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
      <view class="menu-item menu-item-border" @tap="goGraduateVerification">
        <view class="mi-icon c-green">证</view>
        <view class="mi-info">
          <text class="mi-label">毕业验证</text>
          <text class="mi-sub">学历 / 身份认证</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
      <view class="menu-item" @tap="goMembership">
        <view class="mi-icon c-pink">会</view>
        <view class="mi-info">
          <text class="mi-label">会籍中心</text>
          <text class="mi-sub">{{ membershipText }}</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
    </view>

    <view class="footer">明德恒智 · PBC 企业家事业共同体</view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { TIERS, tierToNumber, applyTierConfig } from '@/common/tier'
import { toDate } from '@/common/format'
import { fetchSiteConfig } from '@/common/site-config'

const MENU_MAIN = [
  { label: '我的分销码', sub: '推荐新会员注册得积分', glyph: '推', color: 'c-orange', to: '/pages/mine/distribution/index' },
  { label: '客服微信', sub: '扫码添加专属客服', glyph: '客', color: 'c-blue', to: '/pages/mine/customer-service/index' },
  { label: '我的好友', sub: '按等级 / 地区 / 行业筛选', glyph: '友', color: 'c-green', to: '/pages/mine/friends/index' },
  { label: '积分记录', sub: '获取与消费明细', glyph: '积', color: 'c-pink', to: '/pages/mine/points-ledger/index' },
  { label: '设置', sub: '资料 / 通知 / 隐私', glyph: '设', color: 'c-gray', to: '/pages/mine/settings/index' }
]

export default {
  data() {
    return {
      profile: null,
      membership: null,
      stats: {},
      points: null,
      registrations: [],
      friendsList: [],
      distribution: null,
      ladder: TIERS,
      tierNum: 1,
      MENU_MAIN
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
    memberNo() {
      return (this.profile && (this.profile.member_no || this.profile.uid)) || '—'
    },
    expiresText() {
      const ts = this.membership && this.membership.tier_expires_at
      return ts ? ' · 有效期至 ' + toDate(ts) : ''
    },
    activeTermsCount() {
      const t = this.membership && this.membership.active_terms
      return Array.isArray(t) ? t.length : 0
    },
    contribution() {
      return this.stats.contribution !== undefined ? this.stats.contribution : null
    },
    friendsCount() {
      return this.friendsList.length || (this.stats.friend_count || 0)
    },
    distributionCount() {
      return (this.distribution && this.distribution.invite_count) || 0
    },
    registrationsCount() {
      return this.registrations.length
    },
    profileText() {
      const p = this.profile
      if (p && p.company_name) return p.company_name + (p.job_title ? ' · ' + p.job_title : '')
      return '完善个人资料'
    },
    membershipText() {
      return this.membership && this.membership.can_purchase ? '可购买会员' : '查看会籍权益'
    }
  },
  onShow() {
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
      const jobs = [
        chamber.meProfile(),
        chamber.meMembership(),
        chamber.meStats(),
        chamber.points(),
        chamber.myEventRegistrations(),
        chamber.meFriends(),
        chamber.meDistribution()
      ]
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') this.profile = results[0].value
      if (results[1].status === 'fulfilled') {
        this.membership = results[1].value
        this.tierNum = tierToNumber(this.membership && this.membership.effective_tier, 1)
      }
      if (results[2].status === 'fulfilled') this.stats = results[2].value || {}
      if (results[3].status === 'fulfilled') this.points = results[3].value
      if (results[4].status === 'fulfilled') this.registrations = results[4].value || []
      if (results[5].status === 'fulfilled') this.friendsList = results[5].value || []
      if (results[6].status === 'fulfilled') this.distribution = results[6].value || null
    },
    goTo(path) {
      if (path) uni.navigateTo({ url: path })
    },
    goProfile() {
      uni.navigateTo({ url: '/pages/chamber/profile/index' })
    },
    goPointsLedger() {
      uni.navigateTo({ url: '/pages/mine/points-ledger/index' })
    },
    goFriends() {
      uni.navigateTo({ url: '/pages/mine/friends/index' })
    },
    goDistribution() {
      uni.navigateTo({ url: '/pages/mine/distribution/index' })
    },
    goEvents() {
      uni.switchTab({ url: '/pages/events/index' })
    },
    goGraduateVerification() {
      uni.navigateTo({ url: '/pages/mine/graduate-verification/index' })
    },
    goMembership() {
      uni.navigateTo({ url: '/pages/membership/index' })
    }
  }
}
</script>

<style lang="scss">
.mine-page {
  padding: 24rpx 32rpx 60rpx;
  min-height: 100vh;
}

/* profile 深色卡 */
.profile {
  position: relative;
  overflow: hidden;
  border-radius: 44rpx;
  padding: 40rpx 36rpx;
  color: #fff;
}
.pf-ring {
  position: absolute;
  top: -64rpx;
  right: -48rpx;
  width: 288rpx;
  height: 288rpx;
  border-radius: 50%;
  border: 1rpx solid rgba(243, 188, 106, 0.2);
}
.pf-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 24rpx;
}
.pf-avatar {
  width: 128rpx;
  height: 128rpx;
  border-radius: 50%;
  border: 4rpx solid #f2bd6b;
  background: linear-gradient(135deg, #d99b49, #81531f);
  color: #fff;
  font-size: 44rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.pf-info {
  flex: 1;
  min-width: 0;
}
.pf-name-row {
  display: flex;
  align-items: center;
  gap: 14rpx;
}
.pf-name {
  font-size: 34rpx;
  font-weight: 600;
}
.pf-badge {
  display: flex;
  align-items: center;
  gap: 6rpx;
  background: #f0b35b;
  color: #173253;
  font-size: 20rpx;
  font-weight: 700;
  padding: 4rpx 14rpx;
  border-radius: 8rpx;
  flex-shrink: 0;
}
.pf-badge-crown {
  font-size: 18rpx;
}
.pf-sub {
  display: block;
  font-size: 20rpx;
  color: rgba(255, 255, 255, 0.65);
  margin-top: 10rpx;
}
.pf-edit {
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.9);
  font-size: 20rpx;
  font-weight: 600;
  padding: 12rpx 24rpx;
  border-radius: 999rpx;
  -webkit-backdrop-filter: blur(10px);
  backdrop-filter: blur(10px);
}
.pf-bottom {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 36rpx;
  padding: 24rpx 28rpx;
  border-radius: 28rpx;
  background: rgba(255, 255, 255, 0.1);
  -webkit-backdrop-filter: blur(10px);
  backdrop-filter: blur(10px);
}
.pfb-label {
  display: block;
  font-size: 18rpx;
  color: rgba(255, 255, 255, 0.6);
}
.pfb-value {
  display: block;
  font-size: 26rpx;
  font-weight: 600;
  color: #f5c276;
  margin-top: 6rpx;
}
.pfb-value2 {
  display: block;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.8);
  margin-top: 6rpx;
  text-align: right;
}
.pfb-right {
  text-align: right;
}

/* 统计 */
.stats {
  display: flex;
  padding: 32rpx 16rpx;
  margin-top: 24rpx;
}
.stat {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6rpx;
  border-right: 1rpx solid #edf0f4;
}
.stat:last-child {
  border-right: none;
}
.stat-num {
  font-size: 36rpx;
  font-weight: 700;
  color: #213c62;
}
.stat-label {
  font-size: 20rpx;
  color: #929baa;
}

/* 菜单 */
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
.menu {
  padding: 8rpx 0;
}
.menu-item {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 28rpx 32rpx;
}
.menu-item-border {
  border-bottom: 1rpx solid #edf0f4;
}
.mi-icon {
  width: 80rpx;
  height: 80rpx;
  border-radius: 24rpx;
  font-size: 28rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.c-orange { background: #fff0dc; color: #bc7423; }
.c-blue { background: #e9f0f9; color: #285181; }
.c-green { background: #e9f3ef; color: #42705f; }
.c-pink { background: #f5eaf0; color: #8a5369; }
.c-gray { background: #eef0f3; color: #5d6b7d; }
.mi-info {
  flex: 1;
  min-width: 0;
}
.mi-label {
  display: block;
  font-size: 28rpx;
  color: #273b59;
  font-weight: 600;
}
.mi-sub {
  display: block;
  font-size: 20rpx;
  color: #969fad;
  margin-top: 4rpx;
}
.mi-arrow {
  color: #bdc3cd;
  font-size: 30rpx;
}
.footer {
  text-align: center;
  color: #c0c6d0;
  font-size: 22rpx;
  padding: 60rpx 0 20rpx;
}
</style>
