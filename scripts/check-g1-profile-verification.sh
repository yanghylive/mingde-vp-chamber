#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
BASE_URL="${CRMEB_BASE_URL:-http://127.0.0.1:8011}"
ADMIN_ACCOUNT="${CRMEB_LOCAL_ADMIN_ACCOUNT:-admin}"
ADMIN_PASSWORD="${CRMEB_LOCAL_ADMIN_PASSWORD:-Admin@123456}"
TMP_DIR="$(mktemp -d)"
TOKEN=''
ADMIN_TOKEN=''
UID_VALUE=''
PRIMARY_TENANT_ID=''
ADMIN_ID=''
IDEMPOTENCY_SPECS=()

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

cleanup() {
    local spec tenant_id operation principal_type principal_id caller_key
    for spec in "${IDEMPOTENCY_SPECS[@]:-}"; do
        [ -n "${spec}" ] || continue
        IFS='|' read -r tenant_id operation principal_type principal_id caller_key <<<"${spec}"
        docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
            php /var/www/app/chamber/tests/member_bootstrap_fixture.php cleanup-idempotency \
            "${tenant_id}" "${operation}" "${principal_type}" "${principal_id}" "${caller_key}" \
            >/dev/null 2>&1 || true
    done
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/member_bootstrap_fixture.php cleanup \
        "${TOKEN}" "${ADMIN_TOKEN}" >/dev/null 2>&1 || true
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

json_value() {
    local file="$1" path="$2"
    node - "${file}" "${path}" <<'NODE'
const fs = require('fs');
const [file, path] = process.argv.slice(2);
const data = JSON.parse(fs.readFileSync(file, 'utf8'));
const value = path.split('.').reduce((current, key) => current == null ? undefined : current[key], data);
if (value === undefined) process.exit(2);
process.stdout.write(String(value));
NODE
}

assert_json() {
    local file="$1" path="$2" expected="$3" actual
    actual="$(json_value "${file}" "${path}")" || fail "Missing JSON path ${path} in ${file}"
    [ "${actual}" = "${expected}" ] || fail "Expected ${path}=${expected}, got ${actual}"
}

assert_json_present() {
    json_value "$1" "$2" >/dev/null || fail "Missing JSON path $2 in $1"
}

assert_header() {
    local file="$1" name="$2" value="$3"
    grep -Eiq "^${name}: ${value}([[:space:]]*)$" "${file}" \
        || fail "Expected response header ${name}: ${value}"
}

request() {
    local name="$1"
    shift
    curl -sS --max-time 20 -D "${TMP_DIR}/${name}.headers" -o "${TMP_DIR}/${name}.json" \
        -w '%{http_code}' "$@"
}

remember_idempotency() {
    IDEMPOTENCY_SPECS+=("$1|$2|$3|$4|$5")
}

asset_access_snapshot() {
    local asset_id="$1" output_file="$2"
    docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/member_bootstrap_fixture.php asset-access "${asset_id}" \
        > "${output_file}"
}

assert_asset_access_unchanged() {
    local before="$1" after="$2" field expected
    for field in application_id last_access_time update_time read_audit_count; do
        expected="$(json_value "${before}" "${field}")"
        assert_json "${after}" "${field}" "${expected}"
    done
}

for command in docker curl node cmp base64 grep dd; do
    command -v "${command}" >/dev/null 2>&1 || fail "Required command not found: ${command}"
done

cd "${PROJECT_ROOT}"
bash -n scripts/check-g1-profile-verification.sh
./scripts/prepare-local-crmeb-runtime.sh install >/dev/null
./scripts/manage-local-database.sh setup >/dev/null

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php setup > "${TMP_DIR}/setup.json"
TOKEN="$(json_value "${TMP_DIR}/setup.json" token)"
UID_VALUE="$(json_value "${TMP_DIR}/setup.json" uid)"
PRIMARY_TENANT_ID="$(json_value "${TMP_DIR}/setup.json" primary_tenant_id)"

status="$(request profile-missing-token "${BASE_URL}/chamber/v1/me/profile")"
[ "${status}" = '401' ] || fail "Profile without a token returned HTTP ${status}"
assert_json "${TMP_DIR}/profile-missing-token.json" data.reason authentication_required

bootstrap_key="g1c-bootstrap-$(date +%s)-$$"
remember_idempotency "${PRIMARY_TENANT_ID}" bootstrapChamberMember crmeb_user "${UID_VALUE}" "${bootstrap_key}"
correlation_id="g1c-profile-verification-$(date +%s)-$$"
status="$(request bootstrap \
    -X POST \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${bootstrap_key}" \
    -H "X-Correlation-Id: ${correlation_id}" \
    -H 'Content-Type: application/json' \
    --data '{}' \
    "${BASE_URL}/chamber/v1/me/bootstrap")"
