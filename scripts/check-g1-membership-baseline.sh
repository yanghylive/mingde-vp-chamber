#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

manifest_g1_minimum() {
    local minimum_key="$1" legacy_key="$2" floor="$3" configured

    configured="$(ruby -rjson -e '
      manifest = JSON.parse(File.read(ARGV[0]))
      baseline = manifest.fetch("g1_membership_baseline", {})
      value = baseline[ARGV[1]] || baseline[ARGV[2]]
      print value if value.is_a?(Integer)
    ' PROJECT_MANIFEST.json "${minimum_key}" "${legacy_key}")"

    if [[ "${configured}" =~ ^[0-9]+$ ]] && [ "${configured}" -ge "${floor}" ]; then
        printf '%s\n' "${configured}"
    else
        printf '%s\n' "${floor}"
    fi
}

migration_check_count() {
    local version="$1"

    awk -v expected_version="${version}" '
      $1 == "PASS" && $2 == expected_version &&
      $3 ~ /^\([0-9]+$/ && $4 == "structural" && $5 == "checks)" && NF == 5 {
        count = $3
        sub(/^\(/, "", count)
        print count
      }
    '
}

test_result() {
    awk '
      {
        line = $0
        sub(/\r$/, "", line)
        if (line ~ /^[0-9]+ tests, [0-9]+ failures$/) {
          split(line, fields, " ")
          gsub(/,/, "", fields[2])
          print fields[1], fields[3]
        }
      }
    '
}

assertion_result() {
    sed -nE 's/^PASS .*\(([0-9]+) assertions.*$/\1/p'
}

case_result() {
    sed -nE 's/^.*passed \(([0-9]+) cases\)\.?$/\1/p'
}

for command in docker node ruby awk grep sed; do
    command -v "${command}" >/dev/null 2>&1 || fail "Required command not found: ${command}"
done

cd "${PROJECT_ROOT}"

bash -n scripts/check-g1-membership-baseline.sh
bash -n scripts/check-g1-member-bootstrap.sh
bash -n scripts/check-g1-profile-verification.sh
bash -n scripts/check-g1-membership-checkout.sh
bash -n scripts/check-g1-membership-entitlement.sh
./scripts/check-g0-baseline.sh

database_output="$(./scripts/manage-local-database.sh verify)"
printf '%s\n' "${database_output}"
membership_commerce_checks="$(migration_check_count '202607210003_create_membership_commerce' <<<"${database_output}")"
member_hardening_checks="$(migration_check_count '202607210004_harden_member_verification_projection' <<<"${database_output}")"
admin_menu_checks="$(migration_check_count '202607250001_register_chamber_admin_menu' <<<"${database_output}")"
profile_json_checks="$(migration_check_count '202607250002_normalize_member_profile_json' <<<"${database_output}")"
member_asset_checks="$(migration_check_count '202607250003_create_member_asset' <<<"${database_output}")"
order_context_idempotency_checks="$(migration_check_count '202607250004_link_order_context_idempotency' <<<"${database_output}")"
repair_timer_checks="$(migration_check_count '202607250005_register_membership_repair_timer' <<<"${database_output}")"
[[ "${membership_commerce_checks}" =~ ^[0-9]+$ ]] \
    || fail "membership commerce migration verification result is unavailable"
[[ "${member_hardening_checks}" =~ ^[0-9]+$ ]] \
    || fail "member hardening migration verification result is unavailable"
[[ "${admin_menu_checks}" =~ ^[0-9]+$ ]] \
    || fail "administrator menu migration verification result is unavailable"
[[ "${profile_json_checks}" =~ ^[0-9]+$ ]] \
    || fail "member profile JSON migration verification result is unavailable"
[[ "${member_asset_checks}" =~ ^[0-9]+$ ]] \
    || fail "member asset migration verification result is unavailable"
[[ "${order_context_idempotency_checks}" =~ ^[0-9]+$ ]] \
    || fail "order context idempotency migration verification result is unavailable"
[[ "${repair_timer_checks}" =~ ^[0-9]+$ ]] \
    || fail "membership repair timer migration verification result is unavailable"
[ "${membership_commerce_checks}" -ge 158 ] \
    || fail "membership commerce migration verification lost checks: ${membership_commerce_checks} < 158"
[ "${member_hardening_checks}" -ge 71 ] \
    || fail "member hardening migration verification lost checks: ${member_hardening_checks} < 71"
[ "${admin_menu_checks}" -ge 4 ] \
    || fail "administrator menu migration verification lost checks: ${admin_menu_checks} < 4"
[ "${profile_json_checks}" -ge 5 ] \
    || fail "member profile JSON migration verification lost checks: ${profile_json_checks} < 5"
[ "${member_asset_checks}" -ge 28 ] \
    || fail "member asset migration verification lost checks: ${member_asset_checks} < 28"
[ "${order_context_idempotency_checks}" -ge 3 ] \
    || fail "order context idempotency migration verification lost checks: ${order_context_idempotency_checks} < 3"
[ "${repair_timer_checks}" -ge 3 ] \
    || fail "membership repair timer migration verification lost checks: ${repair_timer_checks} < 3"

g1_migration_minimum="$(manifest_g1_minimum \
    'g1_migration_structural_checks_minimum' 'g1_migration_structural_checks' 272)"
g1_migration_checks="$((${membership_commerce_checks} + ${member_hardening_checks} + ${admin_menu_checks} + ${profile_json_checks} + ${member_asset_checks} + ${order_context_idempotency_checks} + ${repair_timer_checks}))"
[ "${g1_migration_checks}" -ge "${g1_migration_minimum}" ] \
    || fail "G1 migration verification lost checks: ${g1_migration_checks} < ${g1_migration_minimum}"

database_tables="$(docker compose -f "${COMPOSE_FILE}" exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
    | tr -d '[:space:]')"
[ "${database_tables}" -ge 180 ] || fail "expected at least 180 database tables, got ${database_tables}"

chamber_tables="$(docker compose -f "${COMPOSE_FILE}" exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE \"ch\\\\_%\"" -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
    | tr -d '[:space:]')"
