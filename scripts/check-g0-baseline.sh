#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
SIGNING_SECRET="${CHAMBER_TENANT_SIGNING_SECRET:-local-only-tenant-signing-secret-32-bytes}"
SIGNED_HOST="${CHAMBER_TEST_SIGNED_HOST:-signed.local.test}"
CORS_ALLOWED_ORIGIN="${CHAMBER_TEST_CORS_ORIGIN:-http://localhost:5173}"
TMP_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

header_value() {
    local file="$1" name="$2"
    awk -v expected="${name}" \
        'tolower($0) ~ "^" tolower(expected) ":[[:space:]]*" { value=$0; sub(/^[^:]*:[[:space:]]*/, "", value) } END { sub(/\r$/, "", value); print value }' \
        "${file}"
}

assert_json() {
    local file="$1" path="$2" expected="$3"
    node - "${file}" "${path}" "${expected}" <<'NODE'
const fs = require('fs');
const [file, path, expected] = process.argv.slice(2);
const value = path.split('.').reduce((current, key) => current && current[key], JSON.parse(fs.readFileSync(file, 'utf8')));
if (String(value) !== expected) {
  process.stderr.write(`Expected ${path}=${expected}, got ${String(value)}\n`);
  process.exit(1);
}
NODE
}

request() {
    local name="$1"
    shift
    curl -sS --max-time 15 -D "${TMP_DIR}/${name}.headers" -o "${TMP_DIR}/${name}.json" \
        -w '%{http_code}' "$@"
}

assert_loopback_binding() {
    local container="$1" port="$2" bindings
    bindings="$(docker port "${container}" "${port}/tcp" 2>/dev/null || true)"
    [ -n "${bindings}" ] || fail "${container} does not publish ${port}/tcp"
    if printf '%s\n' "${bindings}" | grep -Evq '^127\.0\.0\.1:[0-9]+$'; then
        fail "${container} ${port}/tcp is not restricted to 127.0.0.1: ${bindings}"
    fi
}

for command in docker curl node ruby openssl shasum; do
    require_command "${command}"
done

cd "${PROJECT_ROOT}"

bash -n \
    scripts/check-project.sh \
    scripts/check-local-env.sh \
    scripts/prepare-local-crmeb-runtime.sh \
    scripts/manage-local-database.sh \
    scripts/prepare-local-frontend.sh

./scripts/check-project.sh
docker compose -f "${COMPOSE_FILE}" config >/dev/null
./scripts/prepare-local-crmeb-runtime.sh install
./scripts/manage-local-database.sh setup

assert_loopback_binding mingde_crmeb_nginx 80
assert_loopback_binding mingde_crmeb_mysql 3306
assert_loopback_binding mingde_crmeb_redis 6379
[ -z "$(docker port mingde_crmeb_php 2>/dev/null || true)" ] \
    || fail "PHP-FPM or internal websocket ports must not be published to the host"

while IFS= read -r -d '' file; do
    relative="${file#backend/custom/app/chamber/}"
    docker exec mingde_crmeb_php php -l "/var/www/app/chamber/${relative}" >/dev/null
done < <(find backend/custom/app/chamber -type f -name '*.php' -print0)

docker exec mingde_crmeb_php php /var/www/app/chamber/tests/run.php
./scripts/prepare-local-frontend.sh test
ruby backend/custom/openapi/validate.rb

request_id='client-request-id-0001'
correlation_id='client-correlation-id-0001'
status="$(request health \
    -H "X-Request-Id: ${request_id}" \
    -H "X-Correlation-Id: ${correlation_id}" \
    "${BASE_URL}/chamber/health")"
[ "${status}" = '200' ] || fail "health returned HTTP ${status}"
assert_json "${TMP_DIR}/health.json" status 200
assert_json "${TMP_DIR}/health.json" request_id "${request_id}"
[ "$(header_value "${TMP_DIR}/health.headers" X-Request-Id)" = "${request_id}" ] \
    || fail "health did not preserve X-Request-Id"
[ "$(header_value "${TMP_DIR}/health.headers" X-Correlation-Id)" = "${correlation_id}" ] \
    || fail "health did not preserve X-Correlation-Id"

status="$(request bootstrap-host "${BASE_URL}/chamber/v1/bootstrap")"
[ "${status}" = '200' ] || fail "host bootstrap returned HTTP ${status}"
assert_json "${TMP_DIR}/bootstrap-host.json" data.tenant.slug local-primary
assert_json "${TMP_DIR}/bootstrap-host.json" data.channel.code default
assert_json "${TMP_DIR}/bootstrap-host.json" data.context_source host

