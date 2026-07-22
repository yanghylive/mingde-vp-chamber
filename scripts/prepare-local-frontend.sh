#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CRMEB_ROOT="$PROJECT_ROOT/backend/crmeb"
TEMPLATE_ROOT="$CRMEB_ROOT/template"
ADMIN_SOURCE="$TEMPLATE_ROOT/admin"
UNIAPP_SOURCE="$TEMPLATE_ROOT/uni-app"
CUSTOM_ROOT="$PROJECT_ROOT/frontend/custom"
SHARED_SOURCE="$CUSTOM_ROOT/shared"
WORKSPACE_ROOT="${MINGDE_FRONTEND_WORKSPACE:-$PROJECT_ROOT/.build-workspace/frontend}"
ADMIN_TARGET="$WORKSPACE_ROOT/admin"
UNIAPP_TARGET="$WORKSPACE_ROOT/uni-app"
EXPECTED_TAG="v6.0.0"
EXPECTED_COMMIT="7dcddffff73ec542d689f159724296351f29ea9a"
MODE="${1:-prepare}"

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "required command not found: $1"
}

verify_source() {
  require_command git
  require_command node
  require_command npm
  require_command rsync

  [[ -d "$ADMIN_SOURCE/src" ]] || fail "CRMEB admin template is missing: $ADMIN_SOURCE"
  [[ -f "$UNIAPP_SOURCE/main.js" ]] || fail "CRMEB UniApp template is missing: $UNIAPP_SOURCE"
  [[ -f "$SHARED_SOURCE/tenant-brand.js" ]] || fail "tenant brand core is missing"
  [[ -f "$SHARED_SOURCE/vue2-tenant-brand.js" ]] || fail "Vue2 tenant brand adapter is missing"

  local commit tag dirty
  commit="$(git -C "$CRMEB_ROOT" rev-parse HEAD)"
  tag="$(git -C "$CRMEB_ROOT" describe --tags --exact-match HEAD 2>/dev/null || true)"
  dirty="$(git -C "$CRMEB_ROOT" status --porcelain)"

  [[ "$commit" == "$EXPECTED_COMMIT" ]] || fail "CRMEB commit drift: expected $EXPECTED_COMMIT, got $commit"
  [[ "$tag" == "$EXPECTED_TAG" ]] || fail "CRMEB tag drift: expected $EXPECTED_TAG, got ${tag:-none}"
  [[ -z "$dirty" ]] || fail "CRMEB submodule has local changes; keep upstream read-only"

  node - "$ADMIN_SOURCE/package.json" "$ADMIN_SOURCE/package-lock.json" "$UNIAPP_SOURCE/package.json" "$UNIAPP_SOURCE/package-lock.json" "$(npm --version)" <<'NODE'
const fs = require('fs');

const [adminPackagePath, adminLockPath, uniPackagePath, uniLockPath, npmVersion] = process.argv.slice(2);
const adminPackage = JSON.parse(fs.readFileSync(adminPackagePath, 'utf8'));
const adminLock = JSON.parse(fs.readFileSync(adminLockPath, 'utf8'));
const uniPackage = JSON.parse(fs.readFileSync(uniPackagePath, 'utf8'));
const uniLock = JSON.parse(fs.readFileSync(uniLockPath, 'utf8'));

function tuple(version) {
  return String(version).replace(/^v/, '').split('.').slice(0, 3).map((part) => Number(part) || 0);
}

function compare(left, right) {
  const a = tuple(left);
  const b = tuple(right);
  for (let index = 0; index < 3; index += 1) {
    if (a[index] !== b[index]) return a[index] < b[index] ? -1 : 1;
  }
  return 0;
}

function satisfies(version, expression) {
  const parts = String(expression)
    .replace(/(>=|<=|>|<|=)\s+/g, '$1')
    .split(/\s+/)
    .filter(Boolean);

  return parts.every((part) => {
    const match = /^(>=|<=|>|<|=)?(\d+(?:\.\d+){0,2})$/.exec(part);
    if (!match) throw new Error(`unsupported engine expression: ${part}`);
    const relation = compare(version, match[2]);
    return match[1] === '>' ? relation > 0
      : match[1] === '>=' ? relation >= 0
        : match[1] === '<' ? relation < 0
          : match[1] === '<=' ? relation <= 0
            : relation === 0;
  });
}

const adminRootLock = adminLock.packages && adminLock.packages[''];
const lockedVue = adminLock.packages && adminLock.packages['node_modules/vue'];
const lockedVueCompiler = adminLock.packages && adminLock.packages['node_modules/vue-template-compiler'];
const lockedVuex = adminLock.packages && adminLock.packages['node_modules/vuex'];
const lockedVueCli = adminLock.packages && adminLock.packages['node_modules/@vue/cli-service'];
if (!adminRootLock || adminRootLock.name !== adminPackage.name || adminRootLock.version !== adminPackage.version) {
  throw new Error('admin package-lock root metadata does not match package.json');
}
if (!lockedVue || !lockedVueCompiler || lockedVue.version !== lockedVueCompiler.version) {
  throw new Error('admin Vue and vue-template-compiler lock versions must match');
}
if (!adminPackage.scripts || !adminPackage.scripts.build) {
  throw new Error('admin npm build script is missing');
}
if (!satisfies(process.versions.node, adminPackage.engines.node)) {
  throw new Error(`Node ${process.versions.node} does not satisfy admin engine ${adminPackage.engines.node}`);
}
if (!satisfies(npmVersion, adminPackage.engines.npm)) {
  throw new Error(`npm ${npmVersion} does not satisfy admin engine ${adminPackage.engines.npm}`);
}
if (Object.keys(uniPackage.scripts || {}).length !== 0 || Object.keys(uniPackage.dependencies || {}).length !== 0) {
  throw new Error('UniApp package metadata changed; review its build path before continuing');
}
if (uniLock.lockfileVersion !== 1 || uniLock.name !== 'H5') {
  throw new Error('UniApp legacy lock metadata changed; review its HBuilderX build path');
}

process.stdout.write(
  `Source OK: CRMEB admin ${adminPackage.version}, Vue ${lockedVue.version}, Vuex ${lockedVuex.version}, ` +
    `Vue CLI ${lockedVueCli.version}, Node ${process.versions.node}, npm ${npmVersion}\n`,
);
process.stdout.write('Source note: UniApp has no npm scripts/dependencies; use the HBuilderX/UniApp toolchain.\n');
NODE
}