[ "${chamber_tables}" -ge 23 ] || fail "expected at least 23 Chamber tables including migration registry, got ${chamber_tables}"

membership_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm php /var/www/app/chamber/tests/membership_run.php)"
printf '%s\n' "${membership_output}"
membership_result="$(test_result <<<"${membership_output}")"
read -r membership_tests membership_failures <<<"${membership_result}"
[[ "${membership_tests:-}" =~ ^[0-9]+$ ]] && [[ "${membership_failures:-}" =~ ^[0-9]+$ ]] \
    || fail "membership domain test result is unavailable"
[ "${membership_failures}" -eq 0 ] || fail "membership domain tests reported ${membership_failures} failures"
membership_test_minimum="$(manifest_g1_minimum \
    'membership_domain_tests_minimum' 'membership_domain_tests' 37)"
[ "${membership_tests}" -ge "${membership_test_minimum}" ] \
    || fail "membership domain tests were removed: ${membership_tests} < ${membership_test_minimum}"

checkout_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_run.php)"
printf '%s\n' "${checkout_output}"
checkout_result="$(test_result <<<"${checkout_output}")"
read -r checkout_tests checkout_failures <<<"${checkout_result}"
[[ "${checkout_tests:-}" =~ ^[0-9]+$ ]] && [[ "${checkout_failures:-}" =~ ^[0-9]+$ ]] \
    || fail "membership checkout domain test result is unavailable"
[ "${checkout_failures}" -eq 0 ] \
    || fail "membership checkout domain tests reported ${checkout_failures} failures"
checkout_test_minimum="$(manifest_g1_minimum \
    'membership_checkout_domain_tests_minimum' 'membership_checkout_domain_tests' 15)"
[ "${checkout_tests}" -ge "${checkout_test_minimum}" ] \
    || fail "membership checkout domain tests were removed: ${checkout_tests} < ${checkout_test_minimum}"

order_gateway_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_order_gateway_run.php)"
printf '%s\n' "${order_gateway_output}"
order_gateway_result="$(test_result <<<"${order_gateway_output}")"
read -r order_gateway_tests order_gateway_failures <<<"${order_gateway_result}"
[[ "${order_gateway_tests:-}" =~ ^[0-9]+$ ]] && [[ "${order_gateway_failures:-}" =~ ^[0-9]+$ ]] \
    || fail "membership order gateway test result is unavailable"
[ "${order_gateway_failures}" -eq 0 ] \
    || fail "membership order gateway tests reported ${order_gateway_failures} failures"
