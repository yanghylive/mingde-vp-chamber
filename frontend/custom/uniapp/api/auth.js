/**
 * CRMEB 登录/公开接口
 */
import { request } from '@/common/request'
import { HTTP_REQUEST_URL } from '@/config/app'

export const auth = {
  /** 获取短信验证码 key */
  verifyCode: () => request('/verify_code', { auth: false }),
  /** 图形验证码地址（uni-app image 直接加载） */
  captchaUrl: (key) => HTTP_REQUEST_URL + '/sms_captcha?key=' + encodeURIComponent(key) + '&t=' + Date.now(),
  /** 发送短信验证码 */
  sendSmsCode: (data) =>
    request('/register/verify', {
      method: 'POST',
      auth: false,
      data: {
        phone: data.phone,
        type: data.type || 'login',
        key: data.key,
        captchaType: '',
        captchaVerification: data.captchaVerification
      }
    }),
  /** 验证码登录 */
  loginMobile: (data) =>
    request('/login/mobile', { method: 'POST', auth: false, data }),
  /** 账号密码登录 */
  loginByPassword: (data) =>
    request('/login', { method: 'POST', auth: false, data: { account: data.account, password: data.password } }),

  // ---- 微信一键登录（CRMEB v2 routine 链路）----
  /** wx.login 的 code 换取登录 key；bindPhone=true 表示需先绑定手机号 */
  wechatAuthType: (code) => request('/v2/routine/auth_type', { auth: false, data: { code } }),
  /** 根据 key 完成登录，返回 { token, expires_time, bindName } */
  wechatAuthLogin: (key) => request('/v2/routine/auth_login', { auth: false, data: { key } }),
  /** wx.getPhoneNumber 授权手机号绑定（新版 code 模式），绑定后仍需调 wechatAuthLogin */
  wechatBindPhone: (key, code) =>
    request('/v2/routine/binding_phone', { method: 'POST', auth: false, data: { key, code } })
}

export default auth
