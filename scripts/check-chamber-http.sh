#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
TMP_DIR="$(mktemp -d)"
TOKEN=''
ADMIN_TOKEN=''

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

cleanup() {
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/chamber_http_fixture.php cleanup "${TOKEN}" "${ADMIN_TOKEN}" \
        >/dev/null 2>&1 || true
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

json_value() {
    node - "$1" "$2" <<'NODE'
const fs = require('fs');
const [file, path] = process.argv.slice(2);
const value = path.split('.').reduce((current, key) => current == null ? undefined : current[key], JSON.parse(fs.readFileSync(file, 'utf8')));
if (value === undefined) process.exit(2);
process.stdout.write(String(value));
NODE
}

assert_json() {
    local actual
    actual="$(json_value "$1" "$2")" || fail "Missing JSON path $2 in $1"
    [ "${actual}" = "$3" ] || fail "Expected $2=$3, got ${actual}"
}

request() {
    local name="$1"
    shift
    curl -sS --max-time 30 -D "${TMP_DIR}/${name}.headers" -o "${TMP_DIR}/${name}.json" \
        -w '%{http_code}' "$@"
}

cd "${PROJECT_ROOT}"
./scripts/prepare-local-crmeb-runtime.sh install >/dev/null
docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/chamber_http_fixture.php cleanup >/dev/null
./scripts/manage-local-database.sh setup >/dev/null
docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/chamber_http_fixture.php setup > "${TMP_DIR}/setup.json"

TOKEN="$(json_value "${TMP_DIR}/setup.json" token)"
ADMIN_TOKEN="$(json_value "${TMP_DIR}/setup.json" admin_token)"
EXPERT_ID="$(json_value "${TMP_DIR}/setup.json" expert_id)"
SLOT_ID="$(json_value "${TMP_DIR}/setup.json" slot_id)"

# ---------------------------------------------------------------------------
# 1. 通知：发布广播 → 未读 → 标记已读 → 已读 → 软删 → 消失
# ---------------------------------------------------------------------------
status="$(request notif-create -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' --data '{"title":"验收通知A","body":"验收内容","scope":"all"}' \
    "${BASE_URL}/chamber/admin/v1/notifications")"
[ "${status}" = '200' ] || fail "notification create returned HTTP ${status}"
NOTIF_ID="$(json_value "${TMP_DIR}/notif-create.json" data.id)"

status="$(request notif-list -X GET -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/notifications")"
[ "${status}" = '200' ] || fail "notification list returned HTTP ${status}"

status="$(request notif-read -X POST -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/notifications/${NOTIF_ID}/read")"
[ "${status}" = '200' ] || fail "notification mark-read returned HTTP ${status}"
assert_json "${TMP_DIR}/notif-read.json" data.read true

status="$(request notif-read-replay -X POST -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/notifications/${NOTIF_ID}/read")"
[ "${status}" = '200' ] || fail "notification mark-read replay returned HTTP ${status}"

status="$(request notif-delete -X DELETE -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/notifications/${NOTIF_ID}")"
[ "${status}" = '200' ] || fail "notification delete returned HTTP ${status}"

# ---------------------------------------------------------------------------
# 2. 预约：扣积分 → 幂等重放 → 取消退积分
# ---------------------------------------------------------------------------
run_key="ch-http-$(date +%s)-$$"
status="$(request appt-create -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${run_key}-appt" -H 'Content-Type: application/json' \
    --data "{\"slot_id\":${SLOT_ID},\"mode\":\"online\"}" \
    "${BASE_URL}/chamber/v1/experts/${EXPERT_ID}/appointments")"
[ "${status}" = '200' ] || fail "appointment create returned HTTP ${status}: $(cat "${TMP_DIR}/appt-create.json")"
APPT_ID="$(json_value "${TMP_DIR}/appt-create.json" data.id)"
assert_json "${TMP_DIR}/appt-create.json" data.status confirmed
assert_json "${TMP_DIR}/appt-create.json" data.points_cost 10000

