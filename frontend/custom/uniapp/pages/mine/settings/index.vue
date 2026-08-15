<template>
  <view class="settings-page">
    <page-header title="设置" eyebrow="资料 / 通知 / 隐私" />
    <!-- 资料（对齐 H5） -->
    <view class="sec-head">
      <text class="sec-title">资料</text>
      <text class="sec-eyebrow">展示给其他会员的信息</text>
    </view>
    <view class="card group">
      <view class="item">
        <view class="it-avatar">{{ (nickname || '明').slice(0, 1) }}</view>
        <view class="it-info">
          <text class="it-name">{{ displayPhone }}</text>
          <text class="it-desc">昵称 / 资料编辑请在「我的」页操作</text>
        </view>
      </view>
    </view>

    <!-- 通知（对齐 H5 3 开关） -->
    <view class="sec-head">
      <text class="sec-title">通知</text>
      <text class="sec-eyebrow">控制消息提醒方式</text>
    </view>
    <view class="card group">
      <view class="item" @tap="toggle('notify', 'activity')">
        <view class="it-left">
        <text class="it-label">活动提醒</text>
        <text class="it-desc">报名、签到、开课等动态即时通知</text>
        </view>
        <view class="{{'switch' + (notify.activity ? ' switch-on' : '')}}" />
      </view>
      <view class="item" @tap="toggle('notify', 'points')">
        <view class="it-left">
        <text class="it-label">积分变动提醒</text>
        <text class="it-desc">获取 / 消耗积分时通知</text>
        </view>
        <view class="{{'switch' + (notify.points ? ' switch-on' : '')}}" />
      </view>
      <view class="item" @tap="toggle('notify', 'system')">
        <view class="it-left">
        <text class="it-label">系统公告</text>
        <text class="it-desc">平台重要公告与升级通知</text>
        </view>
        <view class="{{'switch' + (notify.system ? ' switch-on' : '')}}" />
      </view>
    </view>

    <!-- 隐私（对齐 H5 3 开关） -->
    <view class="sec-head">
      <text class="sec-title">隐私</text>
      <text class="sec-eyebrow">掌控个人信息的可见范围</text>
    </view>
    <view class="card group">
      <view class="item" @tap="toggle('privacy', 'profileVisible')">
        <view class="it-left">
        <text class="it-label">允许好友查看我的资料</text>
        <text class="it-desc">好友可在名片中看到我的公司与职位</text>
        </view>
        <view class="{{'switch' + (privacy.profileVisible ? ' switch-on' : '')}}" />
      </view>
      <view class="item" @tap="toggle('privacy', 'inRecommend')">
        <view class="it-left">
        <text class="it-label">向推荐列表展示我</text>
        <text class="it-desc">出现在地区 / 行业筛选结果中</text>
        </view>
        <view class="{{'switch' + (privacy.inRecommend ? ' switch-on' : '')}}" />
      </view>
      <view class="item" @tap="toggle('privacy', 'hidePhone')">
        <view class="it-left">
        <text class="it-label">对非好友隐藏手机号</text>
        <text class="it-desc">仅好友与平台客服可见联系方式</text>
        </view>
        <view class="{{'switch' + (privacy.hidePhone ? ' switch-on' : '')}}" />
      </view>
    </view>

    <view class="card group" style="margin-top: 32rpx">
      <view class="item" @tap="clearCache">
        <text class="it-icon">清</text>
        <text class="it-label">清除缓存</text>
        <text class="it-arrow">></text>
      </view>
      <view class="item" @tap="about">
        <text class="it-icon">关</text>
        <text class="it-label">关于明德恒智AI企商汇</text>
        <text class="it-arrow">></text>
      </view>
    </view>

    <view v-if="isLogin" class="logout-btn" @tap="doLogout">退出登录</view>
  </view>
</template>

<script>
import PageHeader from '@/components/PageHeader.vue'
import chamber from '@/api/chamber'
import { checkLogin, logout } from '@/libs/login'

