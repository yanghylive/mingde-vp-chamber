/**
 * chamber 业务接口全量封装（与 Web 端 lib/api.ts 对齐）
 */
import { request, pickList, uuid } from '@/common/request'

export const chamber = {
  /** 公开 bootstrap */
  bootstrap: () => request('/chamber/v1/bootstrap', { auth: false }),
  /** 会员 bootstrap（登录后） */
  meBootstrap: () =>
    request('/chamber/v1/me/bootstrap', {
      method: 'POST',
      idempotencyKey: uuid(),
      data: {}
    }),

  // ---- 我的 ----
  meProfile: () => request('/chamber/v1/me/profile'),
  meProfileUpdate: (data) => request('/chamber/v1/me/profile', { method: 'PATCH', data }),
  meMembership: () => request('/chamber/v1/me/membership'),
  meStats: () => request('/chamber/v1/me/stats'),
  meFriends: () => request('/chamber/v1/me/friends').then(pickList),
  meDistribution: () => request('/chamber/v1/me/distribution'),
  mePointsLedger: () => request('/chamber/v1/me/points/ledger').then(pickList),
  meOrders: () => request('/chamber/v1/me/orders').then(pickList),
  meNotifications: () => request('/chamber/v1/me/notifications').then(pickList),
  points: () =>
    request('/chamber/v1/me/points').then((body) => {
      if (body === null || body === undefined) return 0
      if (typeof body === 'number') return body
      const d = body.data
      if (typeof d === 'number') return d
      return Number(d && (d.points || body.points)) || 0
    }),

  // ---- 会员 ----
  membershipPlans: () => request('/chamber/v1/membership/plans').then((b) => (b && b.data && b.data.plans) || []),

  // ---- 活动 ----
  events: () => request('/chamber/v1/events').then(pickList),
  eventDetail: (id) => request(`/chamber/v1/events/${id}`),
  myEventRegistrations: () => request('/chamber/v1/me/event-registrations').then(pickList),
  registerEvent: (eventId, ticketId) =>
    request(`/chamber/v1/events/${eventId}/register`, {
      method: 'POST',
      idempotencyKey: uuid(),
      data: ticketId ? { ticket_id: ticketId } : {}
    }),
  checkinEvent: (eventId) =>
    request(`/chamber/v1/events/${eventId}/checkin`, {
      method: 'POST',
      idempotencyKey: uuid(),
      data: {}
    }),

  // ---- 大咖 ----
  experts: () => request('/chamber/v1/experts').then(pickList),
  expertDetail: (id) => request(`/chamber/v1/experts/${id}`),
  expertSlots: (id) => request(`/chamber/v1/experts/${id}/slots`).then(pickList),
  createAppointment: (data) =>
    request(`/chamber/v1/experts/${data.expert_id}/appointments`, {
      method: 'POST',
      idempotencyKey: uuid(),
      data: { slot_id: data.slot_id, mode: data.mode, topic: data.topic || '', message: data.message || '' }
    }),

  // ---- 商城 ----
  products: () => request('/chamber/v1/products').then(pickList),
  pointsPaths: () => request('/chamber/v1/points/paths').then((b) => (b && b.items) || []),
  exchangeProduct: (id, pointsCost, cashCost) =>
    request(`/chamber/v1/products/${id}/exchange`, {
      method: 'POST',
      idempotencyKey: uuid(),
      data: { points_cost: Number(pointsCost || 0), cash_cost: String(cashCost || '0.00') }
    }),

  // ---- 站点配置 ----
  siteConfig: () => request('/chamber/v1/site-config', { auth: false }),

  // ---- 毕业认证 ----
  myGraduateVerification: () => request('/chamber/v1/me/graduate-verifications'),
  submitGraduateVerification: (data) =>
    request('/chamber/v1/me/graduate-verifications', { method: 'POST', data })
}

export default chamber