[ "${status}" = '200' ] || fail "Member bootstrap returned HTTP ${status}"
assert_json "${TMP_DIR}/bootstrap.json" data.member.status active
assert_json "${TMP_DIR}/bootstrap.json" data.tenant.slug local-primary
assert_json_present "${TMP_DIR}/bootstrap.json" request_id
assert_header "${TMP_DIR}/bootstrap.headers" X-Correlation-Id "${correlation_id}"

status="$(request profile-get \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/profile")"
[ "${status}" = '200' ] || fail "Profile query returned HTTP ${status}"
assert_json "${TMP_DIR}/profile-get.json" data.profile_complete false

profile_body='{"real_name":"HTTP Gate Member","class_name":"2024 CEO Class","graduation_year":2024,"industry":"AI Services","company_name":"Mingde Test","job_title":"Founder","resources":["AI consulting"],"needs":["Partner matching"],"privacy":{"real_name":"members","company_name":"members"}}'
profile_key="g1c-profile-$(date +%s)-$$"
remember_idempotency "${PRIMARY_TENANT_ID}" updateChamberMemberProfile crmeb_user "${UID_VALUE}" "${profile_key}"
for name in profile-update profile-replay; do
    status="$(request "${name}" \
        -X PATCH \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Idempotency-Key: ${profile_key}" \
        -H 'Content-Type: application/json' \
        --data "${profile_body}" \
        "${BASE_URL}/chamber/v1/me/profile")"
    [ "${status}" = '200' ] || fail "${name} returned HTTP ${status}"
    assert_json "${TMP_DIR}/${name}.json" data.real_name 'HTTP Gate Member'
    assert_json "${TMP_DIR}/${name}.json" data.profile_complete true
done

status="$(request profile-conflict \
    -X PATCH \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${profile_key}" \
    -H 'Content-Type: application/json' \
    --data '{"real_name":"Different Member"}' \
    "${BASE_URL}/chamber/v1/me/profile")"
[ "${status}" = '409' ] || fail "Profile idempotency conflict returned HTTP ${status}"
assert_json "${TMP_DIR}/profile-conflict.json" data.reason idempotency_conflict

printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' \
    | base64 --decode > "${TMP_DIR}/proof.png"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8zwAAAgEBAScY42YAAAAASUVORK5CYII=' \
    | base64 --decode > "${TMP_DIR}/proof-changed.png"
dd if=/dev/zero of="${TMP_DIR}/oversized.bin" bs=1048576 count=12 2>/dev/null

status="$(request asset-request-too-large \
    -X POST \
    -H "Authorization: Bearer ${TOKEN}" \
    -H 'Idempotency-Key: g1c-oversized-request' \
    -F 'purpose=graduate_verification_proof' \
    -F "file=@${TMP_DIR}/oversized.bin;type=application/octet-stream" \
    "${BASE_URL}/chamber/v1/me/assets")"
