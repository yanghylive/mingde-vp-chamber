/**
 * 微信小程序虚拟支付工具（wx.requestVirtualPayment / Midas）
 * 虚拟商品（会籍 / 积分补差 / 付费活动）必须走虚拟支付通道，微信审核合规要求。
 */
import chamber from '@/api/chamber'

/**
 * 拉起微信小程序虚拟支付
 * @param {object} params 后端 vpayCreateOrder 返回 { signData, paySig, signature, mode }
 * @returns {Promise<{status:string,message?:string}>} paid/cancelled/failed
 */
export function requestVirtualPayment(params) {
  return new Promise((resolve) => {
    if (!params || !params.signData) {
      resolve({ status: 'failed', message: '支付参数缺失' })
      return
    }
    uni.requestVirtualPayment({
      signData: params.signData,
      paySig: params.paySig,
      signature: params.signature,
      mode: params.mode || 'short_series_goods',
      success: () => resolve({ status: 'paid' }),
      fail: (err) => {
        const msg = (err && err.errMsg) || ''
        resolve({ status: msg.indexOf('cancel') >= 0 ? 'cancelled' : 'failed', message: msg })
      }
    })
  })
}

/**
 * 虚拟支付下单并拉起支付（一步封装）
 * @param {object} payload { business_type, order_no?, business_ref?, idempotency_key }
 * @returns {Promise<{status:string,message?:string}>}
 */
export async function vpayAndPay(payload) {
  let order
  try {
    order = await chamber.vpayCreateOrder(payload)
  } catch (e) {
    return { status: 'failed', message: (e && e.msg) || '下单失败，请稍后重试' }
  }
  if (!order || !order.signData) {
    return { status: 'failed', message: '虚拟支付未配置完成，暂不可用' }
  }
  return requestVirtualPayment(order)
}
