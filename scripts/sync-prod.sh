#!/usr/bin/env bash
# =============================================================================
# sync-prod.sh - 本地 <-> 生产（kaypal-prod-new / md.kaypal.cn）后端代码双向同步
#
# 背景：生产是手工演进版（非 git 部署），本地改代码需手动增量上传，生产新代码
#       也需拉回本地提交。本脚本把这一过程一键化。
#
# 用法：
#   ./scripts/sync-prod.sh diff    # 只对比差异（默认，只读安全）
#   ./scripts/sync-prod.sh push    # 本地到生产（增量上传 + prepare + 重启 php）
#   ./scripts/sync-prod.sh pull    # 生产到本地（增量拉回 + git 提示）
#
# 安全原则：
#   1. 绝不使用 rsync --delete - 两边各可能有不属于对方的文件（增量合并）
#   2. 任何模式都先做 diff 预览，push/pull 需确认（-y 跳过确认）
#   3. 每次操作自动备份生产 route.php（生产手工维护，防误覆盖）
# =============================================================================

set -uo pipefail

# ---------- 配置 ----------
SSH_HOST="${SYNC_PROD_HOST:-kaypal-prod-new}"
PROD_BASE="/opt/mingde-vp-chamber"
LOCAL_BASE="$(cd "$(dirname "$0")/.." && pwd)"
SYNC_DIRS=(
  "backend/custom/app/chamber"
  "backend/custom/database/migrations"
)
PHP_CONTAINER="md_kaypal_php"
BACKUP_TAG="$(date +%Y%m%d-%H%M%S)"

