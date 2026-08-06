#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
TMP_DIR="$(mktemp -d)"
TOKEN=''

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

cleanup() {
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/event_registration_http_fixture.php cleanup "${TOKEN}" \
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
    php /var/www/app/chamber/tests/event_registration_http_fixture.php cleanup >/dev/null
./scripts/manage-local-database.sh setup >/dev/null
docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/event_registration_http_fixture.php setup > "${TMP_DIR}/setup.json"

TOKEN="$(json_value "${TMP_DIR}/setup.json" token)"
UID_VALUE="$(json_value "${TMP_DIR}/setup.json" uid)"
PRODUCT_ID="$(json_value "${TMP_DIR}/setup.json" product_id)"
PRODUCT_ATTR_UNIQUE="$(json_value "${TMP_DIR}/setup.json" product_attr_unique)"
FREE_EVENT_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.free.event_id)"
FREE_TICKET_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.free.ticket_id)"
POINTS_EVENT_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.points.event_id)"
POINTS_TICKET_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.points.ticket_id)"
CASH_EVENT_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.cash.event_id)"
CASH_TICKET_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.cash.ticket_id)"
MIXED_EVENT_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.mixed.event_id)"
MIXED_TICKET_ID="$(json_value "${TMP_DIR}/setup.json" fixtures.mixed.ticket_id)"

status="$(request missing-token -X POST -H 'Content-Type: application/json' \
    -H 'Idempotency-Key: g2-http-missing-token' --data "{\"ticket_id\":${FREE_TICKET_ID}}" \
    "${BASE_URL}/chamber/v1/events/${FREE_EVENT_ID}/registrations")"
[ "${status}" = '401' ] || fail "Registration without token returned HTTP ${status}"
assert_json "${TMP_DIR}/missing-token.json" data.reason authentication_required

status="$(request missing-key -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' --data "{\"ticket_id\":${FREE_TICKET_ID}}" \
    "${BASE_URL}/chamber/v1/events/${FREE_EVENT_ID}/registrations")"
[ "${status}" = '400' ] || fail "Registration without idempotency key returned HTTP ${status}"
assert_json "${TMP_DIR}/missing-key.json" data.reason idempotency_key_required

register() {
    local name="$1" event_id="$2" key="$3" body="$4" status
    status="$(request "${name}" -X POST -H "Authorization: Bearer ${TOKEN}" \
        -H "Idempotency-Key: ${key}" -H 'Content-Type: application/json' --data "${body}" \
        "${BASE_URL}/chamber/v1/events/${event_id}/registrations")"
    [ "${status}" = '201' ] || fail "${name} returned HTTP ${status}: $(cat "${TMP_DIR}/${name}.json")"
}

run_key="g2-http-$(date +%s)-$$"
register free "${FREE_EVENT_ID}" "${run_key}-free" \
    "{\"ticket_id\":${FREE_TICKET_ID},\"expected_amount\":\"0.00\",\"expected_integral\":0}"
assert_json "${TMP_DIR}/free.json" data.status registered
assert_json "${TMP_DIR}/free.json" data.payment_required false
register free-replay "${FREE_EVENT_ID}" "${run_key}-free" \
    "{\"ticket_id\":${FREE_TICKET_ID},\"expected_amount\":\"0.00\",\"expected_integral\":0}"
assert_json "${TMP_DIR}/free-replay.json" data.registration_no "$(json_value "${TMP_DIR}/free.json" data.registration_no)"

status="$(request free-conflict -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${run_key}-free" -H 'Content-Type: application/json' \
    --data "{\"ticket_id\":${FREE_TICKET_ID},\"expected_integral\":1}" \
    "${BASE_URL}/chamber/v1/events/${FREE_EVENT_ID}/registrations")"
[ "${status}" = '409' ] || fail "Registration idempotency conflict returned HTTP ${status}"
assert_json "${TMP_DIR}/free-conflict.json" data.reason idempotency_conflict

register points "${POINTS_EVENT_ID}" "${run_key}-points" \
    "{\"ticket_id\":${POINTS_TICKET_ID},\"expected_amount\":\"0.00\",\"expected_integral\":10}"
