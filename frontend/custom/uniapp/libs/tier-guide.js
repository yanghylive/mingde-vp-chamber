import { track } from '@/libs/track'

/**
 * 会员等级门禁引导：命中 tier_required / tier_expired 时弹升级弹窗
 * 供 大咖预约 / 商城兑换 / 好友 等门禁点共用。
 * 用法：catch (e) { if (!tierGuide(e)) { /* 其他错误处理 *\/ } }
 * 返回 true = 已处理（门禁引导），false = 非门禁错误（调用方自行处理）
 */
export function tierGuide(e) {
  const code = (e && e.code) || ''
  if (code !== 'tier_required' && code !== 'tier_expired') return false

  const tier = extractTier(e)
  const isExpired = code === 'tier_expired'
  const title = isExpired ? '会员已到期' : '需要更高级别会员'
  const content = isExpired
    ? '你的会员等级已到期，大咖预约 / 积分兑换等权益已暂停。续费后即可继续使用。'
    : '升级 L' + (tier || 2) + ' 会员后，即可使用大咖 1v1 预约、积分商城兑换、好友互动等专属权益。'

  track('gate_blocked', { reason: code, tier: tier || 0 })

  uni.showModal({
    title,
    content,
    confirmText: '去开通',
    cancelText: '知道了',
    success(res) {
      if (res.confirm) {
        track('gate_upgrade_click', { reason: code })
        uni.navigateTo({ url: '/pages/membership/index' })
      }
    }
  })
  return true
}

/** 从后端文案 "需要 L2 及以上会员" 提取所需等级数字 */
function extractTier(e) {
  const msg = (e && e.msg) || ''
  const m = msg.match(/L([234])/)
  return m ? Number(m[1]) : 0
}
