#!/usr/bin/env bash
set -u

check() {
  local name="$1"
  shift
  printf '%-18s' "$name"
  if "$@" >/tmp/mingde-vp-chamber-env-check.out 2>&1; then
    head -n 1 /tmp/mingde-vp-chamber-env-check.out
  else
    printf 'MISSING\n'
  fi
}

check "docker" docker --version
check "docker compose" docker compose version
check "php" php -v
check "composer" composer --version
check "node" node -v
check "npm" npm -v
check "mysql client" mysql --version
check "redis-server" redis-server --version

rm -f /tmp/mingde-vp-chamber-env-check.out
