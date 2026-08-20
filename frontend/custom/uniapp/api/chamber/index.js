/**
 * chamber 业务接口全量封装（与 Web 端 lib/api.ts 对齐）
 */
import { request, pickList, uuid } from '@/common/request'

export const chamber = {
  /** 公开 bootstrap */
  bootstrap: () => request('/chamber/v1/bootstrap', { auth: false }),
  /** 会员 bootstrap（登录后）；携带邀请码（S6 分享回流） */
  meBootstrap: () => {
    let invite = ''
    try {
      invite = uni.getStorageSync('invite_code') || ''
    } catch (e) {}
    return request('/chamber/v1/me/bootstrap', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: invite ? { invite_code: invite } : {}
    })
  },

  // ---- 我的 ----
  meProfile: () => request('/chamber/v1/me/profile'),
  meProfileUpdate: (data) => request('/chamber/v1/me/profile', { method: 'PATCH', data }),
  meMembership: () => request('/chamber/v1/me/membership'),
  meSettings: () => request('/chamber/v1/me/settings'),
  meSettingsUpdate: (data) => request('/chamber/v1/me/settings', { method: 'PUT', data }),
  meStats: () => request('/chamber/v1/me/stats'),
  meFriends: () => request('/chamber/v1/me/friends').then(pickList),
  meDistribution: () => request('/chamber/v1/me/distribution'),
  mePointsLedger: () => request('/chamber/v1/me/points/ledger').then(pickList),
  meOrders: () => request('/chamber/v1/me/orders').then(pickList),
  meNotifications: () => request('/chamber/v1/me/notifications').then(pickList),
  meNumbers: () => request('/chamber/v1/me/numbers').then(pickList),
  selectNumber: (id) => request('/chamber/v1/me/numbers/' + id + '/select', { method: 'POST' }),
  points: () =>
    request('/chamber/v1/me/points').then((body) => {
      // body 已解包为 data（可能是数字 / {points} / {balance}）
      if (body === null || body === undefined) return 0
      if (typeof body === 'number') return body
      return Number(body.points !== undefined ? body.points : body.balance) || 0
    }),

  // ---- 会员 ----
  membershipPlans: () => request('/chamber/v1/membership/plans', { auth: false }).then((b) => (b && b.plans) || []),
  /** 创建会员开通订单（幂等），返回 { order_no, payable_amount, currency, payment_required } */
  membershipCheckout: (payload) =>
    request('/chamber/v1/membership/checkouts', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: payload
    }),

  // ---- 活动 ----
  events: (params) => request('/chamber/v1/events', { auth: false, data: params || {} }).then(pickList),
  eventDetail: (id) => request('/chamber/v1/events/' + (id), { auth: false }),
  myEventRegistrations: () => request('/chamber/v1/me/event-registrations').then(pickList),
  registerEvent: (eventId, ticketId) =>
    request('/chamber/v1/events/' + (eventId) + '/registrations', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: ticketId ? { ticket_id: ticketId } : {}
    }),
  checkinEvent: (eventId) =>
    request('/chamber/v1/events/' + (eventId) + '/checkins', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: {}
    }),

  // ---- 大咖 ----
  experts: (params) => request('/chamber/v1/experts', { auth: false, data: params || {} }).then(pickList),
  expertDetail: (id) => request('/chamber/v1/experts/' + (id), { auth: false }),
  expertSlots: (id) => request('/chamber/v1/experts/' + (id) + '/slots', { auth: false }).then(pickList),
  createAppointment: (data) =>
    request('/chamber/v1/experts/' + (data.expert_id) + '/appointments', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: { slot_id: data.slot_id, mode: data.mode, topic: data.topic || '', message: data.message || '' }
    }),
  myAppointments: () => request('/chamber/v1/me/appointments').then(pickList),
  cancelAppointment: (id) =>
    request('/chamber/v1/experts/appointments/' + id + '/cancel', { method: 'POST' }),

  // ---- 商城 ----
  products: (params) => request('/chamber/v1/products', { auth: false, data: params || {} }).then(pickList),
  pointsPaths: () => request('/chamber/v1/points/paths', { auth: false }).then((b) => (b && b.items) || []),
  exchangeProduct: (id, pointsCost, cashCost) =>
    request('/chamber/v1/products/' + (id) + '/exchange', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: { points_cost: Number(pointsCost || 0), cash_cost: String(cashCost || '0.00') }
    }),

  // ---- 微信支付（APIv3 直连，与 3010 ai-content 同一套逻辑）----
  /** 创建支付单（JSAPI 拉起支付）。membership 传 order_no；exchange 传 business_ref=兑换订单 id */
  wechatPayOrder: (payload) =>
    request('/chamber/v1/wechat-pay/orders', {
      method: 'POST',
      data: payload
    }),
  /** 查询支付单状态 */
  wechatPayStatus: (outTradeNo) => request('/chamber/v1/wechat-pay/orders/' + encodeURIComponent(outTradeNo)),

  // ---- 微信小程序虚拟支付（Midas / 虚拟商品合规通道）----
  /** 创建虚拟支付单，返回 { signData, paySig, signature, mode } 供 wx.requestVirtualPayment */
  vpayCreateOrder: (payload) =>
    request('/chamber/v1/vpay/orders', {
      method: 'POST',
      data: payload
    }),
  /** 虚拟支付配置就绪检查（公开） */
  vpayConfigStatus: () => request('/chamber/v1/vpay/config-status', { auth: false }),

  // ---- 站点配置 ----
  siteConfig: () => request('/chamber/v1/site-config', { auth: false }),

  // ---- 毕业认证 ----
  myGraduateVerification: () => request('/chamber/v1/me/graduate-verifications'),
  submitGraduateVerification: (data) =>
    request('/chamber/v1/me/graduate-verifications', { method: 'POST', data }),

  // ---- 小薇认知教练 ----
  coachingToday: () => request('/chamber/v1/coaching/today', { auth: false }),
  coachingMorning: (data) => request('/chamber/v1/coaching/morning', { method: 'POST', data }),
  coachingRespond: (data) => request('/chamber/v1/coaching/respond', { method: 'POST', data }),
  coachingEvening: (data) => request('/chamber/v1/coaching/evening', { method: 'POST', data }),
  coachingStatus: () => request('/chamber/v1/coaching/status', { auth: false }),
}

export default chamber
