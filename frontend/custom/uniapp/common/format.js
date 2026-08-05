/**
 * 格式化工具（与 Web 端 lib/format.ts 对齐）
 */

/** 金额格式化：¥1,980.00（对齐 H5 formatMoney 千分位） */
export function formatMoney(n, withSymbol = true) {
  const num = Number(n || 0)
  if (isNaN(num)) return '0.00'
  // 千分位 + 2 位小数
  const parts = num.toFixed(2).split('.')
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const fixed = parts.join('.')
  return withSymbol ? '¥' + fixed : fixed
}

/** 积分格式化：1,280（对齐 H5 formatPoints 千分位） */
export function formatPoints(n) {
  const num = Number(n || 0)
  if (isNaN(num)) return '0'
  return num.toLocaleString('zh-CN')
}

/** 时间戳 → 日期字符串 */
export function toDate(ts, fmt) {
  if (!ts) return ''
  const d = new Date(Number(ts) * 1000)
  if (isNaN(d.getTime())) return ''
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  const h = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  if (fmt === 'datetime') return y + '-' + m + '-' + day + ' ' + h + ':' + min
  if (fmt === 'month') return y + '-' + m
  return y + '-' + m + '-' + day
}

/** 中文日期时间：2026/8/12 23:00（对齐 H5 formatDateTime zh-CN） */
export function fmtZhDateTime(ts) {
  const d = new Date(Number(ts || 0) * 1000)
  if (isNaN(d.getTime())) return ''
  const y = d.getFullYear()
  const m = d.getMonth() + 1
  const day = d.getDate()
  const h = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  return y + '/' + m + '/' + day + ' ' + h + ':' + min
}

/** 中文月日时：8月12日 23:00（对齐 H5 EventsPage.fmtDate.date） */
export function fmtZhMonthDay(ts) {
  const d = new Date(Number(ts || 0) * 1000)
  if (isNaN(d.getTime())) return ''
  const m = d.getMonth() + 1
  const day = d.getDate()
  const h = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  return m + '月' + day + '日 ' + h + ':' + min
}

/** 仅时分：HH:mm（对齐 H5 format(end,'HH:mm')） */
export function fmtHHmm(ts) {
  const d = new Date(Number(ts || 0) * 1000)
  if (isNaN(d.getTime())) return ''
  return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
}

/** 数字千分位 */
export function formatNumber(n) {
  const num = Number(n || 0)
  return num.toLocaleString('en-US')
}

/** 图片地址补全（相对路径 → 绝对） */
export function resolveImage(url) {
  if (!url) return ''
  if (/^https?:\/\//.test(url)) return url
  return 'https://md.kaypal.cn' + url
}
