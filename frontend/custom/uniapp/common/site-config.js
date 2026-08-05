/**
 * 站点配置读取（GET /chamber/v1/site-config）
 * - 缓存到 storage（5 分钟）
 * - 失败回退默认
 */
import { request } from './request'

const CACHE_KEY = 'site_config_cache'
const CACHE_TTL = 5 * 60 * 1000

let pending = null

export function fetchSiteConfig(force = false) {
  const cached = uni.getStorageSync(CACHE_KEY)
  if (!force && cached && cached.t && Date.now() - cached.t < CACHE_TTL) {
    return Promise.resolve(cached.data)
  }
  if (pending) return pending
  pending = request('/chamber/v1/site-config', { auth: false, silent: true })
    .then((body) => {
      const data = body && body.data ? body.data : body
      uni.setStorageSync(CACHE_KEY, { t: Date.now(), data })
      return data
    })
    .catch(() => null)
    .finally(() => {
      pending = null
    })
  return pending
}
