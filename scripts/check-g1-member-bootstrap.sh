#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
SIGNING_SECRET="${CHAMBER_TENANT_SIGNING_SECRET:-local-only-tenant-signing-secret-32-bytes}"
SIGNED_HOST="${CHAMBER_TEST_SIGNED_HOST:-signed.local.test}"
TMP_DIR="$(mktemp -d)"
TOKEN=''
NON_API_TOKEN=''
LEGACY_TOKEN=''
UID_VALUE=''

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/member_bootstrap_fixture.php cleanup \
        "${TOKEN}" "${NON_API_TOKEN}" "${LEGACY_TOKEN}" >/dev/null 2>&1 || true
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

json_value() {
    local file="$1" path="$2"
    node - "${file}" "${path}" <<'NODE'
const fs = require('fs');
const [file, path] = process.argv.slice(2);
const value = path.split('.').reduce((current, key) => current == null ? undefined : current[key], JSON.parse(fs.readFileSync(file, 'utf8')));
if (value === undefined) process.exit(2);
process.stdout.write(String(value));
NODE
}

assert_json() {
    local file="$1" path="$2" expected="$3" actual
    actual="$(json_value "${file}" "${path}")" || fail "Missing JSON path ${path} in ${file}"
    [ "${actual}" = "${expected}" ] || fail "Expected ${path}=${expected}, got ${actual}"
}

request() {
    local name="$1"
    shift
    curl -sS --max-time 20 -D "${TMP_DIR}/${name}.headers" -o "${TMP_DIR}/${name}.json" \
        -w '%{http_code}' "$@"
}

authenticated_request() {
    local name="$1" key="$2" body="$3"
    request "${name}" \
        -X POST \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Idempotency-Key: ${key}" \
        -H 'Content-Type: application/json' \
        --data "${body}" \
        "${BASE_URL}/chamber/v1/me/bootstrap"
}

run_concurrent_group() {
    local prefix="$1" key_mode="$2" body="$3" index key
    local gate="${TMP_DIR}/${prefix}.gate"
    local ready_dir="${TMP_DIR}/${prefix}.ready"
    mkdir -p "${ready_dir}"
    for index in $(seq 1 20); do
        key="g1-${prefix}-$(printf '%04d' "${index}")"
        [ "${key_mode}" = 'distinct' ] || key="g1-${prefix}-same-0001"
        (
            touch "${ready_dir}/${index}"
            while [ ! -f "${gate}" ]; do sleep 0.01; done
            status="$(authenticated_request "${prefix}-${index}" "${key}" "${body}")"
            printf '%s' "${status}" > "${TMP_DIR}/${prefix}-${index}.status"
        ) &
    done
    while [ "$(find "${ready_dir}" -type f | wc -l | tr -d '[:space:]')" -lt 20 ]; do
        sleep 0.01
    done
    touch "${gate}"
    wait

    for index in $(seq 1 20); do
        status="$(cat "${TMP_DIR}/${prefix}-${index}.status")"
        [ "${status}" = '200' ] || fail "${prefix} concurrent request ${index} returned HTTP ${status}"
        assert_json "${TMP_DIR}/${prefix}-${index}.json" status 200
        assert_json "${TMP_DIR}/${prefix}-${index}.json" data.tenant.slug local-primary
        assert_json "${TMP_DIR}/${prefix}-${index}.json" data.member.status active
        assert_json "${TMP_DIR}/${prefix}-${index}.json" data.attribution.referrer_bound true
    done
}

for command in docker curl node openssl; do
    command -v "${command}" >/dev/null 2>&1 || fail "Required command not found: ${command}"
done

cd "${PROJECT_ROOT}"
bash -n scripts/check-g1-member-bootstrap.sh
./scripts/prepare-local-crmeb-runtime.sh install >/dev/null
./scripts/manage-local-database.sh setup >/dev/null

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php setup > "${TMP_DIR}/setup.json"
TOKEN="$(json_value "${TMP_DIR}/setup.json" token)"
NON_API_TOKEN="$(json_value "${TMP_DIR}/setup.json" non_api_token)"
LEGACY_TOKEN="$(json_value "${TMP_DIR}/setup.json" legacy_token)"
UID_VALUE="$(json_value "${TMP_DIR}/setup.json" uid)"
LEGACY_UID="$(json_value "${TMP_DIR}/setup.json" legacy_uid)"
REFERRER_UID="$(json_value "${TMP_DIR}/setup.json" referrer_uid)"
REFERRER_CODE="$(json_value "${TMP_DIR}/setup.json" referrer_code)"
OTHER_REFERRER_CODE="$(json_value "${TMP_DIR}/setup.json" other_referrer_code)"
PRIMARY_TENANT_ID="$(json_value "${TMP_DIR}/setup.json" primary_tenant_id)"
PRIMARY_CHANNEL_ID="$(json_value "${TMP_DIR}/setup.json" primary_channel_id)"
SECONDARY_TENANT_ID="$(json_value "${TMP_DIR}/setup.json" secondary_tenant_id)"

