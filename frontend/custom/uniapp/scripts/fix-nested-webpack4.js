/**
 * postinstall 补丁（uni-app alpha 工具链的 npm 依赖树不完整，HBuilderX 自带完整树所以不受影响）。
 *
 * 补丁 1：给 @dcloudio/uni-mp-weixin 嵌套一份 webpack 4。
 *   原因：uni-mp-weixin 的 split-independent-chunks-plugin.js 引用了
 *   `webpack/lib/GraphHelpers`（仅 webpack 4 有），但 npm 会把顶层 webpack
 *   解析成 5.x（copy-webpack-plugin@14 需要 ^5）。嵌套目录会被 npm install
 *   清掉，所以每次 install 后都要重建。
 *   来源：复用顶层 webpack@4.47.0（package.json 已钉死版本）。
 *
 * 补丁 2：给 packages/vue-loader 嵌套 dcloud 自家的 component-compiler-utils。
 *   原因：vue-loader 的 templateLoader 会在编译产物后追加
 *   `export { render, staticRenderFns, recyclableRender, components }`，
 *   但它 require 的是顶层 stock 版 @vue/component-compiler-utils，
 *   其产物只有 render/staticRenderFns，导致全页 "Export 'recyclableRender'
 *   is not defined"。dcloud fork 版（packages/@vue/ 下）在产物里包含了
 *   recyclableRender/components，必须让 loader 解析到 fork。
 */
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')

function exists(p) {
  try {
    fs.accessSync(p)
    return true
  } catch (e) {
    return false
  }
}

function copyDir(src, dest, marker, label) {
  if (!exists(src)) {
    console.log('[postinstall] 跳过' + label + '：来源不存在 ' + src)
    return
  }
  if (exists(marker)) {
    console.log('[postinstall] ' + label + '已存在，跳过')
    return
  }
  fs.mkdirSync(path.dirname(dest), { recursive: true })
  fs.rmSync(dest, { recursive: true, force: true })
  fs.cpSync(src, dest, { recursive: true })
  console.log('[postinstall] 已安装' + label + ' -> ' + dest)
}

// 补丁 1：webpack 4（仅当顶层不是 4 时才需要；package.json 已钉顶层 ^4.47.0）
try {
  const topWebpack = require(path.join(root, 'node_modules', 'webpack', 'package.json')).version
  if (!String(topWebpack).startsWith('4.')) {
    copyDir(
      path.join(root, 'node_modules', '@vue', 'cli-service', 'node_modules', 'webpack'),
      path.join(root, 'node_modules', '@dcloudio', 'uni-mp-weixin', 'node_modules', 'webpack'),
      path.join(root, 'node_modules', '@dcloudio', 'uni-mp-weixin', 'node_modules', 'webpack', 'lib', 'GraphHelpers.js'),
      '嵌套 webpack4'
    )
  } else {
    console.log('[postinstall] 顶层 webpack 已是 4.x，无需嵌套')
  }
} catch (e) {
  console.log('[postinstall] 无法判断顶层 webpack 版本，跳过嵌套：' + e.message)
}

// 补丁 2：dcloud fork 的 component-compiler-utils
copyDir(
  path.join(root, 'node_modules', '@dcloudio', 'vue-cli-plugin-uni', 'packages', '@vue', 'component-compiler-utils'),
  path.join(root, 'node_modules', '@dcloudio', 'vue-cli-plugin-uni', 'packages', 'vue-loader', 'node_modules', '@vue', 'component-compiler-utils'),
  path.join(root, 'node_modules', '@dcloudio', 'vue-cli-plugin-uni', 'packages', 'vue-loader', 'node_modules', '@vue', 'component-compiler-utils', 'dist', 'compileTemplate.js'),
  '嵌套 component-compiler-utils(fork)'
)
