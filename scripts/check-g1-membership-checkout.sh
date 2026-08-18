#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
TMP_DIR="$(mktemp -d)"
TOKEN=''
UID_VALUE=''
PRODUCT_ID=''
PRODUCT_ATTR_UNIQUE=''

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/membership_checkout_fixture.php cleanup "${TOKEN}" \
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

for command in docker curl node ruby; do
    command -v "${command}" >/dev/null 2>&1 || fail "Required command not found: ${command}"
done

cd "${PROJECT_ROOT}"
bash -n scripts/check-g1-membership-checkout.sh
./scripts/prepare-local-crmeb-runtime.sh install >/dev/null
./scripts/manage-local-database.sh setup >/dev/null

domain_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_run.php)"
printf '%s\n' "${domain_output}"
grep -Fxq '14 tests, 0 failures' <<<"${domain_output}" \
    || fail 'Membership checkout domain gate failed'

gateway_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_order_gateway_run.php)"
printf '%s\n' "${gateway_output}"
grep -Fxq '11 tests, 0 failures' <<<"${gateway_output}" \
    || fail 'Membership order gateway gate failed'

db_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_db_run.php)"
printf '%s\n' "${db_output}"
grep -Fxq 'PASS membership checkout database service (84 assertions; fixtures removed)' <<<"${db_output}" \
    || fail 'Membership checkout database gate failed'

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php setup > "${TMP_DIR}/setup.json"
TOKEN="$(json_value "${TMP_DIR}/setup.json" token)"
UID_VALUE="$(json_value "${TMP_DIR}/setup.json" uid)"
PRODUCT_ID="$(json_value "${TMP_DIR}/setup.json" product_id)"
PRODUCT_ATTR_UNIQUE="$(json_value "${TMP_DIR}/setup.json" product_attr_unique)"

status="$(request plans-missing-token "${BASE_URL}/chamber/v1/membership/plans")"
[ "${status}" = '200' ] || fail "Plans without token returned HTTP ${status}"

status="$(request plans -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/membership/plans")"
[ "${status}" = '200' ] || fail "Membership plans returned HTTP ${status}"
assert_json "${TMP_DIR}/plans.json" data.plans.0.code L2_ANNUAL
assert_json "${TMP_DIR}/plans.json" data.plans.0.price 1000.00
assert_json "${TMP_DIR}/plans.json" data.plans.0.eligible true
assert_json "${TMP_DIR}/plans.json" data.plans.1.code L3_ANNUAL
assert_json "${TMP_DIR}/plans.json" data.plans.1.price 5000.00
node - "${TMP_DIR}/plans.json" <<'NODE' || fail 'Plans leaked CRMEB product mapping'
const fs = require('fs');
const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (data.data.plans.some(plan => 'product_id' in plan || 'product_attr_unique' in plan)) process.exit(1);
NODE

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php seed-cart "${UID_VALUE}" \
    > "${TMP_DIR}/seed-cart.json"
CART_ID="$(json_value "${TMP_DIR}/seed-cart.json" cart_id)"

assert_native_read_denied() {
    local name="$1"
    shift
    local http_status body_status message
    http_status="$(request "${name}" "$@")"
    [ "${http_status}" = '200' ] || fail "Native membership boundary ${name} returned HTTP ${http_status}"
    body_status="$(json_value "${TMP_DIR}/${name}.json" status)"
    [ "${body_status}" = '400' ] || fail "Native membership boundary ${name} returned body status ${body_status}"
    message="$(json_value "${TMP_DIR}/${name}.json" msg)"
    [[ "${message}" == *'会籍'* ]] || fail "Native membership boundary ${name} was not rejected: ${message}"
}

assert_native_read_denied native-cart-list \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/cart/list"
assert_native_read_denied native-cart-count \
    -G -H "Authorization: Bearer ${TOKEN}" \
    --data-urlencode 'numType=true' \
    "${BASE_URL}/api/cart/count"
assert_native_read_denied native-cart-num \
    -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' --data "{\"id\":${CART_ID},\"number\":2}" \
    "${BASE_URL}/api/cart/num"
assert_native_read_denied native-cart-delete \
    -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' --data "{\"ids\":\"${CART_ID}\"}" \
    "${BASE_URL}/api/cart/del"

status="$(request native-membership-cart -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    --data "{\"productId\":${PRODUCT_ID},\"cartNum\":1,\"uniqueId\":\"${PRODUCT_ATTR_UNIQUE}\",\"new\":1}" \
    "${BASE_URL}/api/cart/add")"
[ "${status}" = '200' ] || fail "Native CRMEB membership cart returned HTTP ${status}"
node - "${TMP_DIR}/native-membership-cart.json" <<'NODE' || fail 'Native CRMEB membership cart bypassed Chamber'
const fs = require('fs');
const body = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (body.status !== 400 || !String(body.msg || '').includes('会籍商品只能从会籍中心购买')) process.exit(1);
NODE