status="$(request missing-token \
    -X POST -H 'Content-Type: application/json' -H 'Idempotency-Key: g1-missing-token-0001' \
    --data '{}' "${BASE_URL}/chamber/v1/me/bootstrap")"
[ "${status}" = '401' ] || fail "missing token returned HTTP ${status}"
assert_json "${TMP_DIR}/missing-token.json" data.reason authentication_required

status="$(request wrong-audience \
    -X POST -H "Authorization: Bearer ${NON_API_TOKEN}" -H 'Content-Type: application/json' \
    -H 'Idempotency-Key: g1-wrong-audience-0001' --data '{}' \
    "${BASE_URL}/chamber/v1/me/bootstrap")"
[ "${status}" = '401' ] || fail "non-api token returned HTTP ${status}"
assert_json "${TMP_DIR}/wrong-audience.json" data.reason authentication_required

status="$(request missing-key \
    -X POST -H "Authorization: Bearer ${TOKEN}" -H 'Content-Type: application/json' \
    --data '{}' "${BASE_URL}/chamber/v1/me/bootstrap")"
[ "${status}" = '400' ] || fail "missing Idempotency-Key returned HTTP ${status}"
assert_json "${TMP_DIR}/missing-key.json" data.reason idempotency_key_required

stale_body='{"consents":[{"document_code":"privacy_policy","document_version":"stale","accepted":true}]}'
status="$(authenticated_request stale-consent g1-stale-consent-0001 "${stale_body}")"
[ "${status}" = '409' ] || fail "stale consent returned HTTP ${status}"
assert_json "${TMP_DIR}/stale-consent.json" data.reason consent_document_stale

status="$(authenticated_request invalid-consents-object g1-invalid-consents-0001 '{"consents":{}}')"
[ "${status}" = '400' ] || fail "object-shaped consents returned HTTP ${status}"
assert_json "${TMP_DIR}/invalid-consents-object.json" data.reason request_validation_failed

status="$(request legacy-attribution \
    -X POST -H "Authorization: Bearer ${LEGACY_TOKEN}" \
    -H 'Idempotency-Key: g1-legacy-attribution-0001' -H 'Content-Type: application/json' \
    --data '{}' "${BASE_URL}/chamber/v1/me/bootstrap")"
[ "${status}" = '200' ] || fail "legacy attribution bootstrap returned HTTP ${status}"
docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php inspect "${LEGACY_UID}" \
    > "${TMP_DIR}/legacy-attribution.json"
assert_json "${TMP_DIR}/legacy-attribution.json" members.0.first_channel_id "${PRIMARY_CHANNEL_ID}"
assert_json "${TMP_DIR}/legacy-attribution.json" members.0.referrer_uid "${REFERRER_UID}"

bootstrap_body="{\"invite_code\":\"${REFERRER_CODE}\",\"consents\":[{\"document_code\":\"privacy_policy\",\"document_version\":\"local-2026-07-23\",\"accepted\":true}]}"
run_concurrent_group same-key same "${bootstrap_body}"

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/after-same-key.json"
assert_json "${TMP_DIR}/after-same-key.json" profile_count 1
assert_json "${TMP_DIR}/after-same-key.json" idempotency.0.records 1

run_concurrent_group distinct-key distinct "${bootstrap_body}"

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/after-concurrency.json"
assert_json "${TMP_DIR}/after-concurrency.json" profile_count 1
assert_json "${TMP_DIR}/after-concurrency.json" members.0.tenant_slug local-primary
assert_json "${TMP_DIR}/after-concurrency.json" members.0.first_channel_id "${PRIMARY_CHANNEL_ID}"
assert_json "${TMP_DIR}/after-concurrency.json" members.0.referrer_uid "${REFERRER_UID}"
assert_json "${TMP_DIR}/after-concurrency.json" consents.0.document_version local-2026-07-23
assert_json "${TMP_DIR}/after-concurrency.json" idempotency.0.records 21