order_gateway_test_minimum="$(manifest_g1_minimum \
    'membership_order_gateway_tests_minimum' 'membership_order_gateway_tests' 11)"
[ "${order_gateway_tests}" -ge "${order_gateway_test_minimum}" ] \
    || fail "membership order gateway tests were removed: ${order_gateway_tests} < ${order_gateway_test_minimum}"

checkout_db_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/membership_checkout_db_run.php)"
printf '%s\n' "${checkout_db_output}"
checkout_db_assertions="$(assertion_result <<<"${checkout_db_output}")"
[[ "${checkout_db_assertions}" =~ ^[0-9]+$ ]] \
    || fail "membership checkout database assertion result is unavailable"
checkout_db_minimum="$(manifest_g1_minimum \
    'membership_checkout_database_assertions_minimum' 'membership_checkout_database_assertions' 85)"
[ "${checkout_db_assertions}" -ge "${checkout_db_minimum}" ] \
    || fail "membership checkout database assertions were removed: ${checkout_db_assertions} < ${checkout_db_minimum}"

auth_context_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/auth_context_run.php)"
printf '%s\n' "${auth_context_output}"
auth_context_result="$(test_result <<<"${auth_context_output}")"
read -r auth_context_tests auth_context_failures <<<"${auth_context_result}"
[[ "${auth_context_tests:-}" =~ ^[0-9]+$ ]] && [[ "${auth_context_failures:-}" =~ ^[0-9]+$ ]] \
    || fail "member authentication context test result is unavailable"
[ "${auth_context_failures}" -eq 0 ] \
    || fail "member authentication context tests reported ${auth_context_failures} failures"
auth_context_test_minimum="$(manifest_g1_minimum \
    'member_auth_context_tests_minimum' 'member_auth_context_tests' 33)"
[ "${auth_context_tests}" -ge "${auth_context_test_minimum}" ] \
    || fail "member authentication context tests were removed: ${auth_context_tests} < ${auth_context_test_minimum}"

bootstrap_domain_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/bootstrap_domain_run.php)"
printf '%s\n' "${bootstrap_domain_output}"
bootstrap_domain_result="$(test_result <<<"${bootstrap_domain_output}")"
read -r bootstrap_domain_tests bootstrap_domain_failures <<<"${bootstrap_domain_result}"
[[ "${bootstrap_domain_tests:-}" =~ ^[0-9]+$ ]] && [[ "${bootstrap_domain_failures:-}" =~ ^[0-9]+$ ]] \
    || fail "member bootstrap domain test result is unavailable"
[ "${bootstrap_domain_failures}" -eq 0 ] \
    || fail "member bootstrap domain tests reported ${bootstrap_domain_failures} failures"
bootstrap_domain_test_minimum="$(manifest_g1_minimum \
    'member_bootstrap_domain_tests_minimum' 'member_bootstrap_domain_tests' 28)"
[ "${bootstrap_domain_tests}" -ge "${bootstrap_domain_test_minimum}" ] \
    || fail "member bootstrap domain tests were removed: ${bootstrap_domain_tests} < ${bootstrap_domain_test_minimum}"

profile_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_profile_run.php)"
printf '%s\n' "${profile_output}"
profile_result="$(test_result <<<"${profile_output}")"
read -r profile_tests profile_failures <<<"${profile_result}"
[[ "${profile_tests:-}" =~ ^[0-9]+$ ]] && [[ "${profile_failures:-}" =~ ^[0-9]+$ ]] \
    || fail "member profile domain test result is unavailable"
[ "${profile_failures}" -eq 0 ] || fail "member profile domain tests reported ${profile_failures} failures"
profile_test_minimum="$(manifest_g1_minimum \
    'member_profile_domain_tests_minimum' 'member_profile_domain_tests' 16)"
[ "${profile_tests}" -ge "${profile_test_minimum}" ] \
    || fail "member profile domain tests were removed: ${profile_tests} < ${profile_test_minimum}"

asset_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_asset_run.php)"
printf '%s\n' "${asset_output}"
asset_result="$(test_result <<<"${asset_output}")"
read -r asset_tests asset_failures <<<"${asset_result}"
[[ "${asset_tests:-}" =~ ^[0-9]+$ ]] && [[ "${asset_failures:-}" =~ ^[0-9]+$ ]] \
    || fail "member asset domain test result is unavailable"