[ "${status}" = '413' ] || fail "Oversized Chamber asset request returned HTTP ${status}"
assert_json "${TMP_DIR}/asset-request-too-large.json" status 413
assert_json "${TMP_DIR}/asset-request-too-large.json" data.reason asset_upload_invalid
assert_json "${TMP_DIR}/asset-request-too-large.json" data.field_errors.0.code too_large
oversized_request_id="$(json_value "${TMP_DIR}/asset-request-too-large.json" request_id)"
assert_header "${TMP_DIR}/asset-request-too-large.headers" X-Request-Id "${oversized_request_id}"
assert_header "${TMP_DIR}/asset-request-too-large.headers" X-Correlation-Id "${oversized_request_id}"

asset_key="g1c-asset-$(date +%s)-$$"
remember_idempotency "${PRIMARY_TENANT_ID}" uploadMemberAsset crmeb_user "${UID_VALUE}" "${asset_key}"
for name in asset-upload asset-replay; do
    status="$(request "${name}" \
        -X POST \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Idempotency-Key: ${asset_key}" \
        -F 'purpose=graduate_verification_proof' \
        -F "file=@${TMP_DIR}/proof.png;type=image/png" \
        "${BASE_URL}/chamber/v1/me/assets")"
    [ "${status}" = '201' ] || fail "${name} returned HTTP ${status}"
    assert_json "${TMP_DIR}/${name}.json" data.mime_type image/png
    assert_json "${TMP_DIR}/${name}.json" data.size 68
done
asset_id="$(json_value "${TMP_DIR}/asset-upload.json" data.id)"
object_key="$(json_value "${TMP_DIR}/asset-upload.json" data.object_key)"
assert_json "${TMP_DIR}/asset-replay.json" data.id "${asset_id}"
assert_json "${TMP_DIR}/asset-replay.json" data.object_key "${object_key}"

asset_access_snapshot "${asset_id}" "${TMP_DIR}/owner-invalid-query-before.json"
sleep 1
status="$(request asset-content-unknown-query \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/assets/${asset_id}/content?unexpected=1")"
[ "${status}" = '422' ] || fail "Owner asset unknown query returned HTTP ${status}"
assert_json "${TMP_DIR}/asset-content-unknown-query.json" data.reason request_validation_failed
assert_json "${TMP_DIR}/asset-content-unknown-query.json" data.field_errors.0.field unexpected
assert_json "${TMP_DIR}/asset-content-unknown-query.json" data.field_errors.0.code unknown_field
status="$(request asset-content-invalid-download \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/assets/${asset_id}/content?download=2")"
[ "${status}" = '422' ] || fail "Owner asset invalid download returned HTTP ${status}"
assert_json "${TMP_DIR}/asset-content-invalid-download.json" data.reason request_validation_failed
assert_json "${TMP_DIR}/asset-content-invalid-download.json" data.field_errors.0.field download
assert_json "${TMP_DIR}/asset-content-invalid-download.json" data.field_errors.0.code invalid_value
status="$(request asset-content-duplicate-download \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/assets/${asset_id}/content?download=0&download=1")"
[ "${status}" = '422' ] || fail "Owner asset duplicate download returned HTTP ${status}"
assert_json "${TMP_DIR}/asset-content-duplicate-download.json" data.reason request_validation_failed
assert_json "${TMP_DIR}/asset-content-duplicate-download.json" data.field_errors.0.field download
assert_json "${TMP_DIR}/asset-content-duplicate-download.json" data.field_errors.0.code duplicate_field
asset_access_snapshot "${asset_id}" "${TMP_DIR}/owner-invalid-query-after.json"
assert_asset_access_unchanged \
    "${TMP_DIR}/owner-invalid-query-before.json" \
    "${TMP_DIR}/owner-invalid-query-after.json"

status="$(request asset-conflict \
    -X POST \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${asset_key}" \
    -F 'purpose=graduate_verification_proof' \
    -F "file=@${TMP_DIR}/proof-changed.png;type=image/png" \
    "${BASE_URL}/chamber/v1/me/assets")"
[ "${status}" = '409' ] || fail "Asset idempotency conflict returned HTTP ${status}"
assert_json "${TMP_DIR}/asset-conflict.json" data.reason idempotency_conflict