status="$(authenticated_request replay g1-same-key-same-0001 "${bootstrap_body}")"
[ "${status}" = '200' ] || fail "idempotent replay returned HTTP ${status}"

status="$(authenticated_request conflict g1-same-key-same-0001 '{}')"
[ "${status}" = '409' ] || fail "idempotency conflict returned HTTP ${status}"
assert_json "${TMP_DIR}/conflict.json" data.reason idempotency_conflict

other_body="{\"invite_code\":\"${OTHER_REFERRER_CODE}\"}"
status="$(authenticated_request attribution-locked g1-attribution-locked-0001 "${other_body}")"
[ "${status}" = '409' ] || fail "locked attribution change returned HTTP ${status}"
assert_json "${TMP_DIR}/attribution-locked.json" data.reason member_attribution_locked

timestamp="$(date +%s)"
nonce="g1-bootstrap-secondary-${timestamp}-$$"
canonical_payload="$(printf 'POST\n%s\n/chamber/v1/me/bootstrap\nlocal-secondary\ndefault\n%s\n%s' \
    "${SIGNED_HOST}" "${timestamp}" "${nonce}")"
signature="$(printf '%s' "${canonical_payload}" | openssl dgst -sha256 -hmac "${SIGNING_SECRET}" -r | awk '{print $1}')"
status="$(request secondary \
    -X POST \
    -H "Host: ${SIGNED_HOST}" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H 'Idempotency-Key: g1-same-key-same-0001' \
    -H 'Content-Type: application/json' \
    -H 'X-Chamber-Tenant: local-secondary' \
    -H 'X-Chamber-Channel: default' \
    -H "X-Chamber-Timestamp: ${timestamp}" \
    -H "X-Chamber-Nonce: ${nonce}" \
    -H "X-Chamber-Signature: ${signature}" \
    --data '{}' "${BASE_URL}/chamber/v1/me/bootstrap")"
[ "${status}" = '200' ] || fail "cross-tenant bootstrap returned HTTP ${status}"
assert_json "${TMP_DIR}/secondary.json" data.tenant.slug local-secondary

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php withdraw "${UID_VALUE}" >/dev/null

status="$(authenticated_request withdrawn-new g1-withdrawn-new-0001 "${bootstrap_body}")"
[ "${status}" = '403' ] || fail "withdrawn member with a new key returned HTTP ${status}"
assert_json "${TMP_DIR}/withdrawn-new.json" data.reason member_disabled

status="$(authenticated_request withdrawn-replay g1-same-key-same-0001 "${bootstrap_body}")"
[ "${status}" = '403' ] || fail "withdrawn member replay returned HTTP ${status}"
assert_json "${TMP_DIR}/withdrawn-replay.json" data.reason member_disabled

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php inspect "${UID_VALUE}" \
    > "${TMP_DIR}/final.json"
assert_json "${TMP_DIR}/final.json" profile_count 2
assert_json "${TMP_DIR}/final.json" members.0.tenant_slug local-primary
assert_json "${TMP_DIR}/final.json" members.0.status 2
assert_json "${TMP_DIR}/final.json" members.1.tenant_slug local-secondary
assert_json "${TMP_DIR}/final.json" members.1.status 1
assert_json "${TMP_DIR}/final.json" consents.0.document_version local-2026-07-23

node - "${TMP_DIR}/final.json" "${PRIMARY_TENANT_ID}" "${SECONDARY_TENANT_ID}" <<'NODE'
const fs = require('fs');
const [file, primaryTenant, secondaryTenant] = process.argv.slice(2);
const data = JSON.parse(fs.readFileSync(file, 'utf8'));
if (data.members.length !== 2 || data.consents.length !== 1) process.exit(1);
const groups = new Map(data.idempotency.map(group => [`${group.tenant_id}:${group.status}`, group.records]));
if (groups.get(`${primaryTenant}:succeeded`) !== 21) process.exit(1);
if (groups.get(`${secondaryTenant}:succeeded`) !== 1) process.exit(1);
NODE

printf 'G1 member bootstrap HTTP gate OK\n'
printf 'Auth: real CRMEB api token; non-api audience rejected with HTTP 401\n'
printf 'Concurrency: same-key and distinct-key 20-way races -> one member/profile/consent\n'
printf 'Isolation: tenant-scoped identity/idempotency; withdrawn members never revive or replay 200\n'
