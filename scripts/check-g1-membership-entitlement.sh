#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
TMP_DIR="$(mktemp -d)"
TOKEN=''
UID_VALUE=''

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/membership_checkout_fixture.php cleanup \
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
    curl -sS --max-time 30 -o "${TMP_DIR}/${name}.json" -w '%{http_code}' "$@"
}

for command in docker curl node; do
    command -v "${command}" >/dev/null 2>&1 || fail "Required command not found: ${command}"
done

cd "${PROJECT_ROOT}"
bash -n scripts/check-g1-membership-entitlement.sh
./scripts/prepare-local-crmeb-runtime.sh install >/dev/null
./scripts/manage-local-database.sh setup >/dev/null

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php setup > "${TMP_DIR}/setup.json"
TOKEN="$(json_value "${TMP_DIR}/setup.json" token)"
UID_VALUE="$(json_value "${TMP_DIR}/setup.json" uid)"

checkout() {
    local name="$1" key="$2"
    local status
    status="$(request "${name}" -X POST \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Idempotency-Key: ${key}" \
        -H 'Content-Type: application/json' \
        --data '{"plan_code":"L3_ANNUAL","plan_version":1,"expected_amount":"1000.00","currency":"CNY"}' \
        "${BASE_URL}/chamber/v1/membership/checkouts")"
    [ "${status}" = '201' ] || fail "${name} returned HTTP ${status}"
    assert_json "${TMP_DIR}/${name}.json" data.order_status pending_payment
}

complete() {
    local name="$1"
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/membership_checkout_fixture.php complete "${UID_VALUE}" \
        > "${TMP_DIR}/${name}.json"
    assert_json "${TMP_DIR}/${name}.json" result true
    assert_json "${TMP_DIR}/${name}.json" pay_status 1
    assert_json "${TMP_DIR}/${name}.json" completion_kind paid
    assert_json "${TMP_DIR}/${name}.json" term_count 1
    assert_json "${TMP_DIR}/${name}.json" effect_count 1
    assert_json "${TMP_DIR}/${name}.json" event_count 1
    assert_json "${TMP_DIR}/${name}.json" event_processed processed
}

checkout first g1e-entitlement-first

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php native-mutation-guards "${UID_VALUE}" \
    > "${TMP_DIR}/native-guards.json"
assert_json "${TMP_DIR}/native-guards.json" delivery_service app\\chamber\\services\\GuardedStoreOrderDeliveryServices
assert_json "${TMP_DIR}/native-guards.json" out_service app\\chamber\\services\\GuardedOutStoreOrderServices
assert_json "${TMP_DIR}/native-guards.json" delivery_rejected true
assert_json "${TMP_DIR}/native-guards.json" out_receive_rejected true
assert_json "${TMP_DIR}/native-guards.json" paid 0
assert_json "${TMP_DIR}/native-guards.json" status 0

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php complete-mismatch "${UID_VALUE}" \
    > "${TMP_DIR}/mismatch.json"
assert_json "${TMP_DIR}/mismatch.json" rejected true
assert_json "${TMP_DIR}/mismatch.json" order_paid 0
assert_json "${TMP_DIR}/mismatch.json" context_pay_status 0
assert_json "${TMP_DIR}/mismatch.json" event_count 0
assert_json "${TMP_DIR}/mismatch.json" term_count 0

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php complete-interrupted "${UID_VALUE}" \
    > "${TMP_DIR}/interrupted.json"
assert_json "${TMP_DIR}/interrupted.json" rolled_back true
assert_json "${TMP_DIR}/interrupted.json" order_paid 0
assert_json "${TMP_DIR}/interrupted.json" context_pay_status 0
assert_json "${TMP_DIR}/interrupted.json" event_count 0
assert_json "${TMP_DIR}/interrupted.json" term_count 0

complete first-complete
for replay in $(seq 1 10); do
    complete "first-replay-${replay}"
done
assert_json "${TMP_DIR}/first-replay-10.json" term_count 1
assert_json "${TMP_DIR}/first-replay-10.json" effect_count 1