status="$(request asset-content-missing-token \
    "${BASE_URL}/chamber/v1/me/assets/${asset_id}/content")"
[ "${status}" = '401' ] || fail "Private asset without a token returned HTTP ${status}"

status="$(curl -sS --max-time 20 \
    -D "${TMP_DIR}/asset-content.headers" \
    -o "${TMP_DIR}/asset-content.bin" \
    -w '%{http_code}' \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/assets/${asset_id}/content?download=0")"
[ "${status}" = '200' ] || fail "Private member asset returned HTTP ${status}"
cmp -s "${TMP_DIR}/proof.png" "${TMP_DIR}/asset-content.bin" \
    || fail "Private member asset bytes changed"
assert_header "${TMP_DIR}/asset-content.headers" Cache-Control 'private, no-store, max-age=0'
assert_header "${TMP_DIR}/asset-content.headers" X-Content-Type-Options nosniff

verification_body="$(node -e \
    'process.stdout.write(JSON.stringify({class_name:"2024 CEO Class",graduation_year:2024,proof_object_keys:[process.argv[1]]}))' \
    "${object_key}")"
submit_key="g1c-submit-$(date +%s)-$$"
remember_idempotency "${PRIMARY_TENANT_ID}" submitGraduateVerification crmeb_user "${UID_VALUE}" "${submit_key}"
for name in verification-submit verification-replay; do
    status="$(request "${name}" \
        -X POST \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Idempotency-Key: ${submit_key}" \
        -H 'Content-Type: application/json' \
        --data "${verification_body}" \
        "${BASE_URL}/chamber/v1/me/graduate-verifications")"
    [ "${status}" = '201' ] || fail "${name} returned HTTP ${status}"
    assert_json "${TMP_DIR}/${name}.json" data.status pending
    assert_json "${TMP_DIR}/${name}.json" data.proof_assets.0.id "${asset_id}"
done
application_id="$(json_value "${TMP_DIR}/verification-submit.json" data.id)"
assert_json "${TMP_DIR}/verification-replay.json" data.id "${application_id}"

status="$(request verification-query \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/graduate-verifications")"
[ "${status}" = '200' ] || fail "Verification query returned HTTP ${status}"
assert_json "${TMP_DIR}/verification-query.json" data.current_status pending
assert_json "${TMP_DIR}/verification-query.json" data.can_submit false

status="$(request admin-missing-token \
    "${BASE_URL}/chamber/admin/v1/graduate-verifications")"
[ "${status}" = '401' ] || fail "Admin list without a token returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-missing-token.json" data.reason authentication_required

status="$(request admin-login \
    -X POST \
    -H 'Content-Type: application/json' \
    --data "{\"account\":\"${ADMIN_ACCOUNT}\",\"pwd\":\"${ADMIN_PASSWORD}\"}" \
    "${BASE_URL}/adminapi/login")"
[ "${status}" = '200' ] || fail "CRMEB administrator login returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-login.json" status 200
ADMIN_TOKEN="$(json_value "${TMP_DIR}/admin-login.json" data.token)"
ADMIN_ID="$(json_value "${TMP_DIR}/admin-login.json" data.user_info.id)"

