/**
 * 微信小程序支付工具（JSAPI，对齐 3010 ai-content 支付逻辑）
 */
import chamber from '@/api/chamber'

/**
 * 拉起微信小程序支付（wx.requestPayment）
 * @param {object} payParams 后端返回的 pay_params（appId/timeStamp/nonceStr/package/signType/paySign/out_trade_no）
 * @returns {Promise<{status:string,message?:string}>} paid/cancelled/failed
 */
export function requestWechatPayment(payParams) {
  return new Promise((resolve) => {
    if (!payParams || !payParams.package) {
      resolve({ status: 'failed', message: '支付参数缺失' })
      return
    }
    uni.requestPayment({
      provider: 'wxpay',
      timeStamp: payParams.timeStamp,
      nonceStr: payParams.nonceStr,
      package: payParams.package,
      signType: payParams.signType || 'RSA',
      paySign: payParams.paySign,
      success: () => resolve({ status: 'paid' }),
      fail: (err) => {
        const msg = (err && err.errMsg) || ''
        resolve({ status: msg.indexOf('cancel') >= 0 ? 'cancelled' : 'failed', message: msg })
      }
    })
  })
}

/**
 * 轮询支付单状态直到 paid/closed（回调确认后返回 paid）
 * @param {string} outTradeNo
 * @param {number} timeoutMs 默认 60s
 */
export function pollWechatPayStatus(outTradeNo, timeoutMs = 60000) {
  const deadline = Date.now() + timeoutMs
  return new Promise((resolve) => {
    const tick = () => {
      chamber
        .wechatPayStatus(outTradeNo)
        .then((data) => {
          if (data && data.status === 'paid') return resolve({ status: 'paid' })
          if (data && data.status === 'closed') return resolve({ status: 'closed' })
          if (Date.now() >= deadline) return resolve({ status: 'timeout' })
          setTimeout(tick, 2000)
        })
        .catch(() => {
          if (Date.now() >= deadline) return resolve({ status: 'timeout' })
          setTimeout(tick, 2000)
        })
    }
    tick()
  })
}
