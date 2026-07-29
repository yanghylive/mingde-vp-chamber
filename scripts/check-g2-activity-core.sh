#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
MODE="${1:-local}"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

manifest_g2_value() {
    local key="$1" fallback="$2" value
    value="$(ruby -rjson -e '
      manifest = JSON.parse(File.read(ARGV[0]))
      baseline = manifest.fetch("g2_activity_baseline", {})
      print baseline.fetch(ARGV[1], ARGV[2])
    ' PROJECT_MANIFEST.json "${key}" "${fallback}")"
    printf '%s\n' "${value}"
}

case "${MODE}" in
    local|ci) ;;
    -h|--help|help)
        printf '%s\n' 'Usage: ./scripts/check-g2-activity-core.sh [local|ci]'
        printf '%s\n' '  local  Prepare Docker runtime/database and run the complete G2 gate (default).'
        printf '%s\n' '  ci     Run PHP lint, domain tests, migration inventory, and OpenAPI checks without Docker.'
        exit 0
        ;;
    *) fail "Unknown mode: ${MODE}" ;;
esac

for command in awk grep ruby sed; do
    command -v "${command}" >/dev/null 2>&1 || fail "Required command not found: ${command}"
done
if [ "${MODE}" = 'local' ]; then
    command -v docker >/dev/null 2>&1 || fail 'Required command not found: docker'
else
    command -v php >/dev/null 2>&1 || fail 'Required command not found: php'
fi

cd "${PROJECT_ROOT}"

activity_php_files=(
    backend/custom/app/chamber/activity/EventCheckinRequest.php
    backend/custom/app/chamber/activity/EventCheckinToken.php
    backend/custom/app/chamber/activity/EventEligibility.php
    backend/custom/app/chamber/activity/EventListQuery.php
    backend/custom/app/chamber/activity/EventRegistrationRequest.php
    backend/custom/app/chamber/activity/EventRegistrationListQuery.php
    backend/custom/app/chamber/controller/EventAdminController.php
    backend/custom/app/chamber/controller/EventCheckinController.php
    backend/custom/app/chamber/controller/EventController.php
    backend/custom/app/chamber/controller/EventRegistrationController.php
    backend/custom/app/chamber/services/EventAdminService.php
    backend/custom/app/chamber/services/EventCheckinService.php
    backend/custom/app/chamber/services/EventIdempotency.php
    backend/custom/app/chamber/services/EventRegistrationService.php
    backend/custom/app/chamber/services/EventRegistrationCommerceProjection.php
    backend/custom/app/chamber/services/EventReservationRepairService.php
    backend/custom/app/chamber/services/CrmebEventOrderGateway.php
    backend/custom/app/chamber/jobs/EventReservationRepairJob.php
    backend/custom/app/chamber/services/EventRewardService.php
    backend/custom/app/chamber/services/EventService.php
    backend/custom/app/chamber/tests/event_run.php
    backend/custom/app/chamber/tests/event_db_run.php
    backend/custom/app/chamber/tests/event_registration_db_run.php
    backend/custom/app/chamber/tests/event_registration_concurrency_run.php
)

activity_migrations=(
    202607260001_create_activity_runtime
    202607280001_add_event_checkin_rewards
    202607280002_create_event_payment_runtime
    202607280003_register_event_reservation_timer
)

for file in "${activity_php_files[@]}"; do
    [ -s "${file}" ] || fail "G2 PHP artifact is missing or empty: ${file}"
done
for migration in "${activity_migrations[@]}"; do
    for suffix in up verify down; do
        file="backend/custom/database/migrations/${migration}.${suffix}.sql"
        [ -s "${file}" ] || fail "G2 migration artifact is missing or empty: ${file}"
    done
done

bash -n scripts/check-g2-activity-core.sh

