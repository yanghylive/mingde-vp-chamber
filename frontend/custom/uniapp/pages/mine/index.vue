<template>
  <view class="mine-page">
    <!-- 用户信息卡 -->
    <view class="user-card glass-dark" @tap="goMembership">
      <view class="avatar">{{ avatarText }}</view>
      <view class="user-info">
        <view class="user-name">{{ displayName }}</view>
        <view class="user-tier">
          <text class="tier-badge">{{ currentTier.short }}</text>
          <text class="tier-name">{{ currentTier.name }}</text>
          <text v-if="expiresText" class="tier-expire">{{ expiresText }}</text>
        </view>
      </view>
      <view class="arrow">›</view>
    </view>

    <!-- 4 格统计 -->
    <view class="stats card">
      <view class="stat" @tap="goPointsLedger">
        <text class="stat-num gold">{{ points }}</text>
        <text class="stat-label">积分</text>
      </view>
      <view class="stat" @tap="goFriends">
        <text class="stat-num">{{ stats.friend_count || 0 }}</text>
        <text class="stat-label">好友</text>
      </view>
      <view class="stat" @tap="goDistribution">
        <text class="stat-num">{{ stats.distribution_count || 0 }}</text>
        <text class="stat-label">分销</text>
      </view>
      <view class="stat" @tap="goGraduateVerification">
        <text class="stat-num">{{ graduateStatus }}</text>
        <text class="stat-label">认证</text>
      </view>
    </view>

    <!-- 功能菜单 -->
    <view class="menu card">
      <view class="menu-item" @tap="goDistribution">
        <text class="mi-icon">📢</text>
        <text class="mi-label">我的分销码</text>
        <text class="mi-arrow">›</text>
      </view>
      <view class="menu-item" @tap="goFriends">
        <text class="mi-icon">👥</text>
        <text class="mi-label">我的好友</text>
        <text class="mi-arrow">›</text>
      </view>
      <view class="menu-item" @tap="goPointsLedger">
        <text class="mi-icon">💰</text>
        <text class="mi-label">积分记录</text>
        <text class="mi-arrow">›</text>
      </view>
      <view class="menu-item" @tap="goGraduateVerification">
        <text class="mi-icon">🎓</text>
        <text class="mi-label">毕业认证</text>
        <text class="mi-arrow">›</text>
      </view>
      <view class="menu-item" @tap="goNotifications">
        <text class="mi-icon">🔔</text>
        <text class="mi-label">通知</text>
        <text v-if="hasUnread" class="mi-badge" />
        <text class="mi-arrow">›</text>
      </view>
      <view class="menu-item" @tap="goCustomerService">
        <text class="mi-icon">💬</text>
        <text class="mi-label">客服微信</text>
        <text class="mi-arrow">›</text>
      </view>
      <view class="menu-item" @tap="goSettings">
        <text class="mi-icon">⚙️</text>
        <text class="mi-label">设置</text>
        <text class="mi-arrow">›</text>
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

export default {
  data() {
    return {
      profile: null,
      membership: null,
      stats: {},
      points: 0,
      hasUnread: false,
      ladder: TIERS,
      tierNum: 1
    }
  },
  computed: {
    displayName() {
      return (this.profile && (this.profile.real_name || this.profile.nickname)) || '明德会员'
    },
    avatarText() {
      return this.displayName.slice(0, 1)
    },
    currentTier() {
      return this.ladder.find((t) => t.tier === this.tierNum) || this.ladder[0]
    },
    expiresText() {
      if (this.tierNum <= 1) return ''
      const ts = this.membership && this.membership.tier_expires_at
      return ts ? toDate(ts) + ' 到期' : ''
    },
    graduateStatus() {
      const g = this.membership && this.membership.graduate_verified
      return g ? '已认证' : '未认证'
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
        chamber.meNotifications()
      ]
      const results = await Promise.allSettled(jobs)
      if (results[0].status === 'fulfilled') this.profile = results[0].value
      if (results[1].status === 'fulfilled') {
        this.membership = results[1].value
        this.tierNum = tierToNumber(this.membership && this.membership.effective_tier, 1)
      }
      if (results[2].status === 'fulfilled') this.stats = results[2].value || {}
      if (results[3].status === 'fulfilled') this.points = results[3].value
      if (results[4].status === 'fulfilled') {
        const list = results[4].value || []
        this.hasUnread = list.some((n) => !n.is_read)
      }
    },
    goMembership() {
      uni.navigateTo({ url: '/pages/membership/index' })
    },
    goPointsLedger() {
      uni.navigateTo({ url: '/pages/mine/points-ledger' })
    },
    goFriends() {
      uni.navigateTo({ url: '/pages/mine/friends' })
    },
    goDistribution() {
      uni.navigateTo({ url: '/pages/mine/distribution' })
    },
    goGraduateVerification() {
      uni.navigateTo({ url: '/pages/mine/graduate-verification' })
    },
    goNotifications() {
      uni.navigateTo({ url: '/pages/mine/notifications' })
    },
    goCustomerService() {
      uni.navigateTo({ url: '/pages/mine/customer-service' })
    },
    goSettings() {
      uni.navigateTo({ url: '/pages/mine/settings' })
    }
  }
}
</script>

<style lang="scss">
.mine-page {
  padding: 32rpx 32rpx 60rpx;
}
.user-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 40rpx 32rpx;
  border-radius: 28rpx;
  
  box-shadow: 0 12rpx 32rpx rgba(39, 59, 89, 0.25);
}
.avatar {
  width: 108rpx;
  height: 108rpx;
  border-radius: 50%;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 44rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.user-info {
  flex: 1;
  min-width: 0;
}
.user-name {
  color: #fff;
  font-size: 34rpx;
  font-weight: 700;
}
.user-tier {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-top: 12rpx;
}
.tier-badge {
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 20rpx;
  font-weight: 700;
  padding: 4rpx 14rpx;
  border-radius: 999rpx;
}
.tier-name {
  color: #ffd78f;
  font-size: 26rpx;
  font-weight: 600;
}
.tier-expire {
  color: rgba(255, 255, 255, 0.55);
  font-size: 20rpx;
}
.arrow {
  color: rgba(255, 255, 255, 0.4);
  font-size: 40rpx;
}
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
  gap: 8rpx;
}
.stat-num {
  font-size: 36rpx;
  font-weight: 800;
  color: #273b59;
}
.stat-num.gold {
  color: #b8751d;
}
.stat-label {
  font-size: 22rpx;
  color: #8a94a3;
}
.menu {
  margin-top: 24rpx;
  padding: 8rpx 0;
}
.menu-item {
  display: flex;
  align-items: center;
  padding: 30rpx 32rpx;
  gap: 20rpx;
  border-bottom: 1rpx solid #f5f2ea;
}
.menu-item:last-child {
  border-bottom: none;
}
.mi-icon {
  font-size: 34rpx;
}
.mi-label {
  flex: 1;
  font-size: 28rpx;
  color: #273b59;
}
.mi-badge {
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
  background: #e5484d;
}
.mi-arrow {
  color: #c0c6d0;
  font-size: 32rpx;
}
.footer {
  text-align: center;
  color: #c0c6d0;
  font-size: 22rpx;
  padding: 60rpx 0 20rpx;
}
</style>