checkout renewal-one g1e-entitlement-renewal-one
checkout renewal-two g1e-entitlement-renewal-two

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/before-renewals.json"
read -r CONTEXT_ONE CONTEXT_TWO < <(node - "${TMP_DIR}/before-renewals.json" <<'NODE'
const fs = require('fs');
const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const ids = data.contexts.filter(context => Number(context.pay_status) === 0).map(context => Number(context.id));
if (ids.length !== 2 || ids.some(id => !Number.isInteger(id) || id < 1)) process.exit(1);
process.stdout.write(ids.join(' ') + '\n');
NODE
) || fail 'Two pending renewal contexts were not created'

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php complete-context \
    "${UID_VALUE}" "${CONTEXT_ONE}" > "${TMP_DIR}/renewal-one-complete.json" &
PID_ONE=$!
docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php complete-context \
    "${UID_VALUE}" "${CONTEXT_TWO}" > "${TMP_DIR}/renewal-two-complete.json" &
PID_TWO=$!
wait "${PID_ONE}" || fail 'First concurrent renewal failed'
wait "${PID_TWO}" || fail 'Second concurrent renewal failed'
for result in renewal-one-complete renewal-two-complete; do
    assert_json "${TMP_DIR}/${result}.json" result true
    assert_json "${TMP_DIR}/${result}.json" term_count 1
    assert_json "${TMP_DIR}/${result}.json" effect_count 1
    assert_json "${TMP_DIR}/${result}.json" event_processed processed
done

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/after-renewals.json"
node - "${TMP_DIR}/after-renewals.json" <<'NODE' || fail 'Concurrent renewal terms were not serialized'
const fs = require('fs');
const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (!data.member || Number(data.member.tier) !== 3) process.exit(1);
if (!data.terms || data.terms.length !== 3) process.exit(1);
if (!data.effects || data.effects.length !== 3) process.exit(1);
const terms = [...data.terms].sort((a, b) => Number(a.effective_start_time) - Number(b.effective_start_time));
for (let index = 1; index < terms.length; index += 1) {
  if (Number(terms[index].effective_start_time) < Number(terms[index - 1].effective_end_time)) process.exit(1);
}
if (data.events.length !== 3 || data.events.some(event => event.status !== 'processed')) process.exit(1);
NODE

status="$(request summary -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/membership")"
[ "${status}" = '200' ] || fail "Membership summary returned HTTP ${status}"
assert_json "${TMP_DIR}/summary.json" data.effective_tier L3
assert_json "${TMP_DIR}/summary.json" data.verification_status approved
assert_json "${TMP_DIR}/summary.json" data.can_purchase true
assert_json "${TMP_DIR}/summary.json" data.active_terms.0.tier L3

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php expire "${UID_VALUE}" \
    > "${TMP_DIR}/expire.json"
assert_json "${TMP_DIR}/expire.json" member_tier 2
assert_json "${TMP_DIR}/expire.json" current_term_id 0
assert_json "${TMP_DIR}/expire.json" expired_count 3

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php refund-full "${UID_VALUE}" \
    > "${TMP_DIR}/refund.json"
assert_json "${TMP_DIR}/refund.json" context_refund_status 4
assert_json "${TMP_DIR}/refund.json" term_state 3
assert_json "${TMP_DIR}/refund.json" refund_effect_count 1
assert_json "${TMP_DIR}/refund.json" member_tier 2

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php refund-full "${UID_VALUE}" \
    > "${TMP_DIR}/refund-replay.json"
assert_json "${TMP_DIR}/refund-replay.json" refund_effect_count 1
assert_json "${TMP_DIR}/refund-replay.json" term_state 3

printf 'G1-01E membership entitlement gate OK\n'
printf 'Payment: mismatch fail-closed, transaction rollback, inbox, 10 replays, two concurrent renewals\n'
printf 'Projection: summary API, expiry fallback, full-refund effect and replay\n'