linted=0
if [ "${MODE}" = 'local' ]; then
    ./scripts/prepare-local-crmeb-runtime.sh install
    database_output="$(./scripts/manage-local-database.sh setup)"
    printf '%s\n' "${database_output}"
    grep -Fq 'PASS 202607260001_create_activity_runtime (19 structural checks)' <<<"${database_output}" \
        || fail 'G2 activity runtime migration structural checks changed'
    grep -Fq 'PASS 202607280001_add_event_checkin_rewards (1 structural checks)' <<<"${database_output}" \
        || fail 'G2 activity reward migration structural checks changed'
    grep -Fq 'PASS 202607280002_create_event_payment_runtime (1 structural checks)' <<<"${database_output}" \
        || fail 'G2 event payment runtime migration structural checks changed'
    grep -Fq 'PASS 202607280003_register_event_reservation_timer (1 structural checks)' <<<"${database_output}" \
        || fail 'G2 event reservation timer migration structural checks changed'

    for file in "${activity_php_files[@]}"; do
        relative="${file#backend/custom/app/chamber/}"
        docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
            php -l "/var/www/app/chamber/${relative}" >/dev/null
        linted=$((linted + 1))
    done

    domain_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/event_run.php)"
    database_test_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/event_db_run.php)"
    registration_database_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/event_registration_db_run.php)"
    registration_concurrency_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
        php /var/www/app/chamber/tests/event_registration_concurrency_run.php)"
else
    for file in "${activity_php_files[@]}"; do
        php -l "${file}" >/dev/null
        linted=$((linted + 1))
    done
    domain_output="$(php backend/custom/app/chamber/tests/event_run.php)"
    database_test_output='SKIP: activity database integration requires the local Docker gate'
    registration_database_output='SKIP: registration database integration requires the local Docker gate'
    registration_concurrency_output='SKIP: registration concurrency requires the local Docker gate'
fi

printf '%s\n' "${domain_output}"
domain_cases="$(sed -nE 's/^Activity domain tests passed \(([0-9]+) cases\)\.$/\1/p' <<<"${domain_output}")"
[[ "${domain_cases:-}" =~ ^[0-9]+$ ]] || fail 'G2 activity domain test result is unavailable'
domain_minimum="$(manifest_g2_value activity_domain_tests_minimum 10)"
[ "${domain_cases}" -ge "${domain_minimum}" ] \
    || fail "G2 activity domain tests were removed: ${domain_cases} < ${domain_minimum}"

if [ "${MODE}" = 'local' ]; then
    printf '%s\n' "${database_test_output}"
    database_assertions="$(sed -nE \
        's/^Activity database integration passed \(([0-9]+) assertions\)\.$/\1/p' \
        <<<"${database_test_output}")"
    [[ "${database_assertions:-}" =~ ^[0-9]+$ ]] \
        || fail 'G2 activity database assertion result is unavailable'
    database_minimum="$(manifest_g2_value activity_database_assertions_minimum 33)"
    [ "${database_assertions}" -ge "${database_minimum}" ] \
        || fail "G2 activity database assertions were removed: ${database_assertions} < ${database_minimum}"
    printf '%s\n' "${registration_database_output}"
    registration_database_assertions="$(sed -nE \
        's/^Event registration database integration passed \(([0-9]+) assertions\)\.$/\1/p' \
        <<<"${registration_database_output}")"
    [[ "${registration_database_assertions:-}" =~ ^[0-9]+$ ]] \
        || fail 'G2 registration database assertion result is unavailable'
    registration_database_minimum="$(manifest_g2_value registration_database_assertions_minimum 41)"
    [ "${registration_database_assertions}" -ge "${registration_database_minimum}" ] \
        || fail "G2 registration database assertions were removed: ${registration_database_assertions} < ${registration_database_minimum}"
    printf '%s\n' "${registration_concurrency_output}"
    registration_concurrency_assertions="$(sed -nE \
        's/^Event registration concurrency passed \(([0-9]+) assertions; 6 contenders \/ 2 seats\)\.$/\1/p' \
        <<<"${registration_concurrency_output}")"
    [[ "${registration_concurrency_assertions:-}" =~ ^[0-9]+$ ]] \
        || fail 'G2 registration concurrency assertion result is unavailable'
    registration_concurrency_minimum="$(manifest_g2_value registration_concurrency_assertions_minimum 13)"
    [ "${registration_concurrency_assertions}" -ge "${registration_concurrency_minimum}" ] \
        || fail "G2 registration concurrency assertions were removed: ${registration_concurrency_assertions} < ${registration_concurrency_minimum}"
