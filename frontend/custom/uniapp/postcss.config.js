const path = require('path')

module.exports = {
  parser: require('postcss-comment'),
  plugins: [
    require('postcss-import')({
      resolve(id, basedir, importOptions) {
        if (id.startsWith('~@/')) {
          return path.resolve(process.env.UNI_INPUT_DIR, id.substr(3))
        } else if (id.startsWith('@/')) {
          return path.resolve(process.env.UNI_INPUT_DIR, id.substr(2))
        } else if (id.startsWith('/') && !id.startsWith('//')) {
          return path.resolve(process.env.UNI_INPUT_DIR, id.substr(1))
        }
        return id
      }
    }),
    require('autoprefixer')({
      remove: process.env.UNI_PLATFORM !== 'h5'
    }),
    // Vue2 项目用 PostCSS 7 兼容版（index.v3.js）；默认 index.js 是 PostCSS 8 版，
    // 在 postcss-loader@3 + postcss@7 链路上会报 requires PostCSS 8。
    require('@dcloudio/vue-cli-plugin-uni/packages/postcss/index.v3.js')
  ]
}
