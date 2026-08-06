/**
 * uni-app 构建配置
 * 关键：图片不内联 base64 —— 微信小程序 wxss background-image 的 base64 渲染异常（图标显示为暗色块/空白），
 * 必须用本地文件引用 url("/static/icons/xxx.png")
 */
module.exports = {
  chainWebpack: (config) => {
    // 图片/媒体/字体全部不内联，保持文件引用
    ;['images', 'media', 'fonts'].forEach((type) => {
      config.module.rule(type).use('url-loader').tap((options) => {
        return Object.assign({}, options, { limit: 1 })
      })
    })
  }
}