[ "${asset_failures}" -eq 0 ] || fail "member asset domain tests reported ${asset_failures} failures"
asset_test_minimum="$(manifest_g1_minimum \
    'member_asset_domain_tests_minimum' 'member_asset_domain_tests' 10)"
[ "${asset_tests}" -ge "${asset_test_minimum}" ] \
    || fail "member asset domain tests were removed: ${asset_tests} < ${asset_test_minimum}"

asset_db_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/member_asset_db_run.php)"
printf '%s\n' "${asset_db_output}"
asset_db_assertions="$(assertion_result <<<"${asset_db_output}")"
[[ "${asset_db_assertions}" =~ ^[0-9]+$ ]] \
    || fail "member asset database assertion result is unavailable"
asset_db_minimum="$(manifest_g1_minimum \
    'member_asset_database_assertions_minimum' 'member_asset_database_assertions' 44)"
[ "${asset_db_assertions}" -ge "${asset_db_minimum}" ] \
    || fail "member asset database assertions were removed: ${asset_db_assertions} < ${asset_db_minimum}"

verification_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/graduate_verification_run.php)"
printf '%s\n' "${verification_output}"
verification_tests="$(case_result <<<"${verification_output}")"
[[ "${verification_tests:-}" =~ ^[0-9]+$ ]] \
    || fail "graduate verification domain test result is unavailable"
verification_test_minimum="$(manifest_g1_minimum \
    'graduate_verification_domain_tests_minimum' 'graduate_verification_domain_tests' 12)"
[ "${verification_tests}" -ge "${verification_test_minimum}" ] \
    || fail "graduate verification domain tests were removed: ${verification_tests} < ${verification_test_minimum}"

verification_db_output="$(docker compose -f "${COMPOSE_FILE}" exec -T phpfpm \
    php /var/www/app/chamber/tests/graduate_verification_db_run.php)"
printf '%s\n' "${verification_db_output}"
verification_db_assertions="$(assertion_result <<<"${verification_db_output}")"
[[ "${verification_db_assertions}" =~ ^[0-9]+$ ]] \
    || fail "graduate verification database assertion result is unavailable"
verification_db_minimum="$(manifest_g1_minimum \
    'graduate_verification_database_assertions_minimum' 'graduate_verification_database_assertions' 119)"
[ "${verification_db_assertions}" -ge "${verification_db_minimum}" ] \
    || fail "graduate verification database assertions were removed: ${verification_db_assertions} < ${verification_db_minimum}"

frontend_output="$(./scripts/prepare-local-frontend.sh test)"
printf '%s\n' "${frontend_output}"
tenant_brand_tests="$(sed -nE 's/^PASS ([0-9]+) tenant brand tests$/\1/p' <<<"${frontend_output}")"
member_ui_output="$(node frontend/custom/shared/tests/member-ui.test.js)"
member_ui_tests="$(grep -c '^PASS ' <<<"${member_ui_output}" | tr -d '[:space:]')"
[[ "${tenant_brand_tests}" =~ ^[0-9]+$ ]] || fail "tenant brand frontend test result is unavailable"
[[ "${member_ui_tests}" =~ ^[0-9]+$ ]] || fail "member UI frontend test result is unavailable"
tenant_brand_test_minimum="$(manifest_g1_minimum \
    'tenant_brand_tests_minimum' 'tenant_brand_tests' 6)"
member_ui_test_minimum="$(manifest_g1_minimum \
    'member_ui_tests_minimum' 'member_ui_tests' 18)"
[ "${tenant_brand_tests}" -ge "${tenant_brand_test_minimum}" ] \
    || fail "tenant brand frontend tests were removed: ${tenant_brand_tests} < ${tenant_brand_test_minimum}"
[ "${member_ui_tests}" -ge "${member_ui_test_minimum}" ] \
    || fail "member UI frontend tests were removed: ${member_ui_tests} < ${member_ui_test_minimum}"

./scripts/check-g1-member-bootstrap.sh
./scripts/check-g1-profile-verification.sh
./scripts/check-g1-membership-checkout.sh
./scripts/check-g1-membership-entitlement.sh

openapi_output="$(ruby backend/custom/openapi/validate.rb)"
printf '%s\n' "${openapi_output}"
grep -Fxq '  Contract version: 0.5.0' <<<"${openapi_output}" \
    || fail "OpenAPI membership contract version changed"
