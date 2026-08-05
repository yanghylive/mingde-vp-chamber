<template>
  <view class="login-page">
    <view class="brand">
      <view class="brand-logo">明</view>
      <view class="brand-name gradient-text">明德恒智</view>
      <view class="brand-sub">PBC 企业家事业共同体</view>
    </view>

    <view class="tabs">
      <view :class="['tab', mode === 'password' && 'tab-active']" @tap="mode = 'password'">密码登录</view>
      <view :class="['tab', mode === 'sms' && 'tab-active']" @tap="mode = 'sms'">验证码登录</view>
    </view>

    <!-- 密码登录 -->
    <view v-if="mode === 'password'" class="form">
      <view class="field">
        <input v-model="account" class="input" placeholder="手机号 / 账号" placeholder-class="ph" />
      </view>
      <view class="field">
        <input v-model="password" class="input" password placeholder="密码" placeholder-class="ph" />
      </view>
      <button class="btn-primary submit" :disabled="loading" @tap="doPasswordLogin">
        {{ loading ? '登录中…' : '登 录' }}
      </button>
    </view>

    <!-- 验证码登录 -->
    <view v-else class="form">
      <view class="field">
        <input v-model="phone" class="input" type="number" maxlength="11" placeholder="手机号" placeholder-class="ph" />
      </view>
      <view class="field row">
        <input v-model="captcha" class="input flex1" type="number" maxlength="6" placeholder="图形验证码" placeholder-class="ph" />
        <image v-if="captchaKey" :src="captchaImg" class="captcha-img" mode="aspectFill" @tap="refreshCaptcha" />
        <view v-else class="captcha-placeholder" @tap="refreshCaptcha">获取验证码</view>
      </view>
      <view class="field row">
        <input v-model="smsCode" class="input flex1" type="number" maxlength="6" placeholder="短信验证码" placeholder-class="ph" />
        <view :class="['send-btn', sending && 'send-btn-disabled']" @tap="sendSms">
          {{ countdown > 0 ? countdown + 's' : '发送验证码' }}
        </view>
      </view>
      <button class="btn-primary submit" :disabled="loading" @tap="doSmsLogin">
        {{ loading ? '登录中…' : '登 录' }}
      </button>
    </view>

    <view class="agreement">登录即代表同意《用户协议》与《隐私政策》</view>
  </view>
</template>

<script>
import auth from '@/api/auth'
import { setLogin } from '@/libs/login'

export default {
  data() {
    return {
      mode: 'password',
      account: '',
      password: '',
      phone: '',
      captcha: '',
      smsCode: '',
      captchaKey: '',
      captchaImg: '',
      countdown: 0,
      sending: false,
      loading: false
    }
  },
  onLoad() {
    this.refreshCaptcha()
  },
  methods: {
    refreshCaptcha() {
      auth
        .verifyCode()
        .then((body) => {
          const data = body && body.data ? body.data : body
          const key = data.key
          this.captchaKey = key
          this.captchaImg = auth.captchaUrl(key)
        })
        .catch(() => {})
    },
    sendSms() {
      if (this.sending || this.countdown > 0) return
      if (!/^1\d{10}$/.test(this.phone)) {
        uni.showToast({ title: '请输入正确手机号', icon: 'none' })
        return
      }
      if (!this.captcha) {
        uni.showToast({ title: '请输入图形验证码', icon: 'none' })
        return
      }
      this.sending = true
      auth
        .sendSmsCode({ phone: this.phone, key: this.captchaKey, captchaVerification: this.captcha })
        .then(() => {
          uni.showToast({ title: '验证码已发送', icon: 'success' })
          this.countdown = 60
          const timer = setInterval(() => {
            this.countdown -= 1
            if (this.countdown <= 0) clearInterval(timer)
          }, 1000)
        })
        .catch(() => {
          this.refreshCaptcha()
        })
        .finally(() => {
          this.sending = false
        })
    },
    async finishLogin(token, userInfo) {
      setLogin(token, userInfo || null)
      // 自动建档（会员 bootstrap）
      try {
        const { chamber } = require('@/api/chamber')
        await chamber.meBootstrap()
      } catch (e) {}
      uni.showToast({ title: '登录成功', icon: 'success' })
      setTimeout(() => {
        uni.navigateBack({ delta: 1, fail: () => uni.switchTab({ url: '/pages/index/index' }) })
      }, 600)
    },
    doPasswordLogin() {
      if (!this.account || !this.password) {
        uni.showToast({ title: '请输入账号和密码', icon: 'none' })
        return
      }
      this.loading = true
      auth
        .loginByPassword({ account: this.account, password: this.password })
        .then((body) => {
          const d = body && body.data ? body.data : body
          const token = d.token || d.access_token
          if (!token) throw new Error('登录响应缺少 token')
          this.finishLogin(token, d.userInfo)
        })
        .catch(() => {})
        .finally(() => {
          this.loading = false
        })
    },
    doSmsLogin() {
      if (!/^1\d{10}$/.test(this.phone) || !this.smsCode) {
        uni.showToast({ title: '请输入手机号和验证码', icon: 'none' })
        return
      }
      this.loading = true
      auth
        .loginMobile({ phone: this.phone, captcha: this.smsCode })
        .then((body) => {
          const d = body && body.data ? body.data : body
          const token = d.token || d.access_token
          if (!token) throw new Error('登录响应缺少 token')
          this.finishLogin(token, d.userInfo)
        })
        .catch(() => {})
        .finally(() => {
          this.loading = false
        })
    }
  }
}
</script>

