/**
 * uni.request 统一封装
 * - 自动带 token（Authori-zation: Bearer xxx）
 * - 401 自动跳登录
 * - 错误统一 toast
 * - 支持 Idempotency-Key（幂等）
 * - 网络层失败（连接断开/超时）自动重试 2 次，抗瞬时抖动
 */
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '@/config/app'
import { checkLogin, toLogin, getToken } from '@/libs/login'

/** 网络层失败重试次数（fail 触发：DNS/TCP 失败、超时；请求未达服务器，重试安全） */
const MAX_RETRY = 2
/** 重试退避基数（ms）：第 n 次重试等待 600 * 2^(n-1) */
const RETRY_BASE_MS = 600

/**
 * @param {string} path 如 /chamber/v1/me/profile
 * @param {object} options { method, data, auth(默认true), idempotencyKey, silent(错误不弹toast), retry(网络层重试次数, 默认2) }
 */
export function request(path, options = {}) {
  const {
    method = 'GET',
    data = {},
    auth = true,
    idempotencyKey = '',
    silent = false,
    retry = MAX_RETRY
  } = options

  const headers = Object.assign({}, HEADER)

  if (auth && !checkLogin()) {
    toLogin()
    return Promise.reject({ status: 401, msg: '未登录' })
  }

  if (auth) {
    const token = getToken()
    if (token) headers[TOKENNAME] = 'Bearer ' + token
  }

  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey

  const doRequest = (attempt) =>
    new Promise((resolve, reject) => {
      uni.request({
        url: String(HTTP_REQUEST_URL).replace(/\/+$/, '') + path,
        method,
        header: headers,
        data,
        timeout: TIMEOUT,
        success(response) {
          const body = response.data || {}
          if (response.statusCode >= 200 && response.statusCode < 300) {
            // 统一解包：优先返回 body.data（统一响应结构 {status, msg, data}）
            resolve(body && body.data !== undefined ? body.data : body)
            return
          }
          // 认证失败
          if (response.statusCode === 401 && auth) {
            toLogin()
          }
          const msg = body.msg || body.message || '请求失败'
          const bizCode = body.code !== undefined ? body.code : (body.data && body.data.reason) || ''
          if (!silent && bizCode !== 'tier_required' && bizCode !== 'tier_expired') {
            // 门禁拦截不 toast（改走升级弹窗），其余正常 toast
            uni.showToast({ title: String(msg).slice(0, 30), icon: 'none' })
          }
          reject({ status: response.statusCode, code: bizCode, msg })
        },
        fail(err) {
          // 网络层失败：重试（带退避）；重试耗尽才报错
          if (attempt < retry) {
            const delay = RETRY_BASE_MS * Math.pow(2, attempt - 1)
            setTimeout(() => {
              doRequest(attempt + 1).then(resolve, reject)
            }, delay)
            return
          }
          const msg = (err && err.errMsg) || '网络异常'
          if (!silent) {
            uni.showToast({ title: String(msg).slice(0, 30), icon: 'none' })
          }
          reject({ status: -1, msg })
        }
      })
    })

  return doRequest(1)
}

/** 生成幂等 key */
export function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    const v = c === 'x' ? r : (r & 0x3) | 0x8
    return v.toString(16)
  })
}

/** 从响应里提取数组（兼容 items/list/data 结构） */
export function pickList(body) {
  // body 是 request 解包后的 data（可能是数组 / {items} / {list} / {data}）
  if (!body) return []
  if (Array.isArray(body)) return body
  if (Array.isArray(body.items)) return body.items
  if (Array.isArray(body.list)) return body.list
  const d = body.data
  if (d && Array.isArray(d.items)) return d.items
  if (d && Array.isArray(d.list)) return d.list
  if (Array.isArray(d)) return d
  return []
}
