<template>
  <view class="login-page">
    <!-- 品牌 -->
    <view class="brand">
      <view class="brand-logo">明</view>
      <text class="brand-name">明德恒智AI企商汇</text>
      <text class="brand-sub">企业家事业共同体</text>
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
        <image class="ic ic-sm" src="/static/icons/ic-message-circle-gold.png" mode="aspectFit" />
        <input v-model="phone" class="input" type="number" maxlength="11" placeholder="请输入手机号" placeholder-class="ph" />
      </view>

      <!-- 验证码模式 -->
      <block v-if="mode === 'sms'">
        <view class="field-row">
          <view class="field flex1">
            <image class="ic ic-sm" src="/static/icons/ic-search-gold.png" mode="aspectFit" />
            <input v-model="imgCode" class="input" type="number" maxlength="6" placeholder="图片验证码" placeholder-class="ph" />
          </view>
          <image v-if="captchaImg" :src="captchaImg" class="captcha-img" mode="aspectFill" @tap="refreshCaptcha" />
          <view v-else class="captcha-placeholder" @tap="refreshCaptcha">获取</view>
        </view>
        <view class="field-row">
          <view class="field flex1">
            <image class="ic ic-sm" src="/static/icons/ic-message-circle-gold.png" mode="aspectFit" />
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

      <!-- 微信一键登录（CRMEB v2 routine 链路） -->
      <view class="wx-divider">
        <view class="wx-divider-line" />
        <text class="wx-divider-text">其他登录方式</text>
        <view class="wx-divider-line" />
      </view>
      <view class="{{'wx-login' + (loading ? ' wx-login-disabled' : '')}}" @tap="wxLogin">
        <text class="wx-login-icon">微</text>
        <text class="wx-login-text">微信一键登录</text>
      </view>
      <!-- 首次微信登录需绑定手机号（真实用户点击授权） -->
      <button
        v-if="wxBindMode"
        class="wx-phone-btn"
        open-type="getPhoneNumber"
        @getphonenumber="onGetPhoneNumber"
        :disabled="loading"
      >
        <text class="wx-phone-text">微信授权手机号绑定</text>
      </button>
      <view v-if="wxError" class="wx-error">{{ wxError }}</view>
    </view>

    <!-- 协议同意（微信审核要求：必须自主勾选，不得默认同意） -->
    <view class="agreement">
      <view class="agree-row" @tap="toggleAgreed">
        <view class="{{'agree-box' + (agreed ? ' agree-box-checked' : '')}}">
          <text v-if="agreed" class="agree-tick">✓</text>
        </view>
        <text class="agree-text">我已阅读并同意</text>
      </view>
      <view class="agree-links">
        <text class="agree-link" @tap.stop="openLegal('agreement')">《用户服务协议》</text>
        <text class="agree-text">与</text>
        <text class="agree-link" @tap.stop="openLegal('privacy')">《隐私政策》</text>
      </view>
    </view>
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
      error: '',
      agreed: false,
      wxBindMode: false,
      wxAuthKey: '',
      wxError: ''
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
    toggleAgreed() {
      this.agreed = !this.agreed
      if (this.agreed) this.error = ''
    },
    openLegal(type) {
      uni.navigateTo({ url: '/pages/legal/index?type=' + type })
    },
    requireAgreed() {
      if (!this.agreed) {
        this.error = '请先阅读并勾选同意《用户服务协议》和《隐私政策》'
        return false
      }
      return true
    },
    // ---- 微信一键登录 ----
    wxLogin() {
      if (this.loading) return
      if (!this.requireAgreed()) return
      this.wxError = ''
      this.loading = true
      uni.login({
        provider: 'weixin',
        success: (loginRes) => {
          const code = loginRes.code || ''
          if (!code) {
            this.loading = false
            this.wxError = '微信登录失败，请重试'
            return
          }
          auth
            .wechatAuthType(code)
            .then((body) => {
              const data = body && body.data ? body.data : body
              const key = data && data.key
              if (!key) {
                this.loading = false
                this.wxError = (data && data.msg) || '微信登录失败，请稍后重试'
                return
              }
              this.wxAuthKey = key
              if (data.bindPhone) {
                // 首次微信登录：需绑定手机号（用户点击授权）
                this.wxBindMode = true
                this.loading = false
                return
              }
              this.finishWxLogin(key)
            })
            .catch((e) => {
              this.loading = false
              this.wxError = (e && e.msg) || '微信登录失败，请稍后重试'
            })
        },
        fail: () => {
          this.loading = false
          this.wxError = '微信登录取消或失败'
        }
      })
    },
    onGetPhoneNumber(e) {
      const detail = e && e.detail
      if (!detail || !detail.code) {
        this.wxError = (detail && detail.errMsg) || '未授权手机号，无法完成绑定'
        return
      }
      if (!this.wxAuthKey) {
        this.wxError = '登录状态已失效，请重新点击微信登录'
        return
      }
      this.loading = true
      auth
        .wechatBindPhone(this.wxAuthKey, detail.code)
        .then(() => {
          this.finishWxLogin(this.wxAuthKey)
        })
        .catch((err) => {
          this.loading = false
          this.wxError = (err && err.msg) || '手机号绑定失败，请重试'
        })
    },
    finishWxLogin(key) {
      auth
        .wechatAuthLogin(key)
        .then((body) => {
          const d = body && body.data ? body.data : body
          const token = d.token || d.access_token
          if (!token) throw new Error('微信登录响应缺少 token')
          this.wxBindMode = false
          this.wxError = ''
          this.finishLogin(token, d.userInfo)
        })
        .catch((e) => {
          this.loading = false
          this.wxError = (e && e.msg) || '微信登录失败，请稍后重试'
        })
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
      if (!this.requireAgreed()) return
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
      if (!this.requireAgreed()) return
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
      if (!this.requireAgreed()) return
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
  font-size: 48rpx;
  font-weight: 800;
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
  margin-top: 48rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.6);
}
.agree-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
  padding: 12rpx 24rpx;
}
.agree-box {
  width: 32rpx;
  height: 32rpx;
  border-radius: 8rpx;
  border: 2rpx solid rgba(255, 255, 255, 0.5);
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.agree-box-checked {
  background: #c9a45c;
  border-color: #c9a45c;
}
.agree-tick {
  font-size: 22rpx;
  color: #fff;
  line-height: 1;
}
.agree-text {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.6);
}
.agree-links {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  justify-content: center;
  padding: 4rpx 24rpx 12rpx;
}
.agree-link {
  font-size: 22rpx;
  color: #c9a45c;
}
.wx-divider {
  display: flex;
  align-items: center;
  gap: 16rpx;
  margin: 36rpx 0 24rpx;
}
.wx-divider-line {
  flex: 1;
  height: 2rpx;
  background: rgba(255, 255, 255, 0.12);
}
.wx-divider-text {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.4);
}
.wx-login {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14rpx;
  border: 2rpx solid rgba(255, 255, 255, 0.35);
  border-radius: 20rpx;
  padding: 22rpx 0;
  background: rgba(255, 255, 255, 0.06);
}
.wx-login-disabled {
  opacity: 0.6;
}
.wx-login-icon {
  width: 40rpx;
  height: 40rpx;
  line-height: 40rpx;
  text-align: center;
  border-radius: 50%;
  background: #07c160;
  color: #fff;
  font-size: 24rpx;
  font-weight: 600;
  flex-shrink: 0;
}
.wx-login-text {
  font-size: 28rpx;
  color: rgba(255, 255, 255, 0.9);
  font-weight: 500;
}
.wx-phone-btn {
  margin-top: 20rpx;
  background: #07c160;
  border-radius: 20rpx;
  font-size: 28rpx;
  line-height: 2.6;
}
.wx-phone-btn::after {
  border: none;
}
.wx-phone-text {
  color: #fff;
  font-weight: 500;
}
.wx-error {
  margin-top: 16rpx;
  text-align: center;
  font-size: 24rpx;
  color: #f2b0a0;
}
</style>
