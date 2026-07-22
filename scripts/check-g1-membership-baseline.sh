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

for command in docker ruby awk grep; do
    command -v "${command}" >/dev/null 2>&1 || fail "Required command not found: ${command}"
done

cd "${PROJECT_ROOT}"

bash -n scripts/check-g1-membership-baseline.sh
./scripts/check-g0-baseline.sh

database_output="$(./scripts/manage-local-database.sh verify)"
printf '%s\n' "${database_output}"
membership_commerce_checks="$(migration_check_count '202607210003_create_membership_commerce' <<<"${database_output}")"
member_hardening_checks="$(migration_check_count '202607210004_harden_member_verification_projection' <<<"${database_output}")"
[[ "${membership_commerce_checks}" =~ ^[0-9]+$ ]] \
    || fail "membership commerce migration verification result is unavailable"
[[ "${member_hardening_checks}" =~ ^[0-9]+$ ]] \
    || fail "member hardening migration verification result is unavailable"
[ "${membership_commerce_checks}" -ge 158 ] \
    || fail "membership commerce migration verification lost checks: ${membership_commerce_checks} < 158"
[ "${member_hardening_checks}" -ge 71 ] \
    || fail "member hardening migration verification lost checks: ${member_hardening_checks} < 71"

g1_migration_minimum="$(manifest_g1_minimum \
    'g1_migration_structural_checks_minimum' 'g1_migration_structural_checks' 229)"
g1_migration_checks="$((membership_commerce_checks + member_hardening_checks))"
[ "${g1_migration_checks}" -ge "${g1_migration_minimum}" ] \
    || fail "G1 migration verification lost checks: ${g1_migration_checks} < ${g1_migration_minimum}"

database_tables="$(docker compose -f "${COMPOSE_FILE}" exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
    | tr -d '[:space:]')"
[ "${database_tables}" -ge 179 ] || fail "expected at least 179 database tables, got ${database_tables}"

chamber_tables="$(docker compose -f "${COMPOSE_FILE}" exec -T mysql sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE \"ch\\\\_%\"" -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
    | tr -d '[:space:]')"
[ "${chamber_tables}" -ge 22 ] || fail "expected at least 22 Chamber tables including migration registry, got ${chamber_tables}"

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

openapi_output="$(ruby backend/custom/openapi/validate.rb)"
printf '%s\n' "${openapi_output}"
grep -Fxq '  Contract version: 0.3.0' <<<"${openapi_output}" \
    || fail "OpenAPI membership contract version changed"
grep -Fxq '  Paths: 9 (2 implemented, 9 planned operations)' <<<"${openapi_output}" \
    || fail "OpenAPI membership operation inventory changed"
openapi_schema_count="$(awk '$1 == "Component" && $2 == "schemas:" && $3 ~ /^[0-9]+$/ && NF == 3 { print $3 }' <<<"${openapi_output}")"
[[ "${openapi_schema_count}" =~ ^[0-9]+$ ]] || fail "OpenAPI membership schema count is unavailable"
openapi_schema_minimum="$(manifest_g1_minimum \
    'openapi_schemas_minimum' 'openapi_schemas' 77)"
[ "${openapi_schema_count}" -ge "${openapi_schema_minimum}" ] \
    || fail "OpenAPI membership schemas were removed: ${openapi_schema_count} < ${openapi_schema_minimum}"

ruby -rjson -e '
  baseline = JSON.parse(File.read(ARGV[0])).fetch("g1_membership_baseline")
  expected = {
    "g1_migration_structural_checks" => Integer(ARGV[1]),
    "membership_domain_tests" => Integer(ARGV[2]),
    "openapi_version" => "0.3.0",
    "openapi_paths" => 9,
    "openapi_operations_total" => 11,
    "openapi_operations_implemented" => 2,
    "openapi_operations_planned" => 9,
    "openapi_schemas" => Integer(ARGV[3])
  }
  mismatches = expected.each_with_object([]) do |(key, value), found|
    found << "#{key}=#{baseline[key].inspect}, expected #{value.inspect}" unless baseline[key] == value
  end
  abort "PROJECT_MANIFEST G1 metrics differ: #{mismatches.join("; ")}" unless mismatches.empty?
' PROJECT_MANIFEST.json "${g1_migration_checks}" "${membership_tests}" "${openapi_schema_count}" \
    || fail "PROJECT_MANIFEST G1 metrics are stale"

git diff --check
[ -z "$(git -C backend/crmeb status --porcelain)" ] || fail "CRMEB submodule is dirty"

printf 'G1 membership baseline OK\n'
printf 'Database: 179+ tables, %s G1 structural checks\n' "${g1_migration_checks}"
printf 'Domain: %s membership state and projection tests\n' "${membership_tests}"
printf 'OpenAPI: 0.3.0, 2 implemented + 9 planned operations, %s schemas\n' "${openapi_schema_count}"