asset_access_snapshot "${asset_id}" "${TMP_DIR}/admin-invalid-query-before.json"
sleep 1
status="$(request admin-content-missing-application \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/member-assets/${asset_id}/content")"
[ "${status}" = '422' ] || fail "Admin asset query without application_id returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-content-missing-application.json" data.reason request_validation_failed
assert_json "${TMP_DIR}/admin-content-missing-application.json" data.field_errors.0.field application_id
assert_json "${TMP_DIR}/admin-content-missing-application.json" data.field_errors.0.code required
status="$(request admin-content-unknown-query \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/member-assets/${asset_id}/content?application_id=${application_id}&unexpected=1")"
[ "${status}" = '422' ] || fail "Admin asset unknown query returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-content-unknown-query.json" data.reason request_validation_failed
assert_json "${TMP_DIR}/admin-content-unknown-query.json" data.field_errors.0.field unexpected
assert_json "${TMP_DIR}/admin-content-unknown-query.json" data.field_errors.0.code unknown_field
status="$(request admin-content-invalid-download \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/member-assets/${asset_id}/content?application_id=${application_id}&download=2")"
[ "${status}" = '422' ] || fail "Admin asset invalid download returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-content-invalid-download.json" data.reason request_validation_failed
assert_json "${TMP_DIR}/admin-content-invalid-download.json" data.field_errors.0.field download
assert_json "${TMP_DIR}/admin-content-invalid-download.json" data.field_errors.0.code invalid_value
status="$(request admin-content-duplicate-application \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/member-assets/${asset_id}/content?application_id=${application_id}&application_id=${application_id}")"
[ "${status}" = '422' ] || fail "Admin asset duplicate application_id returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-content-duplicate-application.json" data.reason request_validation_failed
assert_json "${TMP_DIR}/admin-content-duplicate-application.json" data.field_errors.0.field application_id
assert_json "${TMP_DIR}/admin-content-duplicate-application.json" data.field_errors.0.code duplicate_field
asset_access_snapshot "${asset_id}" "${TMP_DIR}/admin-invalid-query-after.json"
assert_asset_access_unchanged \
    "${TMP_DIR}/admin-invalid-query-before.json" \
    "${TMP_DIR}/admin-invalid-query-after.json"

unsubmitted_asset_key="g1c-asset-unsubmitted-$(date +%s)-$$"
remember_idempotency "${PRIMARY_TENANT_ID}" uploadMemberAsset crmeb_user "${UID_VALUE}" "${unsubmitted_asset_key}"
status="$(request asset-unsubmitted \
    -X POST \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${unsubmitted_asset_key}" \
    -F 'purpose=graduate_verification_proof' \
    -F "file=@${TMP_DIR}/proof.png;type=image/png" \
    "${BASE_URL}/chamber/v1/me/assets")"
[ "${status}" = '201' ] || fail "Unsubmitted asset upload returned HTTP ${status}"
unsubmitted_asset_id="$(json_value "${TMP_DIR}/asset-unsubmitted.json" data.id)"
status="$(request admin-unsubmitted-content \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/member-assets/${unsubmitted_asset_id}/content?application_id=${application_id}")"
[ "${status}" = '404' ] || fail "Administrator unsubmitted asset read returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-unsubmitted-content.json" data.reason asset_not_found

status="$(request admin-list \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/graduate-verifications?status=pending&page=1&per_page=20")"
[ "${status}" = '200' ] || fail "Admin verification list returned HTTP ${status}"
node - "${TMP_DIR}/admin-list.json" "${application_id}" <<'NODE'
const fs = require('fs');
const [file, expectedId] = process.argv.slice(2);
const response = JSON.parse(fs.readFileSync(file, 'utf8'));
if (!response.data || !Array.isArray(response.data.items)
    || !response.data.items.some(item => String(item.id) === expectedId)) process.exit(1);
NODE

status="$(request admin-detail \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/graduate-verifications/${application_id}")"
[ "${status}" = '200' ] || fail "Admin verification detail returned HTTP ${status}"
assert_json "${TMP_DIR}/admin-detail.json" data.id "${application_id}"
assert_json "${TMP_DIR}/admin-detail.json" data.member_name 'HTTP Gate Member'

status="$(curl -sS --max-time 20 \
    -D "${TMP_DIR}/admin-content.headers" \
    -o "${TMP_DIR}/admin-content.bin" \
    -w '%{http_code}' \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    "${BASE_URL}/chamber/admin/v1/member-assets/${asset_id}/content?application_id=${application_id}&download=0")"
[ "${status}" = '200' ] || fail "Administrator private asset returned HTTP ${status}"
cmp -s "${TMP_DIR}/proof.png" "${TMP_DIR}/admin-content.bin" \
    || fail "Administrator private asset bytes changed"