ruby -rpsych - backend/custom/openapi/chamber-openapi.yaml <<'RUBY'
content = File.read(ARGV.fetch(0))
spec = Psych.safe_load(
  content,
  permitted_classes: [],
  permitted_symbols: [],
  aliases: true,
  filename: ARGV.fetch(0)
)
expected = %w[
  getChamberHealth
  getChamberBootstrap
  bootstrapChamberMember
  getChamberMemberProfile
  updateChamberMemberProfile
  uploadChamberMemberAsset
  getChamberMemberAssetContent
  getGraduateVerification
  submitGraduateVerification
  listGraduateVerificationsForAdmin
  getChamberMemberAssetContentForAdmin
  getGraduateVerificationForAdmin
  reviewGraduateVerification
  listMembershipPlans
  createMembershipCheckout
  getMembershipSummary
]
actual = {}
spec.fetch('paths', {}).each_value do |path_item|
  path_item.each do |method, operation|
    next unless %w[get post patch put delete].include?(method) && operation.is_a?(Hash)
    operation_id = operation['operationId']
    actual[operation_id] = operation['x-implementation-status'] if expected.include?(operation_id)
  end
end
mismatches = expected.each_with_object([]) do |operation_id, found|
  found << "#{operation_id}=#{actual[operation_id].inspect}" unless actual[operation_id] == 'implemented'
end
abort "OpenAPI lost a G1 implemented operation: #{mismatches.join('; ')}" unless mismatches.empty?
RUBY
openapi_schema_count="$(awk '$1 == "Component" && $2 == "schemas:" && $3 ~ /^[0-9]+$/ && NF == 3 { print $3 }' <<<"${openapi_output}")"
[[ "${openapi_schema_count}" =~ ^[0-9]+$ ]] || fail "OpenAPI membership schema count is unavailable"
openapi_schema_minimum="$(manifest_g1_minimum \
    'openapi_schemas_minimum' 'openapi_schemas' 85)"
[ "${openapi_schema_count}" -ge "${openapi_schema_minimum}" ] \
    || fail "OpenAPI membership schemas were removed: ${openapi_schema_count} < ${openapi_schema_minimum}"

ruby -rjson -e '
  manifest = JSON.parse(File.read(ARGV[0]))
  baseline = manifest.fetch("g1_membership_baseline")

  membership_checks = Integer(ARGV[1])
  hardening_checks = Integer(ARGV[2])
  admin_menu_checks = Integer(ARGV[3])
  profile_json_checks = Integer(ARGV[4])
  asset_migration_checks = Integer(ARGV[5])
  order_context_idempotency_checks = Integer(ARGV[6])
  g1_checks = Integer(ARGV[7])
  expected = {
    "completed_on" => "2026-07-26",
    "chamber_domain_tables" => 22,
    "migration_registry_tables" => 1,
    "chamber_tables_including_registry" => 23,
    "database_tables_total" => 180,
    "membership_commerce_structural_checks" => membership_checks,
    "member_verification_structural_checks" => hardening_checks,
    "admin_menu_structural_checks" => admin_menu_checks,
    "profile_json_structural_checks" => profile_json_checks,
    "member_asset_structural_checks" => asset_migration_checks,
    "order_context_idempotency_structural_checks" => order_context_idempotency_checks,
    "repair_timer_structural_checks" => Integer(ARGV[22]),
    "profile_verification_structural_checks" => admin_menu_checks + profile_json_checks + asset_migration_checks,
    "g1_migration_structural_checks" => g1_checks,
    "migration_structural_checks_total" => 165 + g1_checks,
    "membership_domain_tests" => Integer(ARGV[8]),
    "membership_checkout_domain_tests" => Integer(ARGV[9]),
    "membership_order_gateway_tests" => Integer(ARGV[10]),
    "membership_checkout_database_assertions" => Integer(ARGV[11]),
    "member_auth_context_tests" => Integer(ARGV[12]),
    "member_bootstrap_domain_tests" => Integer(ARGV[13]),
    "member_bootstrap_same_key_concurrency" => 20,
    "member_bootstrap_distinct_key_concurrency" => 20,
    "member_bootstrap_members_per_tenant" => 1,
    "member_bootstrap_profiles_per_member" => 1,
    "member_bootstrap_consent_events_per_document" => 1,
    "member_bootstrap_cross_tenant_member_records" => 2,
    "member_bootstrap_withdrawn_http_status" => 403,
    "member_profile_domain_tests" => Integer(ARGV[14]),
    "member_asset_domain_tests" => Integer(ARGV[15]),
    "member_asset_database_assertions" => Integer(ARGV[16]),
    "graduate_verification_domain_tests" => Integer(ARGV[17]),
    "graduate_verification_database_assertions" => Integer(ARGV[18]),
    "tenant_brand_tests" => Integer(ARGV[19]),
    "member_ui_tests" => Integer(ARGV[20]),
    "profile_verification_completed_on" => "2026-07-25",
    "membership_checkout_completed_on" => "2026-07-25",
    "membership_entitlement_completed_on" => "2026-07-26",
    "membership_payment_replay_attempts" => 10,
    "membership_concurrent_renewals" => 2,
    "membership_refund_replay_attempts" => 1,
    "openapi_version" => "0.5.0",
    "openapi_paths" => 14,
    "openapi_operations_total" => 16,
    "openapi_operations_implemented" => 16,
    "openapi_operations_planned" => 0,
    "openapi_schemas" => 85,
    "member_bootstrap_gate" => "scripts/check-g1-member-bootstrap.sh",
    "profile_verification_gate" => "scripts/check-g1-profile-verification.sh",
    "membership_checkout_gate" => "scripts/check-g1-membership-checkout.sh",
    "membership_entitlement_gate" => "scripts/check-g1-membership-entitlement.sh",
    "gate" => "scripts/check-g1-membership-baseline.sh"
  }
  mismatches = expected.each_with_object([]) do |(key, value), found|
    found << "#{key}=#{baseline[key].inspect}, expected #{value.inspect}" unless baseline[key] == value
  end
  abort "PROJECT_MANIFEST G1 metrics differ: #{mismatches.join("; ")}" unless mismatches.empty?
