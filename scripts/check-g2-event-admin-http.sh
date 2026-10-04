#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
TMP_DIR="$(mktemp -d)"
ADMIN_TOKEN=''
DENIED_TOKEN=''

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

fixture() {
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/event_admin_http_fixture.php "$@"
}

cleanup() {
    fixture cleanup "${ADMIN_TOKEN}" "${DENIED_TOKEN}" >/dev/null 2>&1 || true
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

fixture setup > "${TMP_DIR}/setup.json"
ADMIN_TOKEN="$(json_value "${TMP_DIR}/setup.json" admin_token)"
DENIED_TOKEN="$(json_value "${TMP_DIR}/setup.json" denied_token)"
FOREIGN_EVENT_ID="$(json_value "${TMP_DIR}/setup.json" foreign_event_id)"
RUN="$(date +%s)-$$"
NOW="$(date +%s)"

RUN_PAYLOAD="{\"event_type\":\"industry\",\"title\":\"Admin HTTP ${RUN}\",\"summary\":\"acceptance\",\"tags\":[\"AI\"],\"start_time\":$((NOW + 14400)),\"end_time\":$((NOW + 18000)),\"signup_start_time\":$((NOW - 600)),\"signup_end_time\":$((NOW + 7200)),\"location_name\":\"Venue\",\"checkin_reward_points\":3,\"checkin_reward_contribution\":1,\"tickets\":[{\"name\":\"Free\",\"price\":\"0.00\",\"integral_price\":0,\"capacity\":20,\"sale_start_time\":$((NOW - 600)),\"sale_end_time\":$((NOW + 7200)),\"status\":1}]}"

# ---------------------------------------------------------------------------
# 1. 创建：鉴权 / 幂等重放 / 同键异体冲突
# ---------------------------------------------------------------------------
status="$(request create-anon -X POST -H 'Content-Type: application/json' \
    -H 'Idempotency-Key: aw-anon' --data "${RUN_PAYLOAD}" \
    "${BASE_URL}/chamber/admin/v1/events")"
[ "${status}" = '401' ] || fail "admin event create without token returned HTTP ${status}"

status="$(request create-denied -X POST -H "Authorization: Bearer ${DENIED_TOKEN}" \
    -H 'Content-Type: application/json' -H 'Idempotency-Key: aw-denied' --data "${RUN_PAYLOAD}" \
    "${BASE_URL}/chamber/admin/v1/events")"
[ "${status}" = '403' ] || fail "admin event create without manage permission returned HTTP ${status}"

CREATE_KEY="aw-create-${RUN}"
status="$(request create -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: ${CREATE_KEY}" --data "${RUN_PAYLOAD}" \
    "${BASE_URL}/chamber/admin/v1/events")"
[ "${status}" = '201' ] || fail "admin event create returned HTTP ${status}: $(cat "${TMP_DIR}/create.json")"
EVENT_ID="$(json_value "${TMP_DIR}/create.json" data.id)"

status="$(request create-replay -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: ${CREATE_KEY}" --data "${RUN_PAYLOAD}" \
    "${BASE_URL}/chamber/admin/v1/events")"
[ "${status}" = '201' ] || fail "admin event create replay returned HTTP ${status}"
assert_json "${TMP_DIR}/create-replay.json" data.id "${EVENT_ID}"

CONFLICT_PAYLOAD="{\"event_type\":\"industry\",\"title\":\"Different ${RUN}\",\"start_time\":$((NOW + 14400)),\"end_time\":$((NOW + 18000)),\"signup_start_time\":$((NOW - 600)),\"signup_end_time\":$((NOW + 7200)),\"tickets\":[{\"name\":\"Free\",\"price\":\"0.00\",\"integral_price\":0,\"capacity\":10,\"sale_start_time\":$((NOW - 600)),\"sale_end_time\":$((NOW + 7200)),\"status\":1}]}"
status="$(request create-conflict -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: ${CREATE_KEY}" --data "${CONFLICT_PAYLOAD}" \
    "${BASE_URL}/chamber/admin/v1/events")"
[ "${status}" = '409' ] || fail "admin event create idempotency conflict returned HTTP ${status}"
assert_json "${TMP_DIR}/create-conflict.json" data.reason idempotency_conflict

# ---------------------------------------------------------------------------
# 2. 编辑
# ---------------------------------------------------------------------------
status="$(request update -X PATCH -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: aw-update-${RUN}" \
    --data "${RUN_PAYLOAD}" \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_ID}")"
[ "${status}" = '200' ] || fail "admin event update returned HTTP ${status}: $(cat "${TMP_DIR}/update.json")"
assert_json "${TMP_DIR}/update.json" data.id "${EVENT_ID}"