status="$(request native-membership-cart-v2 -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    --data "{\"product_id\":${PRODUCT_ID},\"num\":1,\"unique\":\"${PRODUCT_ATTR_UNIQUE}\",\"type\":-1}" \
    "${BASE_URL}/api/v2/set_cart_num")"
[ "${status}" = '200' ] || fail "Native CRMEB v2 membership cart returned HTTP ${status}"
node - "${TMP_DIR}/native-membership-cart-v2.json" <<'NODE' || fail 'Native CRMEB v2 membership cart bypassed Chamber'
const fs = require('fs');
const body = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
if (body.status !== 400 || !String(body.msg || '').includes('会籍商品只能从会籍中心购买')) process.exit(1);
NODE

runtime_classes="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm php -r '
require "/var/www/vendor/autoload.php";
$app = new \think\App();
$app->initialize();
foreach ([
    \app\services\order\StoreCartServices::class,
    \app\services\order\StoreOrderCartInfoServices::class,
    \app\services\order\StoreOrderServices::class,
    \app\services\order\StoreOrderRefundServices::class,
    \app\services\order\StoreOrderCreateServices::class,
    \app\services\order\StoreOrderDeliveryServices::class,
    \app\services\order\StoreOrderSuccessServices::class,
    \app\services\order\OutStoreOrderServices::class,
    \app\services\order\StoreOrderTakeServices::class,
] as $service) {
    echo get_class($app->make($service)), "\n";
}
' 2>&1)" || fail 'Unable to initialize the CRMEB root application'
printf '%s\n' "${runtime_classes}"
grep -Fxq 'app\chamber\services\GuardedStoreOrderTakeServices' <<<"${runtime_classes}" \
    || fail 'CRMEB root provider did not bind guarded order-take services'
grep -Fxq 'app\chamber\services\GuardedStoreOrderServices' <<<"${runtime_classes}" \
    || fail 'CRMEB root provider did not bind guarded order services'
grep -Fxq 'app\chamber\services\GuardedStoreOrderRefundServices' <<<"${runtime_classes}" \
    || fail 'CRMEB root provider did not bind guarded refund services'
grep -Fxq 'app\chamber\services\GuardedStoreOrderCartInfoServices' <<<"${runtime_classes}" \
    || fail 'CRMEB root provider did not bind guarded order-cart services'
grep -Fxq 'app\chamber\services\GuardedStoreOrderDeliveryServices' <<<"${runtime_classes}" \
    || fail 'CRMEB root provider did not bind guarded delivery services'
grep -Fxq 'app\chamber\services\GuardedStoreOrderSuccessServices' <<<"${runtime_classes}" \
    || fail 'CRMEB root provider did not bind trusted payment services'
grep -Fxq 'app\chamber\services\GuardedOutStoreOrderServices' <<<"${runtime_classes}" \
    || fail 'CRMEB root provider did not bind guarded OutAPI order services'

checkout_body='{"plan_code":"L3_ANNUAL","plan_version":1,"expected_amount":"5000.00","currency":"CNY"}'
status="$(request checkout-missing-key -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' --data "${checkout_body}" \
    "${BASE_URL}/chamber/v1/membership/checkouts")"
[ "${status}" = '400' ] || fail "Checkout without idempotency key returned HTTP ${status}"
assert_json "${TMP_DIR}/checkout-missing-key.json" data.reason idempotency_key_required

checkout_key="g1d-http-$(date +%s)-$$"
for name in checkout checkout-replay; do
    status="$(request "${name}" -X POST -H "Authorization: Bearer ${TOKEN}" \
        -H "Idempotency-Key: ${checkout_key}" -H 'Content-Type: application/json' \
        --data "${checkout_body}" "${BASE_URL}/chamber/v1/membership/checkouts")"
    [ "${status}" = '201' ] || fail "${name} returned HTTP ${status}"
    assert_json "${TMP_DIR}/${name}.json" data.payable_amount 5000.00
    assert_json "${TMP_DIR}/${name}.json" data.currency CNY
    assert_json "${TMP_DIR}/${name}.json" data.order_status pending_payment
    assert_json "${TMP_DIR}/${name}.json" data.payment_required true
done
assert_json "${TMP_DIR}/checkout.json" data.replayed false
assert_json "${TMP_DIR}/checkout-replay.json" data.replayed true
assert_json "${TMP_DIR}/checkout-replay.json" data.order_no \
    "$(json_value "${TMP_DIR}/checkout.json" data.order_no)"
assert_json "${TMP_DIR}/checkout-replay.json" data.context_no \
    "$(json_value "${TMP_DIR}/checkout.json" data.context_no)"

status="$(request checkout-conflict -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${checkout_key}" -H 'Content-Type: application/json' \
    --data '{"plan_code":"L3_ANNUAL","plan_version":1,"expected_amount":"5000.01","currency":"CNY"}' \
    "${BASE_URL}/chamber/v1/membership/checkouts")"
