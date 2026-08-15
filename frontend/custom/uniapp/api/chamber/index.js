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
  points: () =>
    request('/chamber/v1/me/points').then((body) => {
      // body 已解包为 data（可能是数字 / {points} / {balance}）
      if (body === null || body === undefined) return 0
      if (typeof body === 'number') return body
      return Number(body.points !== undefined ? body.points : body.balance) || 0
    }),

  // ---- 会员 ----
  membershipPlans: () => request('/chamber/v1/membership/plans').then((b) => (b && b.plans) || []),
  /** 创建会员开通订单（幂等），返回 { order_no, payable_amount, currency, payment_required } */
  membershipCheckout: (payload) =>
    request('/chamber/v1/membership/checkouts', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: payload
    }),

  // ---- 活动 ----
  events: (params) => request('/chamber/v1/events', { data: params || {} }).then(pickList),
  eventDetail: (id) => request('/chamber/v1/events/' + (id)),
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
  experts: (params) => request('/chamber/v1/experts', { data: params || {} }).then(pickList),
  expertDetail: (id) => request('/chamber/v1/experts/' + (id)),
  expertSlots: (id) => request('/chamber/v1/experts/' + (id) + '/slots').then(pickList),
  createAppointment: (data) =>
    request('/chamber/v1/experts/' + (data.expert_id) + '/appointments', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: { slot_id: data.slot_id, mode: data.mode, topic: data.topic || '', message: data.message || '' }
    }),

  // ---- 商城 ----
  products: (params) => request('/chamber/v1/products', { data: params || {} }).then(pickList),
  pointsPaths: () => request('/chamber/v1/points/paths').then((b) => (b && b.items) || []),
  exchangeProduct: (id, pointsCost, cashCost) =>
    request('/chamber/v1/products/' + (id) + '/exchange', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: { points_cost: Number(pointsCost || 0), cash_cost: String(cashCost || '0.00') }
    }),

  // ---- 站点配置 ----
  siteConfig: () => request('/chamber/v1/site-config', { auth: false }),

  // ---- 毕业认证 ----
  myGraduateVerification: () => request('/chamber/v1/me/graduate-verifications'),
  submitGraduateVerification: (data) =>
    request('/chamber/v1/me/graduate-verifications', { method: 'POST', data }),

  // ---- 小薇认知教练 ----
  coachingToday: () => request('/chamber/v1/coaching/today'),
  coachingMorning: (data) => request('/chamber/v1/coaching/morning', { method: 'POST', data }),
  coachingRespond: (data) => request('/chamber/v1/coaching/respond', { method: 'POST', data }),
  coachingEvening: (data) => request('/chamber/v1/coaching/evening', { method: 'POST', data }),
  coachingStatus: () => request('/chamber/v1/coaching/status'),
}

export default chamber