# ---------------------------------------------------------------------------
# 3. 发布（幂等重放）
# ---------------------------------------------------------------------------
PUBLISH_KEY="aw-publish-${RUN}"
status="$(request publish -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "Idempotency-Key: ${PUBLISH_KEY}" \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_ID}/publish")"
[ "${status}" = '200' ] || fail "admin event publish returned HTTP ${status}: $(cat "${TMP_DIR}/publish.json")"
status="$(request publish-replay -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "Idempotency-Key: ${PUBLISH_KEY}" \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_ID}/publish")"
[ "${status}" = '200' ] || fail "admin event publish replay returned HTTP ${status}"

# ---------------------------------------------------------------------------
# 4. 签发动态签到码
# ---------------------------------------------------------------------------
status="$(request token -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: aw-token-${RUN}" \
    --data '{"ttl_seconds":300}' \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_ID}/checkin-token")"
[ "${status}" = '201' ] || fail "admin checkin token returned HTTP ${status}: $(cat "${TMP_DIR}/token.json")"

# ---------------------------------------------------------------------------
# 5. 人工补签（先造一条已支付报名）
# ---------------------------------------------------------------------------
fixture registration "${EVENT_ID}" > "${TMP_DIR}/reg.json"
REGISTRATION_ID="$(json_value "${TMP_DIR}/reg.json" registration_id)"
status="$(request manual -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: aw-manual-${RUN}" \
    --data "{\"registration_id\":${REGISTRATION_ID},\"reason\":\"现场补签\"}" \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_ID}/checkins/manual")"
[ "${status}" = '201' ] || fail "admin manual checkin returned HTTP ${status}: $(cat "${TMP_DIR}/manual.json")"

hbefore="$(fixture inspect "${EVENT_ID}" "${REGISTRATION_ID}")"
[ "$(printf '%s' "${hbefore}" | node -e 'let d="";process.stdin.on("data",c=>d+=c).on("end",()=>console.log(JSON.parse(d).checkin_count))')" = '1' ] \
    || fail "manual checkin did not persist exactly once"

# ---------------------------------------------------------------------------
# 6. 取消（单独建一个无报名的活动；幂等重放 + 审计）
# ---------------------------------------------------------------------------
status="$(request create-b -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: aw-create-b-${RUN}" --data "${RUN_PAYLOAD}" \
    "${BASE_URL}/chamber/admin/v1/events")"
[ "${status}" = '201' ] || fail "second admin event create returned HTTP ${status}"
EVENT_B="$(json_value "${TMP_DIR}/create-b.json" data.id)"
status="$(request publish-b -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "Idempotency-Key: aw-publish-b-${RUN}" \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_B}/publish")"
[ "${status}" = '200' ] || fail "second admin event publish returned HTTP ${status}"

CANCEL_KEY="aw-cancel-${RUN}"
status="$(request cancel -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: ${CANCEL_KEY}" \
    --data '{"reason":"日程调整"}' \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_B}/cancel")"
[ "${status}" = '200' ] || fail "admin event cancel returned HTTP ${status}: $(cat "${TMP_DIR}/cancel.json")"
status="$(request cancel-replay -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H 'Content-Type: application/json' -H "Idempotency-Key: ${CANCEL_KEY}" \
    --data '{"reason":"日程调整"}' \
    "${BASE_URL}/chamber/admin/v1/events/${EVENT_B}/cancel")"
[ "${status}" = '200' ] || fail "admin event cancel replay returned HTTP ${status}"

AFTER="$(fixture inspect "${EVENT_B}" "${REGISTRATION_ID}")"
[ "$(printf '%s' "${AFTER}" | node -e 'let d="";process.stdin.on("data",c=>d+=c).on("end",()=>console.log(JSON.parse(d).event_status))')" = '4' ] \
    || fail "cancel did not move the event to cancelled"

# ---------------------------------------------------------------------------
# 7. 跨租户管理写：他租户事件不可发布（404，不泄露存在性）
# ---------------------------------------------------------------------------
status="$(request foreign-publish -X POST -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "Idempotency-Key: aw-foreign-publish-${RUN}" \
    "${BASE_URL}/chamber/admin/v1/events/${FOREIGN_EVENT_ID}/publish")"
[ "${status}" = '404' ] || fail "cross-tenant publish returned HTTP ${status}"

printf 'G2 event admin write HTTP gate OK\n'
printf 'HTTP: create/update/publish/checkin-token/manual-checkin/cancel, idempotent replay, conflict, permissions, cross-tenant\n'