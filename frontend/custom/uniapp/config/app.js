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

module.exports = {
  HTTP_REQUEST_URL,
  HEADER,
  TOKENNAME,
  TIMEOUT
}