# ---------- 工具 ----------
log()  { printf '\033[36m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
warn() { printf '\033[33m[!] %s\033[0m\n' "$*"; }
err()  { printf '\033[31m[x] %s\033[0m\n' "$*" >&2; }
ok()   { printf '\033[32m[v] %s\033[0m\n' "$*"; }

# SSH 重试（生产服务器连接不稳定）
ssh_retry() {
  local attempt=1 max=3
  until ssh -o ConnectTimeout=30 -o ServerAliveInterval=10 "$SSH_HOST" "$@" 2>/tmp/sync-ssh-err; do
    if [ "$attempt" -ge "$max" ]; then
      err "SSH 连续 ${max} 次失败，放弃。最后错误：$(tail -1 /tmp/sync-ssh-err)"
      return 1
    fi
    warn "SSH 第 ${attempt} 次失败，3 秒后重试..."
    sleep 3
    attempt=$((attempt + 1))
  done
  return 0
}

# ---------- 差异对比 ----------
do_diff() {
  log "对比差异（本地 <-> ${SSH_HOST}）..."
  local total_new=0 total_mod=0 total_prod_only=0

  for dir in "${SYNC_DIRS[@]}"; do
    local local_dir="${LOCAL_BASE}/${dir}"

    # 生产文件清单（相对路径 + md5）
    ssh_retry "cd ${PROD_BASE} && find ${dir} -type f \\( -name '*.php' -o -name '*.sql' \\) -exec md5sum {} \\;" > /tmp/sync-prod-md5.txt || return 1

    # 本地清单（同样相对路径）
    (cd "${LOCAL_BASE}" && find "${dir}" -type f \( -name '*.php' -o -name '*.sql' \) -exec md5 -r {} \;) > /tmp/sync-local-md5.txt 2>/dev/null

    python3 - "$dir" << 'PYEOF'
import sys
dir = sys.argv[1]
prod, local = {}, {}
for line in open('/tmp/sync-prod-md5.txt'):
    line = line.strip()
    if not line: continue
    parts = line.split(' ', 1)
    if len(parts) == 2: prod[parts[1].strip()] = parts[0].strip()
for line in open('/tmp/sync-local-md5.txt'):
    line = line.strip()
    if not line: continue
    parts = line.split(' ', 1)
    if len(parts) == 2: local[parts[1].strip()] = parts[0].strip()

new_local = sorted(f for f in local if f not in prod)
modified = sorted(f for f in local if f in prod and local[f] != prod[f])
prod_only = sorted(f for f in prod if f not in local)

print("--- " + dir + " ---")
if not (new_local or modified or prod_only):
    print("    无差异（已同步）")
for f in new_local:  print("    [本地独有] " + f)
for f in modified:   print("    [内容不同] " + f)
for f in prod_only:  print("    [生产独有] " + f)
print("COUNT %d %d %d" % (len(new_local), len(modified), len(prod_only)))
PYEOF
  done
  echo ""
  ok "diff 完成。生产独有文件（生产有本地没有）务必人工确认：那是生产手工演进代码，pull 时会拉回。"
}

# rsync 重试（生产 SSH 不稳定）
rsync_retry() {
  local attempt=1 max=3
  until "$@" 2>/tmp/sync-rsync-err; do
    if [ "$attempt" -ge "$max" ]; then
      err "rsync 连续 ${max} 次失败，放弃：$(tail -1 /tmp/sync-rsync-err)"
      return 1
    fi
    warn "rsync 第 ${attempt} 次失败，5 秒后重试..."
    sleep 5
    attempt=$((attempt + 1))
  done
  return 0
}

# ---------- 本地到生产 ----------
do_push() {
  log "推送：本地到生产（增量，不含 --delete）..."
  do_diff

  if [ "${1:-}" != "-y" ]; then
    echo ""
    read -r -p "确认推送以上本地变更到生产？[y/N] " ans
    [[ "$ans" =~ ^[Yy]$ ]] || { warn "已取消"; exit 0; }
  fi

  for dir in "${SYNC_DIRS[@]}"; do
    log "rsync ${dir} ..."
    ssh_retry "mkdir -p ${PROD_BASE}/${dir}" || return 1
    rsync_retry rsync -az --no-perms --no-owner --no-group \
      -e "ssh -o ConnectTimeout=30 -o ServerAliveInterval=10" \
      "${LOCAL_BASE}/${dir}/" "${SSH_HOST}:${PROD_BASE}/${dir}/" || return 1
  done

  # 备份生产 route.php（防后续误覆盖）
  ssh_retry "cp ${PROD_BASE}/backend/custom/app/chamber/route/route.php ${PROD_BASE}/backend/custom/app/chamber/route/route.php.bak-${BACKUP_TAG}" \
    && ok "route.php 已备份（.bak-${BACKUP_TAG}）"

  # 同步 runtime + 重启 php 清 opcache
  log "prepare runtime ..."
  ssh_retry "cd ${PROD_BASE} && ./scripts/prepare-local-crmeb-runtime.sh prepare" || { warn "prepare 失败，请检查"; }
  log "重启 ${PHP_CONTAINER} 清 opcache ..."
  ssh_retry "docker restart ${PHP_CONTAINER}" && sleep 6 && ok "php 已重启"

  echo ""
  log "推送完成。若本次含新迁移（database/migrations），需在生产执行，例如："
  log "  ssh ${SSH_HOST} 'docker exec ${PHP_CONTAINER} php /tmp/xxx.php'"
  log "  （或参考 backend/custom/database/migrations 下的 up.sql 手动执行）"
  warn "验证：curl -s https://md.kaypal.cn/chamber/v1/health"
}

# ---------- 生产到本地 ----------
do_pull() {
  log "拉取：生产到本地（增量合并，不覆盖本地独有文件）..."
  do_diff

  if [ "${1:-}" != "-y" ]; then
    echo ""
    read -r -p "确认将生产变更拉取到本地？[y/N] " ans
    [[ "$ans" =~ ^[Yy]$ ]] || { warn "已取消"; exit 0; }
  fi

  for dir in "${SYNC_DIRS[@]}"; do
    log "rsync 拉取 ${dir} ..."
    rsync_retry rsync -az --no-perms --no-owner --no-group \
      -e "ssh -o ConnectTimeout=30 -o ServerAliveInterval=10" \
      "${SSH_HOST}:${PROD_BASE}/${dir}/" "${LOCAL_BASE}/${dir}/" || return 1
  done

  echo ""
  ok "拉取完成。请检查后提交："
  log "  cd ${LOCAL_BASE} && git status --short backend/custom/"
  log "  git add backend/custom/ && git commit -m 'sync: 拉取生产演进代码' && git push origin main"
}

# ---------- main ----------
MODE="${1:-diff}"
CONFIRM="${2:-}"

case "$MODE" in
  diff) do_diff ;;
  push) do_push "$CONFIRM" ;;
  pull) do_pull "$CONFIRM" ;;
  *)
    err "未知模式: ${MODE}（可用: diff / push / pull）"
    sed -n '1,18p' "$0"
    exit 1
    ;;
esac
