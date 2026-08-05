/**
 * 会员等级模型（L1-L4）
 * 与 Web 端 lib/ladder.ts 对齐
 */
export const TIERS = [
  {
    tier: 1,
    name: '普通会员',
    short: 'L1',
    tagline: '加入共同体，开启成长之旅',
    tone: 'linear-gradient(135deg,#8a94a3,#6b7686)',
    rights: ['参加线下活动', '大咖线上内容', '会员交流互动']
  },
  {
    tier: 2,
    name: '付费会员',
    short: 'L2',
    tagline: '解锁线上 1v1 与积分生态',
    tone: 'linear-gradient(135deg,#d98a2d,#b8751d)',
    rights: ['大咖线上 1v1 预约', '积分商城兑换', '好友申请', '精选活动优先席位']
  },
  {
    tier: 3,
    name: '高会会员',
    short: 'L3',
    tagline: '线下深度链接与资源赋能',
    tone: 'linear-gradient(135deg,#c2410c,#9a3412)',
    rights: ['大咖线下 1v1 预约', '分销码权益', '闭门私享会', '项目路演优先']
  },
  {
    tier: 4,
    name: '核心伙伴',
    short: 'L4',
    tagline: '项目路演优先 · AI 陪跑席位',
    tone: 'linear-gradient(135deg,#ff8a3d,#f25c2a)',
    rights: ['项目路演优先', 'AI 陪跑席位', '名企 AI 咨询', '理事圆桌闭门会', '生态共创资源池']
  }
]

/** tier 字符串 → 数字（L2/2/'2' → 2） */
export function tierToNumber(tier, fallback = 1) {
  if (tier === undefined || tier === null || tier === '') return fallback
  if (typeof tier === 'number') return tier
  const s = String(tier).toUpperCase()
  if (s.startsWith('L')) {
    const n = parseInt(s.slice(1), 10)
    return isNaN(n) ? fallback : n
  }
  const n = parseInt(s, 10)
  return isNaN(n) ? fallback : n
}

export function tierLabel(tier) {
  const t = TIERS.find((x) => x.tier === tierToNumber(tier))
  return t ? t.name : '普通会员'
}

/** 用站点配置覆盖 TIERS（名+权益） */
export function applyTierConfig(config) {
  const ladder = (config && config.member_ladder) || []
  if (!Array.isArray(ladder) || ladder.length === 0) return TIERS
  return TIERS.map((t) => {
    const c = ladder.find((x) => Number(x.tier) === t.tier)
    if (!c) return t
    return Object.assign({}, t, {
      name: c.name || t.name,
      rights: Array.isArray(c.rights) && c.rights.length > 0 ? c.rights : t.rights
    })
  })
}
