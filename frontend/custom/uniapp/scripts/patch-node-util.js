// Node 18+（本机为 Node 24）移除了 util 上的一批历史类型判断 API：
//   util.isArray / isBoolean / isBuffer / isDate / isError / isFunction /
//   isNull / isNullOrUndefined / isNumber / isObject / isPrimitive /
//   isRegExp / isString / isSymbol / isUndefined
// 它们早在 Node 4 就被标记弃用，但 vue-cli-service(webpack4) 这条老工具链
// 仍在调用。生产构建会在 postcss-urlrewrite 里炸：
//   TypeError: util.isRegExp is not a function
//     at node_modules/postcss-urlrewrite/lib/urlrewrite.js:25
// （本地 dev 构建不走这条 CSS 资源重写路径，所以只有 build 会失败。）
//
// 这里按 Node 官方文档给出的等价写法补齐这些 API，只影响构建期行为，
// 不改变产物内容。通过 NODE_OPTIONS=--require 在 webpack 加载前生效。

const util = require('util')

if (!util.__mingdeLegacyPatched) {
  const legacy = {
    isArray: Array.isArray,
    isBoolean: (v) => typeof v === 'boolean',
    isBuffer: Buffer.isBuffer,
    isDate: (v) => v instanceof Date,
    isError: (v) => v instanceof Error,
    isFunction: (v) => typeof v === 'function',
    isNull: (v) => v === null,
    isNullOrUndefined: (v) => v === null || v === undefined,
    isNumber: (v) => typeof v === 'number',
    isObject: (v) => typeof v === 'object' && v !== null,
    isPrimitive: (v) => v === null || (typeof v !== 'object' && typeof v !== 'function'),
    isRegExp: (v) => v instanceof RegExp,
    isString: (v) => typeof v === 'string',
    isSymbol: (v) => typeof v === 'symbol',
    isUndefined: (v) => v === undefined,
  }

  const patched = []
  for (const name of Object.keys(legacy)) {
    if (typeof util[name] !== 'function') {
      util[name] = legacy[name]
      patched.push(name)
    }
  }

  util.__mingdeLegacyPatched = true
  console.log(
    patched.length
      ? '[patch-node-util] 已补齐 util 历史 API: ' + patched.join(', ')
      : '[patch-node-util] util 历史 API 无需补齐'
  )
}