review_key="g1c-review-$(date +%s)-$$"
remember_idempotency "${PRIMARY_TENANT_ID}" reviewGraduateVerification crmeb_admin "${ADMIN_ID}" "${review_key}"
for name in verification-approve verification-approve-replay; do
    status="$(request "${name}" \
        -X POST \
        -H "Authorization: Bearer ${ADMIN_TOKEN}" \
        -H "Idempotency-Key: ${review_key}" \
        -H 'Content-Type: application/json' \
        --data '{"action":"approve","note":"HTTP gate verified"}' \
        "${BASE_URL}/chamber/admin/v1/graduate-verifications/${application_id}/reviews")"
    [ "${status}" = '200' ] || fail "${name} returned HTTP ${status}"
    assert_json "${TMP_DIR}/${name}.json" data.status approved
    assert_json "${TMP_DIR}/${name}.json" data.reviewer_admin_id "${ADMIN_ID}"
done

second_review_key="g1c-review-again-$(date +%s)-$$"
remember_idempotency "${PRIMARY_TENANT_ID}" reviewGraduateVerification crmeb_admin "${ADMIN_ID}" "${second_review_key}"
status="$(request verification-invalid-transition \
    -X POST \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "Idempotency-Key: ${second_review_key}" \
    -H 'Content-Type: application/json' \
    --data '{"action":"approve"}' \
    "${BASE_URL}/chamber/admin/v1/graduate-verifications/${application_id}/reviews")"
[ "${status}" = '409' ] || fail "Repeated review with a new key returned HTTP ${status}"
assert_json "${TMP_DIR}/verification-invalid-transition.json" data.reason verification_transition_invalid

status="$(request verification-approved \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/graduate-verifications")"
[ "${status}" = '200' ] || fail "Approved verification query returned HTTP ${status}"
assert_json "${TMP_DIR}/verification-approved.json" data.current_status approved
assert_json "${TMP_DIR}/verification-approved.json" data.latest_application.review_note 'HTTP gate verified'
assert_json "${TMP_DIR}/verification-approved.json" data.can_submit false

docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_bootstrap_fixture.php withdraw "${UID_VALUE}" \
    > "${TMP_DIR}/withdraw.json"
assert_json "${TMP_DIR}/withdraw.json" withdrawn true

status="$(request profile-replay-disabled \
    -X PATCH \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Idempotency-Key: ${profile_key}" \
    -H 'Content-Type: application/json' \
    --data "${profile_body}" \
    "${BASE_URL}/chamber/v1/me/profile")"
[ "${status}" = '403' ] || fail "Disabled member profile replay returned HTTP ${status}"
assert_json "${TMP_DIR}/profile-replay-disabled.json" data.reason member_disabled

status="$(request review-replay-disabled \
    -X POST \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "Idempotency-Key: ${review_key}" \
    -H 'Content-Type: application/json' \
    --data '{"action":"approve","note":"HTTP gate verified"}' \
    "${BASE_URL}/chamber/admin/v1/graduate-verifications/${application_id}/reviews")"
[ "${status}" = '403' ] || fail "Disabled member review replay returned HTTP ${status}"
assert_json "${TMP_DIR}/review-replay-disabled.json" data.reason member_disabled

status="$(request asset-content-disabled \
    -H "Authorization: Bearer ${TOKEN}" \
    "${BASE_URL}/chamber/v1/me/assets/${asset_id}/content")"
[ "${status}" = '403' ] || fail "Disabled member private asset read returned HTTP ${status}"
assert_json "${TMP_DIR}/asset-content-disabled.json" data.reason member_disabled

printf 'G1 profile and graduate verification HTTP gate OK\n'
printf 'Profile: authenticated query/update, encrypted replay, conflict and disabled-member protection\n'
printf 'Assets: private upload/replay, submission-bound admin reads, bytes/no-store protection\n'
printf 'Verification: submit/query, admin list/detail/read/review, transition and disabled-member protection\n'
