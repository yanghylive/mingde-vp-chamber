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
# 3. 结算：配规则 → settle → run-due → 分账单 done
# ---------------------------------------------------------------------------
status="$(request settle-rule -X PUT -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' \
    --data '{"business_type":"membership_fee","rules":[{"receiver_type":"company","receiver_id":9001,"receiver_name":"验收公司A","ratio":40,"channel":"bank"},{"receiver_type":"company","receiver_id":9002,"receiver_name":"验收公司B","ratio":40,"channel":"bank"},{"receiver_type":"company","receiver_id":9003,"receiver_name":"验收公司C","ratio":20,"channel":"bank"}]}' \
    "${BASE_URL}/chamber/admin/v1/settlement/rules")"
[ "${status}" = '200' ] || fail "settlement rule save returned HTTP ${status}"

status="$(request settle-create -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' \
    --data '{"business_type":"membership_fee","order_no":"CHTEST001","order_amount":"100.00"}' \
    "${BASE_URL}/chamber/admin/v1/settlement/settle")"
[ "${status}" = '200' ] || fail "settle returned HTTP ${status}"

status="$(request settle-run -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/settlement/run-due")"
[ "${status}" = '200' ] || fail "run-due returned HTTP ${status}"
assert_json "${TMP_DIR}/settle-run.json" data.done 3

status="$(request settle-list -X GET -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/settlement/settlements")"
[ "${status}" = '200' ] || fail "settlement list returned HTTP ${status}"
assert_json "${TMP_DIR}/settle-list.json" data.items.0.status done

echo "PASS: chamber HTTP acceptance (notification read isolation + appointment idempotency + settlement claim)"
