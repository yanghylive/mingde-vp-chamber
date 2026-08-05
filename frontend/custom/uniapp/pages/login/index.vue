<template>
  <view class="login-page">
    <!-- 品牌 -->
    <view class="brand">
      <view class="brand-logo">明</view>
      <text class="brand-name">明德恒智</text>
      <text class="brand-sub">PBC 企业家事业共同体</text>
    </view>

    <!-- 卡片 -->
    <view class="login-card">
      <!-- tab 切换 -->
      <view class="tabs">
        <view
          class="{{'tab' + (mode === 'sms' ? ' tab-active' : '')}}"
          @tap="switchMode('sms')"
        >
          验证码登录
        </view>
        <view
          class="{{'tab' + (mode === 'password' ? ' tab-active' : '')}}"
          @tap="switchMode('password')"
        >
          密码登录
        </view>
      </view>

      <!-- 手机号 -->
      <view class="field">
        <text class="f-icon">话</text>
        <input v-model="phone" class="input" type="number" maxlength="11" placeholder="请输入手机号" placeholder-class="ph" />
      </view>

      <!-- 验证码模式 -->
      <block v-if="mode === 'sms'">
        <view class="field-row">
          <view class="field flex1">
            <text class="f-icon">图</text>
            <input v-model="imgCode" class="input" type="number" maxlength="6" placeholder="图片验证码" placeholder-class="ph" />
          </view>
          <image v-if="captchaImg" :src="captchaImg" class="captcha-img" mode="aspectFill" @tap="refreshCaptcha" />
          <view v-else class="captcha-placeholder" @tap="refreshCaptcha">获取</view>
        </view>
        <view class="field-row">
          <view class="field flex1">
            <text class="f-icon">码</text>
            <input v-model="smsCode" class="input" type="number" maxlength="6" placeholder="短信验证码" placeholder-class="ph" />
          </view>
          <view class="{{'send-btn' + ((countdown > 0 || loading) ? ' send-btn-disabled' : '')}}" @tap="sendSms">
            {{ countdown > 0 ? countdown + 's' : '获取验证码' }}
          </view>
        </view>
      </block>

      <!-- 密码模式 -->
      <view v-else class="field">
        <text class="f-icon">密</text>
        <input v-model="password" class="input" password placeholder="请输入密码" placeholder-class="ph" />
      </view>

      <!-- 错误提示 -->
      <view v-if="error" class="error-tip">{{ error }}</view>

      <!-- 登录按钮 -->
      <view class="{{'submit' + (loading ? ' submit-disabled' : '')}}" @tap="submit">
        {{ loading ? '登录中…' : '登 录' }}
      </view>
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
      mode: 'sms',
      phone: '',
      password: '',
      imgCode: '',
      smsCode: '',
      captchaKey: '',
      captchaImg: '',
      countdown: 0,
      loading: false,
      error: ''
    }
  },
  onLoad() {
    this.refreshCaptcha()
  },
  methods: {
    switchMode(m) {
      this.mode = m
      this.error = ''
    },
    refreshCaptcha() {
      auth
        .verifyCode()
        .then((body) => {
          const data = body && body.data ? body.data : body
          this.captchaKey = data.key
          this.captchaImg = auth.captchaUrl(data.key)
        })
        .catch(() => {})
    },
    sendSms() {
      if (this.countdown > 0 || this.loading) return
      if (!/^1\d{10}$/.test(this.phone)) {
        this.error = '请输入正确的手机号'
        return
      }
      if (!this.imgCode) {
        this.error = '请输入图片验证码'
        return
      }
      this.loading = true
      auth
        .sendSmsCode({ phone: this.phone, key: this.captchaKey, captchaVerification: this.imgCode, type: 'login' })
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
          this.loading = false
        })
    },
    async finishLogin(token, userInfo) {
      setLogin(token, userInfo || null)
      try {
        const { chamber } = require('@/api/chamber')
        await chamber.meBootstrap()
      } catch (e) {}
      uni.showToast({ title: '登录成功', icon: 'success' })
      setTimeout(() => {
        uni.navigateBack({ delta: 1, fail: () => uni.switchTab({ url: '/pages/index/index' }) })
      }, 600)
    },
    submit() {
      if (this.mode === 'sms') {
        this.submitSms()
      } else {
        this.submitPassword()
      }
    },
    submitSms() {
      if (!/^1\d{10}$/.test(this.phone)) {
        this.error = '请输入正确的手机号'
        return
      }
      if (!this.smsCode) {
        this.error = '请输入短信验证码'
        return
      }
      this.loading = true
      this.error = ''
      auth
        .loginMobile({ phone: this.phone, captcha: this.smsCode })
        .then((body) => {
          const d = body && body.data ? body.data : body
          const token = d.token || d.access_token
          if (!token) throw new Error('登录响应缺少 token')
          this.finishLogin(token, d.userInfo)
        })
        .catch((e) => {
          this.error = (e && e.msg) || '登录失败，请稍后重试'
        })
        .finally(() => {
          this.loading = false
        })
    },
    submitPassword() {
      if (!/^1\d{10}$/.test(this.phone)) {
        this.error = '请输入正确的手机号'
        return
      }
      if (!this.password) {
        this.error = '请输入密码'
        return
      }
      this.loading = true
      this.error = ''
      auth
        .loginByPassword({ account: this.phone, password: this.password })
        .then((body) => {
          const d = body && body.data ? body.data : body
          const token = d.token || d.access_token
          if (!token) throw new Error('登录响应缺少 token')
          this.finishLogin(token, d.userInfo)
        })
        .catch((e) => {
          this.error = (e && e.msg) || '登录失败，请稍后重试'
        })
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
  padding: 128rpx 48rpx 48rpx;
  background: linear-gradient(180deg, #1b2a4a, #0e1830);
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
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
  font-size: 56rpx;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 12rpx 32rpx rgba(0, 0, 0, 0.2);
  margin-bottom: 24rpx;
}
.brand-name {
  font-size: 44rpx;
  font-weight: 700;
  color: #fff;
}
.brand-sub {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.6);
  margin-top: 12rpx;
}
.login-card {
  background: #fff;
  border-radius: 44rpx;
  padding: 40rpx 36rpx;
  box-shadow: 0 24rpx 64rpx rgba(0, 0, 0, 0.25);
}
.tabs {
  display: flex;
  background: #f1f3f7;
  border-radius: 20rpx;
  padding: 8rpx;
  margin-bottom: 40rpx;
}
.tab {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  font-size: 28rpx;
  color: #8a94a3;
  border-radius: 16rpx;
  transition: all 0.2s;
  font-weight: 500;
}
.tab-active {
  background: #fff;
  color: #1b2a4a;
  font-weight: 600;
  box-shadow: 0 4rpx 12rpx rgba(27, 42, 74, 0.1);
}
.field {
  display: flex;
  align-items: center;
  gap: 20rpx;
  border: 2rpx solid #e8ecf1;
  border-radius: 20rpx;
  padding: 24rpx 28rpx;
  margin-bottom: 28rpx;
}
.field:focus-within {
  border-color: #1b2a4a;
}
.f-icon {
  font-size: 28rpx;
  color: #a0a8b5;
}
.input {
  flex: 1;
  font-size: 28rpx;
  color: #1b2a4a;
}
.ph {
  color: #c0c6d0;
}
.field-row {
  display: flex;
  gap: 16rpx;
  margin-bottom: 28rpx;
}
.field-row .field {
  margin-bottom: 0;
}
.flex1 {
  flex: 1;
}
.captcha-img {
  width: 200rpx;
  height: 96rpx;
  border-radius: 16rpx;
  border: 2rpx solid #e8ecf1;
  flex-shrink: 0;
}
.captcha-placeholder {
  width: 200rpx;
  height: 96rpx;
  border-radius: 16rpx;
  background: #f1f3f7;
  color: #8a94a3;
  font-size: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.send-btn {
  width: 200rpx;
  height: 96rpx;
  border-radius: 16rpx;
  background: #1b2a4a;
  color: #fff;
  font-size: 24rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.send-btn-disabled {
  background: #f1f3f7;
  color: #a0a8b5;
}
.error-tip {
  background: #fdeeee;
  color: #b44444;
  font-size: 26rpx;
  padding: 20rpx 28rpx;
  border-radius: 16rpx;
  margin-bottom: 28rpx;
}
.submit {
  background: linear-gradient(90deg, #1b2a4a, #0e1830);
  color: #fff;
  font-size: 30rpx;
  font-weight: 600;
  text-align: center;
  padding: 24rpx 0;
  border-radius: 20rpx;
  box-shadow: 0 12rpx 32rpx rgba(27, 42, 74, 0.3);
}
.submit-disabled {
  opacity: 0.6;
}
.agreement {
  text-align: center;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.4);
  margin-top: 48rpx;
}
</style>