' PROJECT_MANIFEST.json "${membership_commerce_checks}" "${member_hardening_checks}" \
    "${admin_menu_checks}" "${profile_json_checks}" "${member_asset_checks}" \
    "${order_context_idempotency_checks}" "${g1_migration_checks}" "${membership_tests}" \
    "${checkout_tests}" "${order_gateway_tests}" "${checkout_db_assertions}" \
    "${auth_context_tests}" "${bootstrap_domain_tests}" "${profile_tests}" "${asset_tests}" \
    "${asset_db_assertions}" "${verification_tests}" "${verification_db_assertions}" \
    "${tenant_brand_tests}" "${member_ui_tests}" "${openapi_schema_count}" "${repair_timer_checks}" \
    || fail "PROJECT_MANIFEST G1 metrics are stale"

git diff --check
[ -z "$(git -C backend/crmeb status --porcelain)" ] || fail "CRMEB submodule is dirty"

printf 'G1 membership baseline OK\n'
printf 'Database: 180+ tables, 23+ Chamber tables, %s G1 / %s total structural checks\n' \
    "${g1_migration_checks}" "$((165 + g1_migration_checks))"
printf 'Domain: %s membership state and projection tests\n' "${membership_tests}"
printf 'Checkout: %s domain + %s CRMEB gateway tests, %s database assertions, real HTTP flow\n' \
    "${checkout_tests}" "${order_gateway_tests}" "${checkout_db_assertions}"
printf 'Entitlement: payment inbox, 10 replays, 2 concurrent renewals, expiry/refund projection\n'
printf 'Bootstrap: %s auth context + %s request/consent tests; same-key and distinct-key 20-way HTTP races\n' \
    "${auth_context_tests}" "${bootstrap_domain_tests}"
printf 'Profile/assets: %s + %s domain tests, %s database assertions\n' \
    "${profile_tests}" "${asset_tests}" "${asset_db_assertions}"
printf 'Graduate verification: %s domain tests, %s database assertions, real member/admin HTTP flow\n' \
    "${verification_tests}" "${verification_db_assertions}"
printf 'Frontend: %s tenant brand + %s member UI tests\n' "${tenant_brand_tests}" "${member_ui_tests}"
printf 'OpenAPI: 16 G1 implemented operations preserved, %s current schemas\n' "${openapi_schema_count}"