copy_workspace() {
  mkdir -p "$ADMIN_TARGET" "$UNIAPP_TARGET"

  rsync -a --delete \
    --exclude node_modules \
    --exclude dist \
    "$ADMIN_SOURCE/" "$ADMIN_TARGET/"
  rsync -a --delete \
    --exclude node_modules \
    --exclude unpackage \
    "$UNIAPP_SOURCE/" "$UNIAPP_TARGET/"

  rsync -a --exclude .gitkeep --exclude README.html "$CUSTOM_ROOT/admin/" "$ADMIN_TARGET/"
  rsync -a --exclude .gitkeep --exclude README.html "$CUSTOM_ROOT/uniapp/" "$UNIAPP_TARGET/"

  mkdir -p "$ADMIN_TARGET/src/chamber/shared" "$UNIAPP_TARGET/chamber/shared"
  rsync -a --delete --exclude tests --exclude '*.html' --exclude .gitkeep \
    "$SHARED_SOURCE/" "$ADMIN_TARGET/src/chamber/shared/"
  rsync -a --delete --exclude tests --exclude '*.html' --exclude .gitkeep \
    "$SHARED_SOURCE/" "$UNIAPP_TARGET/chamber/shared/"

  node - "$WORKSPACE_ROOT/source.json" "$EXPECTED_TAG" "$EXPECTED_COMMIT" <<'NODE'
const fs = require('fs');
const [output, tag, commit] = process.argv.slice(2);
const manifest = {
  upstream: 'CRMEB',
  tag,
  commit,
  adminSource: 'backend/crmeb/template/admin',
  uniAppSource: 'backend/crmeb/template/uni-app',
  overlaySource: 'frontend/custom',
};
fs.writeFileSync(output, `${JSON.stringify(manifest, null, 2)}\n`);
NODE
}