status="$(request appt-replay -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${run_key}-appt" -H 'Content-Type: application/json' \
    --data "{\"slot_id\":${SLOT_ID},\"mode\":\"online\"}" \
    "${BASE_URL}/chamber/v1/experts/${EXPERT_ID}/appointments")"
[ "${status}" = '200' ] || fail "appointment replay returned HTTP ${status}"
assert_json "${TMP_DIR}/appt-replay.json" data.id "${APPT_ID}"
assert_json "${TMP_DIR}/appt-replay.json" data.replayed true

status="$(request appt-cancel -X POST -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/experts/appointments/${APPT_ID}/cancel")"
[ "${status}" = '200' ] || fail "appointment cancel returned HTTP ${status}"
assert_json "${TMP_DIR}/appt-cancel.json" data.points_refunded 10000

# ---------------------------------------------------------------------------
# 2.5 退票管理：管理端退款单读取权限与租户范围（人工确认由 DB 门禁覆盖）
# ---------------------------------------------------------------------------
status="$(request refund-list-anon -X GET \
    "${BASE_URL}/chamber/admin/v1/refunds")"
[ "${status}" = '401' ] || fail "refund list without auth returned HTTP ${status}"

status="$(request refund-list-admin -X GET -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/refunds")"
[ "${status}" = '200' ] || fail "refund list with admin token returned HTTP ${status}"
node - "${TMP_DIR}/refund-list-admin.json" <<'NODE' || fail 'Refund list envelope is invalid'
const fs = require('fs');
const body = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (body.status !== 200 || !Array.isArray(body.data.items) || typeof body.data.total !== 'number') process.exit(1);
NODE

status="$(request refund-confirm-missing -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Idempotency-Key: ch-refund-missing-1' -H 'Content-Type: application/json' --data '{"reason":"finance check"}' \
    "${BASE_URL}/chamber/admin/v1/refunds/999999999/confirm")"
[ "${status}" = '404' ] || fail "refund confirm on missing attempt returned HTTP ${status}"
assert_json "${TMP_DIR}/refund-confirm-missing.json" data.reason refund_attempt_not_found

# ---------------------------------------------------------------------------
# 2.6 候补：路由/鉴权/校验连通（完整转正流程由 event_waitlist_db_run 覆盖）
# ---------------------------------------------------------------------------
status="$(request waitlist-anon -X POST -H 'Idempotency-Key: ch-wl-anon' \
    -H 'Content-Type: application/json' --data '{"ticket_id":1}' \
    "${BASE_URL}/chamber/v1/events/1/waitlist")"
[ "${status}" = '401' ] || fail "waitlist join without token returned HTTP ${status}"

status="$(request waitlist-mine -X GET -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/waitlist")"
[ "${status}" = '200' ] || fail "my waitlist returned HTTP ${status}: $(cat "${TMP_DIR}/waitlist-mine.json")"
node - "${TMP_DIR}/waitlist-mine.json" <<'NODE' || fail 'Waitlist list envelope is invalid'
const fs = require('fs');
const body = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (body.status !== 200 || !Array.isArray(body.data.items)) process.exit(1);
NODE

status="$(request waitlist-missing-ticket -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Idempotency-Key: ch-wl-missing' -H 'Content-Type: application/json' \
    --data '{"ticket_id":2147483647}' \
    "${BASE_URL}/chamber/v1/events/1/waitlist")"
[ "${status}" = '404' ] || fail "waitlist join on missing ticket returned HTTP ${status}"
assert_json "${TMP_DIR}/waitlist-missing-ticket.json" data.reason ticket_not_found

# 会员 token 打 admin 路由：CRMEB admin 鉴权解析失败会清掉该 token 的缓存，
# 因此这条负向断言必须放在所有会员请求之后，否则会毒化 ${TOKEN}。
status="$(request refund-list-member -X GET -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/refunds")"
[ "${status}" = '401' ] || fail "refund list with member token returned HTTP ${status}"

echo "PASS: chamber HTTP acceptance (notification read isolation + appointment idempotency + refund admin permissions + waitlist routing)"