export default {
  components: { PageHeader },
  data() {
    return {
      isLogin: false,
      nickname: '',
      phone: '',
      notify: { activity: true, points: true, system: true },
      privacy: { profileVisible: true, inRecommend: true, hidePhone: true }
    }
  },
  computed: {
    displayPhone() {
      const p = this.phone || ''
      if (!p) return this.nickname || '明德会员'
      return p.slice(0, 3) + '****' + p.slice(-4)
    }
  },
  onShow() {
    this.isLogin = checkLogin()
    const ui = uni.getStorageSync('userInfo')
    if (ui && ui.nickname) this.nickname = ui.nickname
    if (ui && ui.phone) this.phone = ui.phone
    // 偏好从后端读（本地 storage 兜底，兼容旧版本）
    const saved = uni.getStorageSync('settings_toggles')
    if (saved) {
      try {
        const s = JSON.parse(saved)
        if (s.notify) this.notify = Object.assign({}, this.notify, s.notify)
        if (s.privacy) this.privacy = Object.assign({}, this.privacy, s.privacy)
      } catch (e) {}
    }
    if (this.isLogin) {
      chamber.meSettings().then((d) => {
        if (d && d.notify) this.notify = Object.assign({}, this.notify, d.notify)
        if (d && d.privacy) this.privacy = Object.assign({}, this.privacy, d.privacy)
      }).catch(() => {})
    }
  },
  methods: {
    // vue2 小程序模板只能调实例方法：toggle 原为组件根级方法（模板调不到），移入 methods
    toggle(group, key) {
      this[group][key] = !this[group][key]
      const payload = JSON.stringify({ notify: this.notify, privacy: this.privacy })
      uni.setStorageSync('settings_toggles', payload)
      // 持久化到后端（登录态下）
      if (this.isLogin) {
        chamber.meSettingsUpdate({ notify: this.notify, privacy: this.privacy }).catch(() => {})
      }
    },
    clearCache() {
      uni.clearStorageSync()
      this.isLogin = false
      uni.showToast({ title: '缓存已清除', icon: 'success' })
    },
    about() {
      uni.showModal({
        title: '明德恒智AI企商汇',
        content: '明德恒智AI企商汇 · PBC 企业家事业共同体\nv1.0.0',
        showCancel: false
      })
    },
    doLogout() {
      uni.showModal({
        title: '退出登录',
        content: '确定退出当前账号？',
        success: (res) => {
          if (res.confirm) {
            logout()
            this.isLogin = false
            uni.showToast({ title: '已退出', icon: 'success' })
            setTimeout(() => {
              uni.reLaunch({ url: '/pages/index/index' })
            }, 500)
          }
        }
      })
    }
  }
}
</script>

<style lang="scss">
.settings-page {
  padding: 32rpx;
}
.sec-head {
  margin: 28rpx 4rpx 16rpx;
}
.sec-head:first-child {
  margin-top: 4rpx;
}
.sec-title {
  display: block;
  font-size: 32rpx;
  font-weight: 700;
  color: #17325b;
}
.sec-eyebrow {
  display: block;
  font-size: 20rpx;
  color: #8994a6;
  margin-top: 6rpx;
}
.it-avatar {
  width: 80rpx;
  height: 80rpx;
  border-radius: 50%;
  background: #eef3f9;
  color: #285181;
  font-size: 32rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.it-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}
.it-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #273b59;
}
.it-desc {
  display: block;
  font-size: 20rpx;
  color: #969fad;
}
.switch {
  width: 88rpx;
  height: 48rpx;
  border-radius: 999rpx;
  background: #dfe4ea;
  position: relative;
  flex-shrink: 0;
  transition: background 0.2s;
}
.switch::after {
  content: '';
  position: absolute;
  top: 4rpx;
  left: 4rpx;
  width: 40rpx;
  height: 40rpx;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 2rpx 6rpx rgba(0, 0, 0, 0.15);
  transition: left 0.2s;
}
.switch-on {
  background: linear-gradient(90deg, #c87922, #eba94e);
}
.switch-on::after {
  left: 44rpx;
}
.group {
  padding: 8rpx 0;
}
.item {
  display: flex;
  align-items: center;
  padding: 30rpx 32rpx;
  gap: 20rpx;
  border-bottom: 1rpx solid #f5f2ea;
}
.item:last-child {
  border-bottom: none;
}
.it-icon {
  font-size: 32rpx;
}
.it-left {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4rpx;
}
.it-label {
  font-size: 28rpx;
  color: #273b59;
  font-weight: 700;
}
.it-arrow {
  color: #c0c6d0;
  font-size: 32rpx;
}
.logout-btn {
  margin-top: 48rpx;
  text-align: center;
  padding: 26rpx 0;
  border-radius: 20rpx;
  background: #fff;
  color: #e5484d;
  font-size: 28rpx;
  font-weight: 600;
}
</style>