status="$(request raw-id \
    -H 'Host: unknown.local.test' \
    -H 'X-Tenant-Id: 2' \
    "${BASE_URL}/chamber/v1/bootstrap")"
[ "${status}" = '400' ] || fail "raw tenant ID test returned HTTP ${status}"
assert_json "${TMP_DIR}/raw-id.json" data.reason missing_context

timestamp="$(date +%s)"
nonce="g0-baseline-${timestamp}-$$"
canonical_payload="$(printf 'GET\n%s\n/chamber/v1/bootstrap\nlocal-secondary\ndefault\n%s\n%s' \
    "${SIGNED_HOST}" "${timestamp}" "${nonce}")"
signature="$(printf '%s' "${canonical_payload}" | openssl dgst -sha256 -hmac "${SIGNING_SECRET}" -r | awk '{print $1}')"

signed_headers=(
    -H "Host: ${SIGNED_HOST}"
    -H 'X-Chamber-Tenant: local-secondary'
    -H 'X-Chamber-Channel: default'
    -H "X-Chamber-Timestamp: ${timestamp}"
    -H "X-Chamber-Nonce: ${nonce}"
    -H "X-Chamber-Signature: ${signature}"
)

status="$(request signed-first "${signed_headers[@]}" "${BASE_URL}/chamber/v1/bootstrap")"
[ "${status}" = '200' ] || fail "signed bootstrap returned HTTP ${status}"
assert_json "${TMP_DIR}/signed-first.json" data.tenant.slug local-secondary
assert_json "${TMP_DIR}/signed-first.json" data.context_source signed_channel

status="$(request signed-replay "${signed_headers[@]}" "${BASE_URL}/chamber/v1/bootstrap")"
[ "${status}" = '401' ] || fail "signed replay returned HTTP ${status}"
assert_json "${TMP_DIR}/signed-replay.json" data.reason replayed_request

status="$(request cors \
    -X OPTIONS \
    -H "Origin: ${CORS_ALLOWED_ORIGIN}" \
    -H 'Access-Control-Request-Method: GET' \
    -H 'Access-Control-Request-Headers: X-Request-Id,X-Correlation-Id,X-Chamber-Signature' \
    "${BASE_URL}/chamber/v1/bootstrap")"
[ "${status}" = '200' ] || fail "CORS preflight returned HTTP ${status}"
allowed_headers="$(header_value "${TMP_DIR}/cors.headers" Access-Control-Allow-Headers | tr '[:upper:]' '[:lower:]')"
exposed_headers="$(header_value "${TMP_DIR}/cors.headers" Access-Control-Expose-Headers | tr '[:upper:]' '[:lower:]')"
[[ "${allowed_headers}" == *x-chamber-signature* ]] || fail "CORS does not allow Chamber signature headers"
[[ "${allowed_headers}" == *x-request-id* ]] || fail "CORS does not allow request tracing headers"
[[ "${exposed_headers}" == *x-correlation-id* ]] || fail "CORS does not expose tracing headers"
[ "$(header_value "${TMP_DIR}/cors.headers" Access-Control-Allow-Origin)" = "${CORS_ALLOWED_ORIGIN}" ] \
    || fail "CORS did not return the exact allowlisted Origin"
[ "$(header_value "${TMP_DIR}/cors.headers" Access-Control-Allow-Credentials)" = 'true' ] \
    || fail "CORS credential policy is not explicit"

status="$(request cors-denied \
    -X OPTIONS \
    -H 'Origin: https://evil.example' \
    -H 'Access-Control-Request-Method: GET' \
    "${BASE_URL}/chamber/v1/bootstrap")"
[ "${status}" = '403' ] || fail "unknown CORS Origin returned HTTP ${status}"
assert_json "${TMP_DIR}/cors-denied.json" data.reason cors_origin_denied
[ -z "$(header_value "${TMP_DIR}/cors-denied.headers" Access-Control-Allow-Origin)" ] \
    || fail "unknown CORS Origin was reflected in Access-Control-Allow-Origin"

[ -z "$(git -C backend/crmeb status --porcelain)" ] || fail "CRMEB submodule is dirty"

printf 'G0 baseline OK\n'
printf 'HTTP: health, host tenant, signed tenant, replay rejection, CORS allow/deny\n'
printf 'Database: migration checksum, structural postconditions, seed verification\n'
printf 'Frontend: shared bootstrap tests; OpenAPI: static contract validation\n'
