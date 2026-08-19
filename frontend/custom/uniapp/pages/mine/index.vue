<template>
  <view class="mine-page">
    <!-- 页头（对齐 H5 PageHeader：眉题 + 标题 + 副标题） -->
    <view class="ph">
      <view class="ph-row">
        <view>
          <view class="ph-eyebrow"><image class="ic ic-xs" src="/static/icons/ic-crown-gold.png" mode="aspectFit" />明德恒智AI企商汇</view>
          <text class="ph-title">我的</text>
          <text class="ph-sub">精进每一步，成就更好的自己</text>
        </view>
      </view>
    </view>

    <!-- profile 深色卡 -->
    <view class="profile glass-dark">
      <view class="pf-ring" />
      <view class="pf-row">
        <view class="pf-avatar">{{ avatarText }}</view>
        <view class="pf-info">
          <view class="pf-name-row">
            <text class="pf-name">{{ displayName }}</text>
            <view class="pf-badge">
              <image class="ic ic-xs" src="/static/icons/ic-crown-gold.png" mode="aspectFit" />
              <text>{{ currentTier.name }}</text>
            </view>
          </view>
          <text class="pf-sub" @tap="openNumberPicker">{{ currentTier.short }} · 番号 {{ memberNo }}{{ expiresText }}<text v-if="numbersList.length > 1" class="pf-no-switch"> 切换▾</text></text>
        </view>
        <view class="pf-edit" @tap="openEditor">编辑资料</view>
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
        <text class="stat-num">{{ points != null ? formatPoints(points) : '—' }}</text>
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
        <view class="sh-icon"><image class="ic ic-md" src="/static/icons/ic-medal-white.png" mode="aspectFit" /></view>
        <view>
          <text class="sh-title">我的服务</text>
          <text class="sh-sub">专属记录与权益中心</text>
        </view>
      </view>
    </view>
    <view class="card menu">
      <view
        v-for="(m, i) in menuMain"
        :key="m.label"
        class="{{'menu-item' + (i < menuMain.length - 1 ? ' menu-item-border' : '')}}"
        @tap="goTo(m.to)"
      >
        <view class="{{'mi-icon' + (' ' + m.color)}}"><view class="ic ic-md {{m.icon}}" /></view>
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
        <view class="mi-icon c-orange"><image class="ic ic-md" src="/static/icons/ic-ticket-check-orange.png" mode="aspectFit" /></view>
        <view class="mi-info">
          <text class="mi-label">我的活动</text>
          <text class="mi-sub">{{ registrationsCount }} 场报名记录</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
      <view class="menu-item menu-item-border" @tap="openEditor">
        <view class="mi-icon c-blue"><image class="ic ic-md" src="/static/icons/ic-star-blue.png" mode="aspectFit" /></view>
        <view class="mi-info">
          <text class="mi-label">我的资料</text>
          <text class="mi-sub">{{ profileText }}</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
      <view class="menu-item menu-item-border" @tap="goGraduateVerification">
        <view class="mi-icon c-green"><image class="ic ic-md" src="/static/icons/ic-shield-check-green.png" mode="aspectFit" /></view>
        <view class="mi-info">
          <text class="mi-label">毕业验证</text>
          <text class="mi-sub">学历 / 身份认证</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
      <view class="menu-item" @tap="goMembership">
        <view class="mi-icon c-pink"><image class="ic ic-md" src="/static/icons/ic-gift-pink.png" mode="aspectFit" /></view>
        <view class="mi-info">
          <text class="mi-label">会籍中心</text>
          <text class="mi-sub">{{ membershipText }}</text>
        </view>
        <text class="mi-arrow">></text>
      </view>
    </view>

    <!-- 会员等级 grid（对齐 H5） -->
    <view class="sec-head" style="margin-top: 36rpx">
      <view class="sh-row">
        <view class="sh-icon"><image class="ic ic-sm" src="/static/icons/ic-crown-gold.png" mode="aspectFit" /></view>
        <view>
          <text class="sh-title">会员等级</text>
          <text class="sh-sub">见证每一次持续成长</text>
        </view>
      </view>
    </view>
    <view class="tier-grid">
      <view
        v-for="t in ladder"
        :key="t.tier"
        class="{{'tier-cell glass-control' + (t.tier === tierNum ? ' tier-cell-current' : '')}}"
        @tap="goMembership"
      >
        <view class="{{'tier-dot' + (t.tier === tierNum ? ' tier-dot-current' : '')}}">
          <image class="ic ic-sm" src="/static/icons/ic-crown-gold.png" mode="aspectFit" />
        </view>
        <text class="tier-name">{{ t.short }}</text>
        <text class="tier-label">{{ t.name }}</text>
      </view>
    </view>


    <!-- 编辑资料弹窗（对齐 H5 EditProfileModal） -->
    <view v-if="editing" class="modal-mask" @tap="closeEditor">
      <view class="modal-sheet" @tap.stop>
        <view class="ms-head">
          <text class="ms-title">编辑资料</text>
          <view class="ms-close" @tap="closeEditor">×</view>
        </view>
        <scroll-view scroll-y class="ms-body">
          <view class="ms-field">
            <text class="ms-label">姓名</text>
            <input v-model="editor.real_name" class="ms-input" placeholder="请输入真实姓名" placeholder-class="ph" />
          </view>
          <view class="ms-row">
            <view class="ms-field flex1">
              <text class="ms-label">公司</text>
              <input v-model="editor.company_name" class="ms-input" placeholder="所在公司" placeholder-class="ph" />
            </view>
            <view class="ms-field flex1">
              <text class="ms-label">职位</text>
              <input v-model="editor.job_title" class="ms-input" placeholder="职位头衔" placeholder-class="ph" />
            </view>
          </view>
          <view class="ms-row">
            <view class="ms-field flex1">
              <text class="ms-label">行业</text>
              <input v-model="editor.industry" class="ms-input" placeholder="所属行业" placeholder-class="ph" />
            </view>
            <view class="ms-field flex1">
              <text class="ms-label">地区</text>
              <input v-model="editor.region" class="ms-input" placeholder="所在城市" placeholder-class="ph" />
            </view>
          </view>
          <view class="ms-field">
            <text class="ms-label">个人简介</text>
            <textarea v-model="editor.bio" class="ms-input ms-area" placeholder="一句话介绍自己" placeholder-class="ph" />
          </view>
          <text v-if="saveError" class="ms-error">{{ saveError }}</text>
        </scroll-view>
        <view class="ms-foot">
          <view class="ms-btn ms-btn-cancel" @tap="closeEditor">取消</view>
          <view class="ms-btn ms-btn-save" @tap="saveEditor">{{ saving ? '保存中…' : '保存' }}</view>
        </view>
      </view>
    </view>

    <!-- 番号选择弹窗 -->
    <view v-if="numberPickerVisible" class="modal-mask" @tap="numberPickerVisible = false">
      <view class="num-sheet" @tap.stop>
        <view class="num-sheet-head">
          <text class="num-sheet-title">选择展示番号</text>
          <view class="num-sheet-close" @tap="numberPickerVisible = false">×</view>
        </view>
        <scroll-view scroll-y class="num-sheet-body">
          <view
            v-for="n in numbersList"
            :key="n.id"
            class="{{'num-item' + (n.is_selected ? ' num-item-active' : '')}}"
            @tap="pickNumber(n)"
          >
            <view class="num-item-main">
              <text class="num-item-number">{{ n.number }}</text>
              <text v-if="n.label" class="num-item-label">{{ n.label }}</text>
            </view>
            <text v-if="n.is_selected" class="num-item-check">✓</text>
          </view>
        </scroll-view>
      </view>
    </view>

    <view class="footer">明德恒智AI企商汇 · PBC 企业家事业共同体</view>
  </view>
