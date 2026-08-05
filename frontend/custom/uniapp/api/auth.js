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
    request('/login', { method: 'POST', auth: false, data: { account: data.account, password: data.password } })
}

export default auth
