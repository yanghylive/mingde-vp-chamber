#!/usr/bin/env bash
#
# B5 前端门禁：真实编译 mp-weixin 产物 + 产物验证
#
# 用途：改完 uniapp 前端代码后，跑本脚本做「上线前预检」——
#   1) 源码静态检查（已知 wxss 坑 / scoped / 标签闭合）
#   2) 用 HBuilderX 工具链真实编译 mp-weixin 产物（编译失败 = 门禁拦截）
#   3) 产物验证（JS 语法 / 已知 bug 特征 / 关键页面齐全）
#
# 用法：
#   scripts/check-frontend-build.sh          # 只检查，不部署
#   SYNC=1 scripts/check-frontend-build.sh   # 检查通过后同步产物到 unpackage/dist/build
#
# 依赖：本机 HBuilderX（/Applications/HBuilderX.app）+ 托管 node 22 + 项目 node_modules

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
UNIAPP="$PROJECT_ROOT/frontend/custom/uniapp"
BUILD_SRC="/tmp/uni-build-src"
OUT_DIR="$BUILD_SRC/dist/build/mp-weixin"
NODE="${FRONTEND_NODE:-/Users/yanghy/.workbuddy/binaries/node/versions/22.22.2/bin/node}"
[ -x "${NODE}" ] || fail "node 不可用: ${NODE}（可用 FRONTEND_NODE 环境变量覆盖，例如 FRONTEND_NODE=/Users/dawang/Developer/tools/node-v22/bin/node）"
HBX_PLUGINS="/Applications/HBuilderX.app/Contents/HBuilderX/plugins"
SYNC="${SYNC:-0}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
fail() { printf "${RED}❌ FAIL:${NC} %s\n" "$*" >&2; exit 1; }
pass() { printf "${GREEN}✅${NC} %s\n" "$*"; }
warn() { printf "${YELLOW}⚠️${NC} %s\n" "$*"; }

echo "===== B5 前端门禁：真实编译 + 产物验证 ====="
echo "项目：$UNIAPP"

# ---- 1. 源码静态检查 ----
echo ""
echo "--- [1/4] 源码静态检查 ---"
cd "$UNIAPP"

# 1a. % 与 rpx 混合 calc（微信 wxss 不支持，会导致两列布局崩坏）
if grep -rnE 'calc\([^)]*%[^)]*rpx|calc\([^)]*rpx[^)]*%' pages/ 2>/dev/null; then
  fail "发现 % 与 rpx 混合 calc（微信 wxss 不支持，两列布局会崩坏成单列）"
fi
pass "无 % + rpx 混合 calc"

# 1b. 页面 scoped 样式（小程序页面不支持 scoped）
if grep -rn '<style scoped' pages/ 2>/dev/null; then
  fail "发现页面 scoped 样式（小程序页面不支持）"
fi
pass "无页面 scoped 样式"

# 1c. 标签闭合平衡（template/style/script 开闭数量一致）
for f in $(find pages -name '*.vue'); do
  t_open=$(grep -c '<template' "$f" || true); t_close=$(grep -c '</template>' "$f" || true)
  s_open=$(grep -c '<style' "$f" || true);   s_close=$(grep -c '</style>' "$f" || true)
  sc_open=$(grep -c '<script' "$f" || true); sc_close=$(grep -c '</script>' "$f" || true)
  if [ "$t_open" != "$t_close" ] || [ "$s_open" != "$s_close" ] || [ "$sc_open" != "$sc_close" ]; then
    fail "$f 标签未闭合 (template $t_open/$t_close  style $s_open/$s_close  script $sc_open/$sc_close)"
  fi
done
pass "所有 .vue 标签闭合平衡"

# ---- 2. 准备构建目录 ----
echo ""
echo "--- [2/4] 准备构建目录 ---"
mkdir -p "$BUILD_SRC"
rsync -a --delete --exclude node_modules --exclude unpackage "$UNIAPP/" "$BUILD_SRC/src/"
ln -sfn "$UNIAPP/node_modules" "$BUILD_SRC/node_modules"
ln -sfn "$UNIAPP/package.json" "$BUILD_SRC/package.json"
ln -sfn "$UNIAPP/vue.config.js" "$BUILD_SRC/vue.config.js"
pass "构建目录就绪（/tmp/uni-build-src）"