assert_json "${TMP_DIR}/points.json" data.status registered

status="$(request native-event-cart -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    --data "{\"productId\":${PRODUCT_ID},\"cartNum\":1,\"uniqueId\":\"${PRODUCT_ATTR_UNIQUE}\",\"new\":1}" \
    "${BASE_URL}/api/cart/add")"
[ "${status}" = '200' ] || fail "Native event cart returned HTTP ${status}"
node - "${TMP_DIR}/native-event-cart.json" <<'NODE' || fail 'Native CRMEB cart bypassed event guard'
const fs = require('fs');
const body = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (body.status !== 400 || !String(body.msg || '').includes('活动票只能从活动中心购买')) process.exit(1);
NODE

register cash "${CASH_EVENT_ID}" "${run_key}-cash" \
    "{\"ticket_id\":${CASH_TICKET_ID},\"expected_amount\":\"10.00\",\"expected_integral\":0}"
assert_json "${TMP_DIR}/cash.json" data.status pending_payment
assert_json "${TMP_DIR}/cash.json" data.payment_required true
register cash-replay "${CASH_EVENT_ID}" "${run_key}-cash" \
    "{\"ticket_id\":${CASH_TICKET_ID},\"expected_amount\":\"10.00\",\"expected_integral\":0}"
assert_json "${TMP_DIR}/cash-replay.json" data.order_no "$(json_value "${TMP_DIR}/cash.json" data.order_no)"

register mixed "${MIXED_EVENT_ID}" "${run_key}-mixed" \
    "{\"ticket_id\":${MIXED_TICKET_ID},\"expected_amount\":\"10.00\",\"expected_integral\":15}"
assert_json "${TMP_DIR}/mixed.json" data.status pending_payment

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/event_registration_http_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/before-payment.json"
assert_json "${TMP_DIR}/before-payment.json" registration_count 4
assert_json "${TMP_DIR}/before-payment.json" context_count 2
assert_json "${TMP_DIR}/before-payment.json" order_count 2
assert_json "${TMP_DIR}/before-payment.json" balance 75
assert_json "${TMP_DIR}/before-payment.json" frozen_balance 15
assert_json "${TMP_DIR}/before-payment.json" held_count 1
assert_json "${TMP_DIR}/before-payment.json" event_ledger_count 1

CASH_REGISTRATION_ID="$(json_value "${TMP_DIR}/cash.json" data.id)"
MIXED_REGISTRATION_ID="$(json_value "${TMP_DIR}/mixed.json" data.id)"
for registration_id in "${CASH_REGISTRATION_ID}" "${MIXED_REGISTRATION_ID}" "${MIXED_REGISTRATION_ID}"; do
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/event_registration_http_fixture.php complete "${registration_id}" \
        > "${TMP_DIR}/complete-${registration_id}.json"
    assert_json "${TMP_DIR}/complete-${registration_id}.json" result true
    assert_json "${TMP_DIR}/complete-${registration_id}.json" status 1
    assert_json "${TMP_DIR}/complete-${registration_id}.json" pay_status 1
done

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/event_registration_http_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/after-payment.json"
assert_json "${TMP_DIR}/after-payment.json" balance 75
assert_json "${TMP_DIR}/after-payment.json" frozen_balance 0
assert_json "${TMP_DIR}/after-payment.json" held_count 0
assert_json "${TMP_DIR}/after-payment.json" captured_count 1
assert_json "${TMP_DIR}/after-payment.json" event_ledger_count 2

status="$(request detail -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/event-registrations/${MIXED_REGISTRATION_ID}")"
[ "${status}" = '200' ] || fail "Paid registration detail returned HTTP ${status}"
assert_json "${TMP_DIR}/detail.json" data.status registered
assert_json "${TMP_DIR}/detail.json" data.order_status paid
assert_json "${TMP_DIR}/detail.json" data.payment_required false

ruby backend/custom/openapi/validate.rb >/dev/null
git diff --check
printf 'G2 event registration HTTP gate OK\n'
printf 'HTTP: authentication, idempotency, free, points, cash, mixed, native isolation, payment projection and replay\n'
