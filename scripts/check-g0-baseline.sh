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
database_setup_output="$(./scripts/manage-local-database.sh setup)"
printf '%s\n' "${database_setup_output}"
grep -Fq 'PASS 202607210001_create_chamber_core (104 structural checks)' <<<"${database_setup_output}" \
    || fail "core migration structural check count changed"
grep -Fq 'PASS 202607210002_create_commerce_event_baseline (61 structural checks)' <<<"${database_setup_output}" \
    || fail "commerce migration structural check count changed"

database_tables="$(docker compose -f "${COMPOSE_FILE}" exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
    | tr -d '[:space:]')"
[ "${database_tables}" -ge 174 ] || fail "expected at least 174 database tables, got ${database_tables}"
chamber_tables="$(docker compose -f "${COMPOSE_FILE}" exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE \"ch\\\\_%\"" -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
    | tr -d '[:space:]')"
[ "${chamber_tables}" -ge 17 ] || fail "expected at least 17 Chamber tables including the migration registry, got ${chamber_tables}"

assert_loopback_binding mingde_crmeb_nginx 80
assert_loopback_binding mingde_crmeb_mysql 3306
assert_loopback_binding mingde_crmeb_redis 6379
[ -z "$(docker port mingde_crmeb_php 2>/dev/null || true)" ] \
    || fail "PHP-FPM or internal websocket ports must not be published to the host"

while IFS= read -r -d '' file; do
    relative="${file#backend/custom/app/chamber/}"
    docker exec mingde_crmeb_php php -l "/var/www/app/chamber/${relative}" >/dev/null
done < <(find backend/custom/app/chamber -type f -name '*.php' -print0)

tenant_test_output="$(docker exec mingde_crmeb_php php /var/www/app/chamber/tests/run.php)"
printf '%s\n' "${tenant_test_output}"
grep -Fxq '25 tests, 0 failures' <<<"${tenant_test_output}" \
    || fail "tenant test count changed"

commerce_test_output="$(docker exec mingde_crmeb_php php /var/www/app/chamber/tests/commerce_run.php)"
printf '%s\n' "${commerce_test_output}"
grep -Fxq '18 tests, 0 failures' <<<"${commerce_test_output}" \
    || fail "commerce domain test count changed"

commerce_db_output="$(docker exec -w /var/www mingde_crmeb_php php app/chamber/tests/commerce_db_run.php)"
printf '%s\n' "${commerce_db_output}"
grep -Fq 'PASS commerce database adapter (7 assertions; transaction rolled back)' <<<"${commerce_db_output}" \
    || fail "commerce database assertion count changed"

frontend_test_output="$(./scripts/prepare-local-frontend.sh test)"
printf '%s\n' "${frontend_test_output}"
grep -Fq 'PASS 6 tenant brand tests' <<<"${frontend_test_output}" \
    || fail "frontend shared test count changed"

commerce_audit_output="$(ruby backend/custom/commerce/audit_crmeb_v6.rb)"
printf '%s\n' "${commerce_audit_output}"
grep -Fq 'Result: 11/11 locked expectations matched.' <<<"${commerce_audit_output}" \
    || fail "CRMEB commerce source audit count changed"

openapi_output="$(ruby backend/custom/openapi/validate.rb)"
printf '%s\n' "${openapi_output}"
openapi_schema_count="$(awk '/Component schemas:/ { print $3 }' <<<"${openapi_output}")"
[[ "${openapi_schema_count}" =~ ^[0-9]+$ ]] || fail "OpenAPI schema count is unavailable"
[ "${openapi_schema_count}" -ge 37 ] || fail "OpenAPI lost a G0 schema"

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
    -H 'Access-Control-Request-Headers: Idempotency-Key,X-Request-Id,X-Correlation-Id,X-Chamber-Signature' \
    "${BASE_URL}/chamber/v1/bootstrap")"
[ "${status}" = '200' ] || fail "CORS preflight returned HTTP ${status}"
allowed_headers="$(header_value "${TMP_DIR}/cors.headers" Access-Control-Allow-Headers | tr '[:upper:]' '[:lower:]')"
exposed_headers="$(header_value "${TMP_DIR}/cors.headers" Access-Control-Expose-Headers | tr '[:upper:]' '[:lower:]')"
[[ "${allowed_headers}" == *x-chamber-signature* ]] || fail "CORS does not allow Chamber signature headers"
[[ "${allowed_headers}" == *idempotency-key* ]] || fail "CORS does not allow Idempotency-Key"
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
printf 'Database: 174+ tables, 165+ migration checks, seed verification, real commerce adapter\n'
printf 'Commerce: 18 domain tests, 7 database assertions, 11 CRMEB source checks\n'
printf 'Frontend: shared bootstrap tests; OpenAPI: current contract preserves G0 vocabulary\n'