</template>

<script>
import chamber from '@/api/chamber'
import { checkLogin } from '@/libs/login'
import { TIERS, tierToNumber, applyTierConfig } from '@/common/tier'
import { toDate, formatPoints as _formatPoints } from '@/common/format'
import { fetchSiteConfig } from '@/common/site-config'
import { AI_DISABLED } from '@/config/app'

const MENU_MAIN = [
  { label: '我的分销码', sub: '推荐新会员注册得积分', icon: 'ic-link-2-orange', color: 'c-orange', to: '/pages/mine/distribution/index' },
  { label: 'AI 分身训练', sub: '对话训练你的智能分身', icon: 'ic-bot-gold', color: 'c-gold', to: '/pages/ai-twin/index' },
  { label: '客服微信', sub: '扫码添加专属客服', icon: 'ic-message-circle-blue', color: 'c-blue', to: '/pages/mine/customer-service/index' },
  { label: '我的好友', sub: '按等级 / 地区 / 行业筛选', icon: 'ic-users-round-green', color: 'c-green', to: '/pages/mine/friends/index' },
  { label: '我的预约', sub: '大咖档期预约记录', icon: 'ic-calendar-check-gold', color: 'c-gold', to: '/pages/mine/appointments/index' },
  { label: '积分记录', sub: '获取与消费明细', icon: 'ic-history-pink', color: 'c-pink', to: '/pages/mine/points-ledger/index' },
  { label: '设置', sub: '资料 / 通知 / 隐私', icon: 'ic-settings-gray', color: 'c-gray', to: '/pages/mine/settings/index' }
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
      MENU_MAIN,
      editing: false,
      saving: false,
      saveError: '',
      editor: {
        real_name: '',
        company_name: '',
        job_title: '',
        industry: '',
        region: '',
        bio: ''
      },
      numbersList: [],
      numberPickerVisible: false
    }
  },
  computed: {
    // 审核期下掉 AI 分身训练入口（深度合成合规）
    menuMain() {
      return AI_DISABLED ? MENU_MAIN.filter((m) => m.to !== '/pages/ai-twin/index') : MENU_MAIN
    },
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
      return this.friendsList.length || (this.stats.friends || 0)
    },
    distributionCount() {
      return (this.distribution && this.distribution.referred_count) || 0
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
    // 新人大礼包「完善个人资料」跳转：加载完成后自动打开编辑弹窗
    let enterEdit = false
    try {
      enterEdit = uni.getStorageSync('mine_enter_edit') === '1'
      if (enterEdit) uni.removeStorageSync('mine_enter_edit')
    } catch (e) {}
    this.loadData().then(() => {
      if (enterEdit) this.openEditor()
    })
  },
  methods: {
    iconPath(name) { return '/static/icons/' + name + '.png' },
    // vue2 小程序模板只能调实例方法：包装 format 工具（模板 {{ formatPoints(points) }}）
    formatPoints(n) { return _formatPoints(n) },
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
    openEditor() {
      const p = this.profile || {}
      this.editor = {
        real_name: p.real_name || '',
        company_name: p.company_name || '',
        job_title: p.job_title || '',
        industry: p.industry || '',
        region: p.city || p.province || '',
        bio: p.bio || ''
      }
      this.saveError = ''
      this.editing = true
    },
    closeEditor() {
      if (this.saving) return
      this.editing = false
    },
    openNumberPicker() {
      this.numberPickerVisible = true
      chamber
        .meNumbers()
        .then((list) => {
          this.numbersList = Array.isArray(list) ? list : []
        })
        .catch(() => {
          this.numbersList = []
        })
    },
    pickNumber(n) {
      chamber
        .selectNumber(n.id)
        .then(() => {
          this.numbersList = this.numbersList.map((x) => Object.assign({}, x, { is_selected: x.id === n.id }))
          if (this.profile) this.profile.member_no = n.number
          uni.showToast({ title: '已切换', icon: 'success' })
          setTimeout(() => (this.numberPickerVisible = false), 400)
        })
        .catch((e) => {
          uni.showToast({ title: (e && e.msg) || '切换失败', icon: 'none' })
        })
    },
    async saveEditor() {
      if (this.saving) return
      this.saving = true
      this.saveError = ''
      try {
        await chamber.meProfileUpdate({
          real_name: this.editor.real_name,
          company_name: this.editor.company_name,
          job_title: this.editor.job_title,
          industry: this.editor.industry,
          city: this.editor.region,
          bio: this.editor.bio
        })
        uni.showToast({ title: '已保存', icon: 'success' })
        this.editing = false
        this.loadData()
      } catch (e) {
        this.saveError = '保存失败，请重试'
      }
      this.saving = false
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
  padding: env(safe-area-inset-top) 32rpx 60rpx;
}
.ph {
  padding: 8rpx 32rpx 24rpx;
}
.ph-eyebrow {
  display: flex;
  align-items: center;
  gap: 10rpx;
  font-size: 20rpx;
  color: #a06a2d;
  font-weight: 600;
  letter-spacing: 2rpx;
  text-transform: uppercase;
}
.ph-title {
  display: block;
  font-size: 44rpx;
  font-weight: 800;
  color: #17233d;
  margin-top: 6rpx;
}
.ph-sub {
  display: block;
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 6rpx;
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
.pf-no-switch {
  color: #ffd78f;
  font-size: 18rpx;
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
  font-weight: 700;
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

/* 会员等级 grid */
.tier-grid {
  display: flex;
  gap: 20rpx;
}
.tier-cell {
  flex: 1;
  border-radius: 28rpx;
  padding: 28rpx 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8rpx;
}
.tier-cell-current {
  border: 2rpx solid #d98a2d;
}
.tier-dot {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: #eef0f3;
  color: #8a94a3;
  font-size: 26rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tier-dot-current {
  background: #fff1dc;
  color: #bd7726;
}
.tier-name {
  font-size: 24rpx;
  font-weight: 700;
  color: #34455f;
}
.tier-label {
  font-size: 18rpx;
  color: #9aa3b0;
}
/* 编辑资料弹窗 */
.modal-mask {
  position: fixed;
  inset: 0;
  z-index: 999;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.modal-sheet {
  width: 100%;
  background: linear-gradient(145deg, rgba(12, 37, 72, 0.97), rgba(23, 66, 108, 0.92));
  border-radius: 36rpx 36rpx 0 0;
  color: #fff;
  max-height: 78vh;
  display: flex;
  flex-direction: column;
}
.ms-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 32rpx 36rpx 8rpx;
}
.ms-title {
  font-size: 32rpx;
  font-weight: 700;
}
.ms-close {
  width: 56rpx;
  height: 56rpx;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30rpx;
}
.ms-body {
  flex: 1;
  padding: 24rpx 36rpx;
  box-sizing: border-box;
  max-height: 56vh;
}
.ms-field {
  margin-bottom: 24rpx;
}
.ms-row {
  display: flex;
  gap: 20rpx;
}
.ms-row .ms-field {
  flex: 1;
  min-width: 0;
}
.ms-label {
  display: block;
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.8);
  margin-bottom: 12rpx;
}
.ms-input {
  background: rgba(255, 255, 255, 0.1);
  border: 1rpx solid rgba(255, 255, 255, 0.12);
  border-radius: 16rpx;
  color: #fff;
  font-size: 28rpx;
  padding: 24rpx 28rpx;
  width: 100%;
  box-sizing: border-box;
}
.ms-area {
  min-height: 140rpx;
}
.ms-error {
  display: block;
  font-size: 22rpx;
  color: #f29a8a;
  margin-top: 8rpx;
}
.ms-foot {
  display: flex;
  gap: 20rpx;
  padding: 24rpx 36rpx 40rpx;
  border-top: 1rpx solid rgba(255, 255, 255, 0.1);
}
.ms-btn {
  flex: 1;
  text-align: center;
  font-size: 28rpx;
  font-weight: 600;
  padding: 22rpx 0;
  border-radius: 16rpx;
}
.ms-btn-cancel {
  background: rgba(255, 255, 255, 0.15);
  color: #17325b;
}
.ms-btn-save {
  background: linear-gradient(90deg, #c87922, #eba94e);
  color: #fff;
}
.footer {
  text-align: center;
  color: #c0c6d0;
  font-size: 22rpx;
  padding: 60rpx 0 20rpx;
}
.num-sheet {
  width: 100%;
  background: #fff;
  border-radius: 36rpx 36rpx 0 0;
  max-height: 70vh;
  display: flex;
  flex-direction: column;
}
.num-sheet-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 32rpx 36rpx 12rpx;
}
.num-sheet-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #273b59;
}
.num-sheet-close {
  width: 56rpx;
  height: 56rpx;
  border-radius: 50%;
  background: #f2f5f8;
  color: #8a94a3;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30rpx;
}
.num-sheet-body {
  padding: 12rpx 36rpx 40rpx;
  box-sizing: border-box;
  max-height: 52vh;
}
.num-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24rpx 28rpx;
  border-radius: 20rpx;
  background: #f7f9fb;
  margin-bottom: 16rpx;
}
.num-item-active {
  background: #fff6e8;
  border: 2rpx solid #e8a23c;
}
.num-item-main {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}
.num-item-number {
  font-size: 28rpx;
  font-weight: 700;
  color: #273b59;
}
.num-item-label {
  font-size: 22rpx;
  color: #8a94a3;
}
.num-item-check {
  color: #b8751d;
  font-size: 30rpx;
  font-weight: 700;
}
</style>
