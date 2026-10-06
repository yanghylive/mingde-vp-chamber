// 本机（arm64 macOS）没有可用的 fsevents 原生二进制：
// node_modules/fsevents 是 1.2.13，其入口用 bindings('fse') 加载
// fsevents.node，该文件不存在，于是 require('fsevents') 并不抛错，
// 但只导出 getInfo/FSEvents/Constants 桩对象，没有 watch()。
// watchpack 用的 chokidar 3.6.0 走 fsevents 路径时会调
// fsevents.watch()，于是 --watch 持续抛
// "TypeError: fsevents.watch is not a function" 且从不真正监听。
//
// chokidar 自己有 usePolling 开关，但在 webpack4 这条链路上打不开：
//   - webpack/lib/node/NodeWatchFileSystem 构造时创建 Watchpack，
//     watch() 只传 files/dirs/startTime 三个参数，把 webpack 的
//     watchOptions 丢掉；
//   - watchpack 只认 options.poll（DirectoryWatcher 会转成
//     chokidar 的 usePolling + interval）；
//   - CHOKIDAR_USEPOLLING 环境变量也不生效：chokidar 3.6.0 先算
//     opts.useFsEvents = !opts.usePolling，再读环境变量，此时已定。
//
// 所以这里在 watchpack 创建 DirectoryWatcher 之前，强制给它的
// watcherOptions 注入 poll，改走轮询。仅用于本地开发监听，
// 不影响产物内容（只影响文件变化检测方式）。

const path = require('path')

// require.resolve('watchpack') 指向 .../watchpack/lib/watchpack.js，
// DirectoryWatcher 与它同级。
const DirectoryWatcher = require.resolve(
  path.join(path.dirname(require.resolve('watchpack')), 'DirectoryWatcher')
)

const originalDirectoryWatcher = require(DirectoryWatcher)

if (!originalDirectoryWatcher.__patchedForPolling) {
  const Patched = function DirectoryWatcherWithPolling(directory, options) {
    return new originalDirectoryWatcher(
      directory,
      Object.assign({}, options, { poll: options && options.poll ? options.poll : 300 })
    )
  }
  Patched.__patchedForPolling = true
  Patched.prototype = originalDirectoryWatcher.prototype

  require.cache[DirectoryWatcher].exports = Patched
  console.log('[patch-watch-polling] 文件监听已切换为轮询（poll=300ms）')
} else {
  console.log('[patch-watch-polling] 轮询补丁已生效')
}
