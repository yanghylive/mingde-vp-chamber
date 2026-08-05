/**
 * 登录态检查
 * 小程序端 token 存 storage（与 Web 端 CRMEB token 体系一致）
 */
export function checkLogin() {
  const token = uni.getStorageSync('token')
  return !!token
}

export function toLogin() {
  if (!checkLogin()) {
    uni.navigateTo({ url: '/pages/login/index' })
  }
}

export function getToken() {
  return uni.getStorageSync('token') || ''
}

export function setLogin(token, userInfo) {
  uni.setStorageSync('token', token)
  if (userInfo) {
    uni.setStorageSync('userInfo', userInfo)
  }
}

export function logout() {
  uni.removeStorageSync('token')
  uni.removeStorageSync('userInfo')
}
