/**
 * 应用配置
 * HTTP_REQUEST_URL：后端 API 基址（生产 md.kaypal.cn）
 * 开发时可改为本地/测试环境
 */
const HTTP_REQUEST_URL = 'https://md.kaypal.cn/api'

const HEADER = {
  'content-type': 'application/json',
  Accept: 'application/json'
}

const TOKENNAME = 'Authori-zation'

// 单次请求超时：chamber API 均为 JSON 小响应（正常 <1s），15s 足够；
// 网络抖动时由 request.js 自动重试兜底，避免长时间白屏
const TIMEOUT = 15000

// 微信审核整改（2026-08-19）：临时下掉小程序内虚拟商品现金支付入口
// （会籍购买 / 付费活动报名 / 积分兑换补差）。审核通过后置回 false 即恢复。
const VIRTUAL_PAY_DISABLED = true

// 微信审核整改（2026-08-19）：深度合成类目/合作协议未就绪前，临时下掉 AI 问答服务入口
// （AI 分身训练 / 平台 AI 助手 / 大咖 AI 对话 / 小薇问答 / AI 生态）。资质到位后置回 false 即恢复。
const AI_DISABLED = true

module.exports = {
  HTTP_REQUEST_URL,
  HEADER,
  TOKENNAME,
  TIMEOUT,
  VIRTUAL_PAY_DISABLED,
  AI_DISABLED
}
