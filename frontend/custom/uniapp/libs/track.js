/**
 * 行为埋点（P0 数据可观测）
 * 关键行为（onboard/task/chat/gate/share）上报服务端，让留存/转化可度量。
 * 用法：track('event_name', { k: v })
 * 批量场景可调 trackBatch([{event, page, data}, ...])
 */
import { HTTP_REQUEST_URL } from '@/config/app'

const EVENT_URL = HTTP_REQUEST_URL + '/chamber/v1/client/events'

/** 从本地存储尽力取 uid（未登录为 0） */
function currentUid() {
  try {
    const u = uni.getStorageSync('userInfo')
    if (u && (u.uid || u.id)) return Number(u.uid || u.id) || 0
    const uid = uni.getStorageSync('uid')
    return Number(uid) || 0
  } catch (e) {
    return 0
  }
}

function currentPage() {
  try {
    const pages = getCurrentPages()
    const p = pages && pages[pages.length - 1]
    return p ? p.route : ''
  } catch (e) {
    return ''
  }
}

/** 单事件上报（异步，不阻塞业务，失败静默） */
export function track(event, data) {
  if (!event) return
  try {
    uni.request({
      url: EVENT_URL,
      method: 'POST',
      header: { 'Content-Type': 'application/json' },
      data: {
        event: String(event),
        page: currentPage(),
        uid: currentUid(),
        data: data || {}
      },
      timeout: 5000
    })
  } catch (e) {}
}

/** 批量上报（适合一次动作触发多个事件） */
export function trackBatch(events) {
  if (!Array.isArray(events) || !events.length) return
  try {
    uni.request({
      url: EVENT_URL,
      method: 'POST',
      header: { 'Content-Type': 'application/json' },
      data: {
        uid: currentUid(),
        events: events.map((e) => ({
          event: String(e.event || ''),
          page: e.page || currentPage(),
          data: e.data || {}
        }))
      },
      timeout: 5000
    })
  } catch (e) {}
}
