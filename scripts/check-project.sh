#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_CRMEB_SHA="7dcddffff73ec542d689f159724296351f29ea9a"

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 1
}

required_files=(
  "index.html"
  "PROJECT_MANIFEST.json"
  "docs/index.html"
  "docs/PRD-v2.0.html"
  "docs/CRMEB-二次开发说明书-v1.0.html"
  "docs/CRMEB-API资料索引.html"
  "docs/开发目录与工作流.html"
  "docs/开工基线与首轮任务.html"
  "docs/本地开发环境基线.html"
  "docs/Codex-Agent团队全量开发计划-v1.0.html"
  "backend/custom/README.html"
  "frontend/custom/README.html"
  "ai-service/README.html"
  "deployment/README.html"
  "deployment/local/README.html"
  "deployment/local/docker-compose.crmeb.yml"
  "deployment/local/nginx-vhost.conf"
  "scripts/check-local-env.sh"
)

for file in "${required_files[@]}"; do
  [[ -s "$ROOT/$file" ]] || fail "missing or empty required file: $file"
done

[[ -f "$ROOT/.gitmodules" ]] || fail "missing .gitmodules"
grep -Fq 'path = backend/crmeb' "$ROOT/.gitmodules" || fail "CRMEB submodule path is not registered"
[[ -d "$ROOT/backend/crmeb/.git" || -f "$ROOT/backend/crmeb/.git" ]] || fail "CRMEB submodule is not initialized"

actual_sha="$(git -C "$ROOT/backend/crmeb" rev-parse HEAD)"
[[ "$actual_sha" == "$EXPECTED_CRMEB_SHA" ]] || fail "CRMEB SHA mismatch: expected $EXPECTED_CRMEB_SHA, got $actual_sha"

submodule_changes="$(git -C "$ROOT/backend/crmeb" status --porcelain)"
[[ -z "$submodule_changes" ]] || fail "CRMEB submodule has uncommitted changes"

for file in "${required_files[@]}"; do
  [[ "$file" == *.html ]] || continue
  grep -Fqi '<!DOCTYPE html>' "$ROOT/$file" || fail "HTML doctype missing: $file"
  grep -Fqi '</html>' "$ROOT/$file" || fail "closing html tag missing: $file"
done

printf 'Project baseline OK\n'
printf 'CRMEB: %s\n' "$actual_sha"
printf 'Required documents: %s\n' "${#required_files[@]}"