[ "${status}" = '409' ] || fail "Checkout idempotency conflict returned HTTP ${status}"
assert_json "${TMP_DIR}/checkout-conflict.json" data.reason idempotency_conflict

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/inspect.json"
assert_json "${TMP_DIR}/inspect.json" context_count 1
assert_json "${TMP_DIR}/inspect.json" order_count 1
assert_json "${TMP_DIR}/inspect.json" idempotency_count 1
assert_json "${TMP_DIR}/inspect.json" contexts.0.payable_amount 5000.00
assert_json "${TMP_DIR}/inspect.json" orders.0.pay_price 5000.00
assert_json "${TMP_DIR}/inspect.json" orders.0.pay_type weixin
assert_json "${TMP_DIR}/inspect.json" orders.0.total_num 1
assert_json "${TMP_DIR}/inspect.json" orders.0.use_integral 0
assert_json "${TMP_DIR}/inspect.json" orders.0.cart_rows 1
assert_json "${TMP_DIR}/inspect.json" orders.0.unique \
    "$(json_value "${TMP_DIR}/checkout.json" data.context_no)"
assert_json "${TMP_DIR}/inspect.json" orders.0.gain_integral 0
assert_json "${TMP_DIR}/inspect.json" orders.0.one_brokerage 0.00
assert_json "${TMP_DIR}/inspect.json" orders.0.two_brokerage 0.00
assert_json "${TMP_DIR}/inspect.json" orders.0.staff_brokerage 0.00
assert_json "${TMP_DIR}/inspect.json" orders.0.agent_brokerage 0.00
assert_json "${TMP_DIR}/inspect.json" orders.0.division_brokerage 0.00

ORDER_NO="$(json_value "${TMP_DIR}/inspect.json" orders.0.order_id)"
ORDER_PK="$(json_value "${TMP_DIR}/inspect.json" orders.0.id)"
CART_UNIQUE="$(json_value "${TMP_DIR}/inspect.json" orders.0.cart_unique)"

assert_native_read_denied native-order-list \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/order/list"
assert_native_read_denied native-order-detail \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/order/detail/${ORDER_NO}"
assert_native_read_denied native-order-cashier \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/order/cashier/${ORDER_NO}/order"
assert_native_read_denied native-order-friend-detail \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/order/friend_detail?order_id=${ORDER_PK}"
assert_native_read_denied native-order-gift-detail \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/order/gift_detail/${ORDER_PK}"
assert_native_read_denied native-order-refund-cart \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/order/refund/cart_info/${ORDER_PK}"
assert_native_read_denied native-order-product \
    -X POST -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    --data "{\"unique\":\"${CART_UNIQUE}\"}" \
    "${BASE_URL}/api/order/product"
assert_native_read_denied native-order-data \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/api/order/data"

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php make-repairable "${UID_VALUE}" \
    > "${TMP_DIR}/make-repairable.json"
docker compose -f "${COMPOSE_FILE}" exec -T phpfpm php -r '
require "/var/www/vendor/autoload.php";
$app = new \think\App();
$app->initialize();
$row = \think\facade\Db::table("eb_system_timer")
    ->where("name", "Chamber membership order context repair")
    ->where("is_open", 1)
    ->where("is_del", 0)
    ->find();
if (!is_array($row)) {
    fwrite(STDERR, "Membership repair timer is not registered\n");
    exit(1);
}
$code = json_decode((string) $row["customCode"]);
if (!is_string($code) || $code === "") {
    fwrite(STDERR, "Membership repair timer code is invalid\n");
    exit(1);
}
$runner = $app->make(\app\services\system\crontab\CrontabRunServices::class);
$runner->customTimer($code);
' || fail 'Native CRMEB timer executor did not run the registered membership repair job'

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/timer-inspect.json"
node - "${TMP_DIR}/timer-inspect.json" <<'NODE' || fail 'Native CRMEB timer did not repair the pending membership context'
const fs = require('fs');
const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const context = data.contexts && data.contexts[0];
const record = data.records && data.records[0];
process.exit(context && Number(context.order_pk) > 0 && record && record.status === 'succeeded' ? 0 : 1);
NODE
cp "${TMP_DIR}/timer-inspect.json" "${TMP_DIR}/inspect.json"
assert_json "${TMP_DIR}/inspect.json" context_count 1
assert_json "${TMP_DIR}/inspect.json" order_count 1
assert_json "${TMP_DIR}/inspect.json" idempotency_count 1

ruby backend/custom/openapi/validate.rb
[ -z "$(git -C backend/crmeb status --porcelain)" ] || fail 'CRMEB upstream tree is dirty'
git diff --check

printf 'G1-01D membership checkout gate OK\n'
printf 'HTTP: plans, native v1/v2 cart denial, native order/read/refund isolation, deterministic CRMEB order, OrderContext bind, replay, conflict, timer repair\n'
