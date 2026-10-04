/**
 * 登录态检查
 * 小程序端 token 存 storage（与 Web 端 CRMEB token 体系一致）
 */
export function checkLogin() {
  const token = uni.getStorageSync('token')
  return !!token
}

export function toLogin() {
  // 已在登录页则不再 push，避免 401 连续触发时页面栈无限增长
  const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
  const current = pages.length ? pages[pages.length - 1] : null
  const route = current && (current.route || '')
  if (route === 'pages/login/index') return
  uni.navigateTo({ url: '/pages/login/index' })
}

/**
 * 401 时的失效 token 处理：清掉本地 token 再跳登录。
 * 否则「本地有 token 但后端已失效」会形成 401 死循环——checkLogin() 一直为真，
 * toLogin() 也永远不跳转，用户被卡在反复报错的页面上（微信审核「白屏/无法加载」）。
 */
export function invalidateToken() {
  try {
    uni.removeStorageSync('token')
    uni.removeStorageSync('userInfo')
  } catch (e) {}
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