verify_workspace() {
  [[ -f "$WORKSPACE_ROOT/source.json" ]] || fail "workspace is not prepared: $WORKSPACE_ROOT"
  [[ -f "$ADMIN_TARGET/src/chamber/shared/tenant-brand.js" ]] || fail "admin shared bootstrap is missing"
  [[ -f "$UNIAPP_TARGET/chamber/shared/tenant-brand.js" ]] || fail "UniApp shared bootstrap is missing"

  cmp -s "$SHARED_SOURCE/tenant-brand.js" "$ADMIN_TARGET/src/chamber/shared/tenant-brand.js" || fail "admin shared bootstrap drift"
  cmp -s "$SHARED_SOURCE/tenant-brand.js" "$UNIAPP_TARGET/chamber/shared/tenant-brand.js" || fail "UniApp shared bootstrap drift"
  cmp -s "$SHARED_SOURCE/vue2-tenant-brand.js" "$ADMIN_TARGET/src/chamber/shared/vue2-tenant-brand.js" || fail "admin Vue2 adapter drift"
  cmp -s "$SHARED_SOURCE/vue2-tenant-brand.js" "$UNIAPP_TARGET/chamber/shared/vue2-tenant-brand.js" || fail "UniApp Vue2 adapter drift"

  node - "$WORKSPACE_ROOT/source.json" "$EXPECTED_COMMIT" <<'NODE'
const fs = require('fs');
const [manifestPath, expectedCommit] = process.argv.slice(2);
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
if (manifest.commit !== expectedCommit) throw new Error('prepared workspace commit does not match CRMEB baseline');
NODE

  printf 'Workspace OK: %s\n' "$WORKSPACE_ROOT"
}

run_tests() {
  node "$SHARED_SOURCE/tests/tenant-brand.test.js"
  node - "$ADMIN_TARGET/src/chamber/shared" "$UNIAPP_TARGET/chamber/shared" <<'NODE'
const [adminShared, uniShared] = process.argv.slice(2);
for (const directory of [adminShared, uniShared]) {
  const core = require(`${directory}/tenant-brand.js`);
  const adapter = require(`${directory}/vue2-tenant-brand.js`);
  if (typeof core.bootstrapTenantBrand !== 'function') throw new Error(`invalid core module: ${directory}`);
  if (typeof adapter.installTenantBrand !== 'function') throw new Error(`invalid Vue2 adapter: ${directory}`);
}
process.stdout.write('PASS generated Admin and UniApp CommonJS imports\n');
NODE
}

build_admin() {
  cmp -s "$ADMIN_SOURCE/package-lock.json" "$ADMIN_TARGET/package-lock.json" || fail "admin lockfile drift before install"

  (
    cd "$ADMIN_TARGET"
    npm ci --no-audit --no-fund
    npm run build
  )

  cmp -s "$ADMIN_SOURCE/package-lock.json" "$ADMIN_TARGET/package-lock.json" || fail "npm changed the upstream admin lockfile"
  [[ -f "$ADMIN_TARGET/dist/index.html" ]] || fail "admin build did not produce dist/index.html"
  printf 'Admin build OK: %s\n' "$ADMIN_TARGET/dist"
}

case "$MODE" in
  prepare)
    verify_source
    copy_workspace
    verify_workspace
    printf 'Admin workspace: %s\n' "$ADMIN_TARGET"
    printf 'UniApp workspace: %s\n' "$UNIAPP_TARGET"
    ;;
  check)
    verify_source
    verify_workspace
    ;;
  test)
    verify_source
    copy_workspace
    verify_workspace
    run_tests
    ;;
  build-admin)
    verify_source
    copy_workspace
    verify_workspace
    build_admin
    ;;
  *)
    fail "usage: $0 [prepare|check|test|build-admin]"
    ;;
esac