# ---- 3. 真实编译 ----
# HBuilderX 存在时用其插件目录；否则走纯 npm 工具链（package.json 已钉死
# webpack4/copy-plugin5 等版本 + postinstall 补齐嵌套依赖，见 scripts/fix-nested-webpack4.js）。
# 已验证：无 HBuilderX 的机器上可完整编译出 1.3M 可部署产物。
echo ""
echo "--- [3/4] 真实编译 mp-weixin ---"
cd "$BUILD_SRC"
if [ -d "${HBX_PLUGINS}" ]; then
  export UNI_HBUILDERX_PLUGINS="${HBX_PLUGINS}"
else
  unset UNI_HBUILDERX_PLUGINS
  warn "未检测到 HBuilderX，使用纯 npm 工具链编译"
fi
if ! env -u HTTP_PROXY -u HTTPS_PROXY -u http_proxy -u https_proxy -u NODE_OPTIONS \
    NODE_ENV=production UNI_PLATFORM=mp-weixin UNI_CLI_CONTEXT="$BUILD_SRC" \
    UNI_OUTPUT_DIR="$OUT_DIR" \
    "$NODE" node_modules/@vue/cli-service/bin/vue-cli-service.js uni-build \
    > "$BUILD_SRC/build.log" 2>&1; then
  echo "编译日志（末尾 30 行）："
  tail -30 "$BUILD_SRC/build.log" >&2 || true
  fail "编译失败，见上方日志"
fi
pass "编译成功（DONE Build complete）"

# ---- 4. 产物验证 ----
echo ""
echo "--- [4/4] 产物验证 ---"

# 4a. node --check 所有产物 JS（语法）
js_count=0
while IFS= read -r f; do
  if ! "$NODE" --check "$f" >/dev/null 2>&1; then
    "$NODE" --check "$f" 2>&1 || true
    fail "产物 JS 语法错误: $f"
  fi
  js_count=$((js_count + 1))
done < <(find "$OUT_DIR" -name '*.js' -type f)
pass "产物 JS 语法检查通过（$js_count 个文件）"

# 4b. 产物无 calc(50% 残留（两列布局 bug 特征）
if grep -rn 'calc(50%' "$OUT_DIR/pages/" 2>/dev/null; then
  fail "产物残留 calc(50%（两列布局 bug 未修复）"
fi
pass "产物无 calc(50% 残留"

# 4c. 关键页面产物齐全（以 pages.json 注册为准；AI 页下线期间不要求 chat/ai-ecosystem）
registered="$(node - "$UNIAPP/pages.json" <<'NODE'
const fs = require('fs');
const text = fs.readFileSync(process.argv[2], 'utf8').replace(/\/\/.*/g, '');
const pages = JSON.parse(text).pages || [];
for (const p of pages) {
  if (p && p.path) console.log(p.path.replace(/\/index$/, ''));
}
NODE
)"
for p in index events experts mall membership mine login; do
  if ! grep -qxF "pages/$p" <<<"${registered}"; then
    fail "pages.json 缺少 tabBar 关键页: pages/$p/"
  fi
  if [ ! -f "$OUT_DIR/pages/$p/index.js" ] && [ ! -f "$OUT_DIR/pages/$p/index.wxml" ]; then
    fail "缺少关键页面产物: pages/$p/"
  fi
done
pass "关键页面产物齐全（tabBar 5 页 + 会员中心/登录）"

# ---- 可选：同步产物 ----
if [ "$SYNC" = "1" ]; then
  rsync -a --delete "$OUT_DIR/" "$UNIAPP/unpackage/dist/build/"
  pass "产物已同步到 unpackage/dist/build（微信开发者工具打开此目录）"
else
  warn "未同步产物（设置 SYNC=1 可同步到 unpackage/dist/build）"
fi

echo ""
printf "${GREEN}===== 门禁全部通过 =====${NC}\n"
