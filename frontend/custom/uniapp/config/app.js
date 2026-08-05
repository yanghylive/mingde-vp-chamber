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

const TIMEOUT = 30000

module.exports = {
  HTTP_REQUEST_URL,
  HEADER,
  TOKENNAME,
  TIMEOUT
}