<style lang="scss">
.login-page {
  min-height: 100vh;
  padding: 160rpx 64rpx 60rpx;
  background: linear-gradient(180deg, #fffaf2 0%, #f7f5f0 100%);
  box-sizing: border-box;
}
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 80rpx;
}
.brand-logo {
  width: 128rpx;
  height: 128rpx;
  border-radius: 32rpx;
  background: linear-gradient(135deg, #d98a2d, #b8751d);
  color: #fff;
  font-size: 64rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 12rpx 32rpx rgba(184, 117, 29, 0.35);
  margin-bottom: 24rpx;
}
.brand-name {
  font-size: 44rpx;
  font-weight: 700;
}
.brand-sub {
  font-size: 24rpx;
  color: #8a94a3;
  margin-top: 8rpx;
}
.tabs {
  display: flex;
  background: #f1ede4;
  border-radius: 999rpx;
  padding: 8rpx;
  margin-bottom: 48rpx;
}
.tab {
  flex: 1;
  text-align: center;
  padding: 18rpx 0;
  font-size: 28rpx;
  color: #516580;
  border-radius: 999rpx;
  transition: all 0.2s;
}
.tab-active {
  background: #fff;
  color: #b8751d;
  font-weight: 600;
  box-shadow: 0 4rpx 12rpx rgba(39, 59, 89, 0.08);
}
.form {
  display: flex;
  flex-direction: column;
  gap: 28rpx;
}
.field {
  background: #fff;
  border-radius: 20rpx;
  padding: 24rpx 28rpx;
  display: flex;
  align-items: center;
  box-shadow: 0 4rpx 16rpx rgba(39, 59, 89, 0.04);
}
.field.row {
  gap: 20rpx;
}
.input {
  flex: 1;
  font-size: 30rpx;
  color: #273b59;
}
.flex1 {
  flex: 1;
}
.ph {
  color: #c0c6d0;
}
.captcha-img {
  width: 200rpx;
  height: 72rpx;
  border-radius: 12rpx;
}
.captcha-placeholder {
  width: 200rpx;
  height: 72rpx;
  border-radius: 12rpx;
  background: #f1ede4;
  color: #516580;
  font-size: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.send-btn {
  padding: 16rpx 24rpx;
  border-radius: 12rpx;
  background: #f6ead6;
  color: #b8751d;
  font-size: 26rpx;
  font-weight: 600;
}
.send-btn-disabled {
  color: #c0c6d0;
  background: #f1ede4;
}
.submit {
  margin-top: 16rpx;
}
.agreement {
  text-align: center;
  font-size: 22rpx;
  color: #c0c6d0;
  margin-top: 48rpx;
}
</style>
