import Vue from 'vue'
import App from './App'
import store from './store'

Vue.config.productionTip = false
Vue.prototype.$store = store

// ============ S5 全局错误上报 ============
// Vue 组件错误 + 页面 JS 异常 → 上报服务端日志，替代"等用户贴 console"
const ERROR_URL = 'https://md.kaypal.cn/api/chamber/v1/client/errors'

function reportClientError(msg, stack, page) {
  if (!msg) return
  try {
    let uid = 0
    try {
      const u = uni.getStorageSync('userInfo')
      if (u && (u.uid || u.id)) uid = Number(u.uid || u.id) || 0
    } catch (e) {}
    uni.request({
      url: ERROR_URL,
      method: 'POST',
      header: { 'Content-Type': 'application/json' },
      data: {
        uid: uid,
        msg: String(msg).slice(0, 300),
        stack: String(stack || '').slice(0, 800),
        page: String(page || '').slice(0, 120),
        platform: 'mp-weixin'
      },
      timeout: 8000
    })
  } catch (e) {}
}

Vue.config.errorHandler = (err, vm, info) => {
  const page = (vm && vm.$options && vm.$options.__file) || ''
  reportClientError(err && err.message, err && err.stack, page + ' ' + info)
}

App.mpType = 'app'

const app = new Vue({
  ...App,
  store
})
app.$mount()

if (typeof wx !== 'undefined') {
  wx.onError((err) => {
    reportClientError('wx.onError', err)
  })
}
