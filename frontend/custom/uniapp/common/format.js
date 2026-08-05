/**
 * 格式化工具（与 Web 端 lib/format.ts 对齐）
 */

/** 金额格式化（默认带 ¥） */
export function formatMoney(n, withSymbol = true) {
  const num = Number(n || 0)
  if (isNaN(num)) return '0.00'
  const fixed = num.toFixed(2)
  return withSymbol ? '¥' + fixed : fixed
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