else
    printf '%s\n' "${database_test_output}"
    printf '%s\n' "${registration_database_output}"
    printf '%s\n' "${registration_concurrency_output}"
fi

openapi_output="$(ruby backend/custom/openapi/validate.rb)"
printf '%s\n' "${openapi_output}"
openapi_inventory="$(sed -nE \
    's/^[[:space:]]*Paths: ([0-9]+) \(([0-9]+) implemented, ([0-9]+) planned operations\)$/\1 \2 \3/p' \
    <<<"${openapi_output}")"
read -r openapi_paths openapi_implemented openapi_planned <<<"${openapi_inventory}"
[[ "${openapi_paths:-}" =~ ^[0-9]+$ ]] \
    && [[ "${openapi_implemented:-}" =~ ^[0-9]+$ ]] \
    && [[ "${openapi_planned:-}" =~ ^[0-9]+$ ]] \
    || fail 'OpenAPI operation inventory is unavailable'
expected_openapi_paths="$(manifest_g2_value openapi_paths 26)"
expected_openapi_implemented="$(manifest_g2_value openapi_operations_implemented 21)"
expected_openapi_planned="$(manifest_g2_value openapi_operations_planned 7)"
[ "${openapi_paths}" -eq "${expected_openapi_paths}" ] \
    && [ "${openapi_implemented}" -eq "${expected_openapi_implemented}" ] \
    && [ "${openapi_planned}" -eq "${expected_openapi_planned}" ] \
    || fail "G2 OpenAPI inventory changed: ${openapi_paths} paths, ${openapi_implemented} implemented, ${openapi_planned} planned"

ruby -rpsych - backend/custom/openapi/chamber-openapi.yaml <<'RUBY'
content = File.read(ARGV.fetch(0))
spec = Psych.safe_load(
  content,
  permitted_classes: [],
  permitted_symbols: [],
  aliases: true,
  filename: ARGV.fetch(0)
)
expected = {
  'listEvents' => 'implemented',
  'showEvent' => 'implemented',
  'createEventRegistration' => 'planned',
  'listMyEventRegistrations' => 'implemented',
  'showMyEventRegistration' => 'implemented',
  'createEventCheckin' => 'implemented',
  'createEventForAdmin' => 'planned',
  'updateEventForAdmin' => 'planned',
  'publishEventForAdmin' => 'planned',
  'cancelEventForAdmin' => 'planned',
  'issueEventCheckinTokenForAdmin' => 'planned',
  'createManualEventCheckinForAdmin' => 'planned'
}
actual = {}
spec.fetch('paths', {}).each_value do |path_item|
  path_item.each do |method, operation|
    next unless %w[get post patch put delete].include?(method) && operation.is_a?(Hash)
    operation_id = operation['operationId']
    actual[operation_id] = operation['x-implementation-status'] if expected.key?(operation_id)
  end
end
mismatches = expected.each_with_object([]) do |(operation_id, status), found|
  found << "#{operation_id}=#{actual[operation_id].inspect}, expected #{status.inspect}" unless actual[operation_id] == status
end
abort "G2 OpenAPI operations differ: #{mismatches.join('; ')}" unless mismatches.empty?
RUBY

git diff --check
[ -z "$(git -C backend/crmeb status --porcelain)" ] || fail 'CRMEB submodule is dirty'

printf 'G2 activity core gate OK (%s mode)\n' "${MODE}"
printf 'PHP: %s G2 files linted; domain: %s cases\n' "${linted}" "${domain_cases}"
if [ "${MODE}" = 'local' ]; then
    printf 'Database: 19 + 1 migration checks; %s activity + %s registration assertions\n' \
        "${database_assertions}" "${registration_database_assertions}"
    printf 'Concurrency: 6 contenders / 2 seats; %s assertions\n' "${registration_concurrency_assertions}"
else
    printf 'Database: migration triplets inventoried; runtime assertions delegated to local mode\n'
fi
printf 'OpenAPI: %s paths, %s implemented + %s planned operations\n' \
    "${openapi_paths}" "${openapi_implemented}" "${openapi_planned}"
