/**
 * uni.request 统一封装
 * - 自动带 token（Authori-zation: Bearer xxx）
 * - 401 自动跳登录
 * - 错误统一 toast
 * - 支持 Idempotency-Key（幂等）
 * - 网络层失败（连接断开/超时）自动重试 2 次，抗瞬时抖动
 */
import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '@/config/app'
import { checkLogin, toLogin, getToken, invalidateToken } from '@/libs/login'

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
          // 认证失败：清掉本地失效 token 再跳登录，避免 401 死循环
          if (response.statusCode === 401 && auth) {
            invalidateToken()
            toLogin()
          }
          const rawMsg = body.msg || body.message || ''
          const bizCode = body.code !== undefined ? body.code : (body.data && body.data.reason) || ''
          const msg = friendlyMessage(response.statusCode, bizCode, rawMsg)
          if (!silent && bizCode !== 'tier_required' && bizCode !== 'tier_expired') {
            // 门禁拦截不 toast（改走升级弹窗），其余正常 toast
            uni.showToast({ title: String(msg).slice(0, 30), icon: 'none' })
          }
          reject({ status: response.statusCode, code: bizCode, msg, raw: rawMsg })
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
          const msg = friendlyMessage(-1, '', (err && err.errMsg) || '')
          if (!silent) {
            uni.showToast({ title: String(msg).slice(0, 30), icon: 'none' })
          }
          reject({ status: -1, msg })
        }
      })
    })

  return doRequest(1)
}

/**
 * 后端错误消息 → 面向用户的中文提示。
 * 审核环境/线上曾直接透出 "Not found" / "Authentication required" 等英文，
 * 既不符合小程序体验，也会让微信审核判定为「页面报错」。这里统一兜底成中文。
 */
const FRIENDLY_BY_CODE = {
  authentication_required: '请先登录',
  permission_denied: '没有访问权限',
  tenant_scope_denied: '当前账号不在该空间内',
  member_not_found: '会员不存在',
  idempotency_key_required: '缺少幂等标识，请重试',
  idempotency_conflict: '请求已提交，请勿重复操作',
  request_validation_failed: '请求参数有误',
  resource_state_conflict: '当前状态不可执行该操作',
  login_code_required: '微信登录态缺失，请重进小程序重试',
  session_key_missing: '微信登录态获取失败，请重进小程序重试',
  // 活动报名资格类原因（与后端 EventEligibility::reason 一致），避免笼统的通用文案
  event_not_open: '活动暂未开放',
  signup_not_open: '报名尚未开始',
  signup_closed: '报名已经截止',
  event_started: '活动已经开始',
  event_full: '名额已满',
  membership_tier_required: '当前会籍等级不满足要求',
  membership_verification_required: '完成毕业认证后可报名',
  channel_not_eligible: '当前商会渠道不可报名',
  points_required: '积分不足',
  role_required: '当前会员身份不满足要求'
}

const FRIENDLY_BY_STATUS = {
  401: '请先登录',
  403: '没有访问权限',
  404: '内容不存在或已下线',
  409: '当前状态不可执行该操作',
  422: '请求参数有误',
  500: '服务开小差了，请稍后重试',
  503: '服务暂时不可用，请稍后重试',
  '-1': '网络不稳定，请检查网络后重试'
}

function friendlyMessage(status, bizCode, rawMsg) {
  if (bizCode && FRIENDLY_BY_CODE[bizCode]) return FRIENDLY_BY_CODE[bizCode]
  const byStatus = FRIENDLY_BY_STATUS[String(status)]
  if (byStatus) return byStatus
  // 后端已是中文时透传，否则兜底（避免英文/HTML 泄漏到 toast）
  const text = String(rawMsg || '').trim()
  if (text && /[一-龥]/.test(text)) return text
  return FRIENDLY_BY_STATUS['500']
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
