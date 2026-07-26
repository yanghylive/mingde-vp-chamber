#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"
MIGRATIONS_DIR="${PROJECT_ROOT}/backend/custom/database/migrations"
SEEDS_DIR="${PROJECT_ROOT}/backend/custom/database/seeds"
COMMAND="${1:-status}"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

compose() {
    docker compose -f "${COMPOSE_FILE}" "$@"
}

require_database() {
    command -v docker >/dev/null 2>&1 || fail "Docker is required"
    compose ps --status running mysql 2>/dev/null | grep -q 'mingde_crmeb_mysql' \
        || fail "Local MySQL is not running; run ./scripts/prepare-local-crmeb-runtime.sh install first"
}

mysql_exec() {
    compose exec -T mysql sh -lc \
        'MYSQL_PWD="$MYSQL_PASSWORD" mysql --default-character-set=utf8mb4 -u "$MYSQL_USER" "$MYSQL_DATABASE" "$@"' \
        sh "$@"
}

mysql_value() {
    local query="$1"
    compose exec -T mysql sh -lc \
        'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "$1" -u "$MYSQL_USER" "$MYSQL_DATABASE"' sh "${query}" \
        | tr -d '\r'
}

ensure_registry() {
    mysql_exec <<'SQL'
CREATE TABLE IF NOT EXISTS `ch_schema_migration` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` varchar(128) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ch_schema_migration_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商会数据库迁移记录';
SQL
}

file_checksum() {
    shasum -a 256 "$1" | awk '{print $1}'
}

preflight_migrations() {
    local file version verify_file count=0
    [ -d "${MIGRATIONS_DIR}" ] || fail "Migration directory not found: ${MIGRATIONS_DIR}"

    for file in "${MIGRATIONS_DIR}"/*.up.sql; do
        [ -f "${file}" ] || continue
        count=$((count + 1))
        version="$(basename "${file}" .up.sql)"
        [[ "${version}" =~ ^[0-9]{12,14}_[a-z0-9_]+$ ]] \
            || fail "Invalid migration filename: $(basename "${file}")"
        verify_file="${file%.up.sql}.verify.sql"
        [ -s "${verify_file}" ] \
            || fail "Migration verifier is required: $(basename "${verify_file}")"
    done

    [ "${count}" -gt 0 ] || fail "No migration files found in ${MIGRATIONS_DIR}"

    for verify_file in "${MIGRATIONS_DIR}"/*.verify.sql; do
        [ -f "${verify_file}" ] || continue
        file="${verify_file%.verify.sql}.up.sql"
        [ -f "${file}" ] \
            || fail "Orphan migration verifier: $(basename "${verify_file}")"
    done
}

verify_migration_structure() {
    local version="$1" verify_file="$2" output passed_checks

    printf 'VERIFY MIGRATION %s\n' "${version}"
    if ! output="$(mysql_exec < "${verify_file}")"; then
        fail "Migration structure verification could not run: ${version}"
    fi
    if printf '%s\n' "${output}" | grep -Eq '(^|[[:space:]])FAIL($|[[:space:]])'; then
        printf '%s\n' "${output}"
        fail "Migration structure verification failed: ${version}"
    fi
    passed_checks="$(printf '%s\n' "${output}" | awk '$2 == "PASS" { count++ } END { print count + 0 }')"
    [ "${passed_checks}" -gt 0 ] || fail "Migration verifier returned no checks: ${version}"
    printf 'PASS %s (%s structural checks)\n' "${version}" "${passed_checks}"
}

refresh_timer_cache() {
    if compose ps --status running timer 2>/dev/null | grep -q 'mingde_crmeb_timer'; then
        # CRMEB's Workerman timer snapshots the crontab rows at process start.
        # Restart it after migrations so newly registered Chamber jobs are
        # actually scheduled in the local deployment.
        compose restart timer >/dev/null
        return 0
    fi

    if ! compose ps --status running phpfpm 2>/dev/null | grep -q 'mingde_crmeb_php'; then
        printf 'SKIP timer cache refresh: no PHP or timer container is running\n' >&2
        return 0
    fi

    compose exec -T phpfpm php -r '
require "/var/www/vendor/autoload.php";
(new \think\App())->initialize();
\think\facade\Cache::delete("crontabCache");
' >/dev/null
}

apply_migrations() {
    local file version verify_file checksum existing
    preflight_migrations
    ensure_registry

    for file in "${MIGRATIONS_DIR}"/*.up.sql; do
        [ -f "${file}" ] || continue
        version="$(basename "${file}" .up.sql)"
        verify_file="${file%.up.sql}.verify.sql"
        checksum="$(file_checksum "${file}")"
        existing="$(mysql_value "SELECT checksum FROM ch_schema_migration WHERE version='${version}' LIMIT 1;")"

        if [ -n "${existing}" ]; then
            [ "${existing}" = "${checksum}" ] \
                || fail "Applied migration checksum changed: ${version}"
            printf 'SKIP %s (already applied)\n' "${version}"
            verify_migration_structure "${version}" "${verify_file}"
            continue
        fi

        printf 'APPLY %s\n' "${version}"
        mysql_exec < "${file}"
        verify_migration_structure "${version}" "${verify_file}"
        mysql_exec <<SQL
INSERT INTO ch_schema_migration (version, checksum, applied_at)
VALUES ('${version}', '${checksum}', UNIX_TIMESTAMP());
SQL
        printf 'RECORDED %s\n' "${version}"
    done

    refresh_timer_cache
}

verify_migrations() {
    local file version verify_file checksum existing
    preflight_migrations
    ensure_registry

    for file in "${MIGRATIONS_DIR}"/*.up.sql; do
        [ -f "${file}" ] || continue
        version="$(basename "${file}" .up.sql)"
        verify_file="${file%.up.sql}.verify.sql"
        checksum="$(file_checksum "${file}")"
        existing="$(mysql_value "SELECT checksum FROM ch_schema_migration WHERE version='${version}' LIMIT 1;")"

        verify_migration_structure "${version}" "${verify_file}"
        [ -n "${existing}" ] \
            || fail "Migration is structurally present but not registered: ${version}; run migrate"
        [ "${existing}" = "${checksum}" ] \
            || fail "Applied migration checksum changed: ${version}"
    done
}

apply_seeds() {
    local file
    [ -d "${SEEDS_DIR}" ] || fail "Seed directory not found: ${SEEDS_DIR}"

    for file in "${SEEDS_DIR}"/*.sql; do
        [ -f "${file}" ] || continue
        [[ "${file}" == *.verify.sql ]] && continue
        printf 'SEED %s\n' "$(basename "${file}")"
        mysql_exec < "${file}"
    done
}

verify_seeds() {
    local file output

    for file in "${SEEDS_DIR}"/*.verify.sql; do
        [ -f "${file}" ] || continue
        printf 'VERIFY %s\n' "$(basename "${file}")"
        output="$(mysql_exec < "${file}")"
        printf '%s\n' "${output}"
        if printf '%s\n' "${output}" | grep -Eq '(^|[[:space:]])FAIL($|[[:space:]])'; then
            fail "Seed verification failed: $(basename "${file}")"
        fi
    done
}

show_status() {
    local core_tables tenant_count=0 channel_count=0
    ensure_registry
    core_tables="$(mysql_value "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'ch\\_%';")"
    if [ "$(mysql_value "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ch_tenant';")" -eq 1 ]; then
        tenant_count="$(mysql_value "SELECT COUNT(*) FROM ch_tenant WHERE is_del=0;")"
    fi
    if [ "$(mysql_value "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ch_channel';")" -eq 1 ]; then
        channel_count="$(mysql_value "SELECT COUNT(*) FROM ch_channel WHERE is_del=0;")"
    fi

    printf 'Chamber tables: %s\n' "${core_tables}"
    printf 'Tenants/channels: %s/%s\n' "${tenant_count}" "${channel_count}"
    mysql_exec -e 'SELECT version, checksum, FROM_UNIXTIME(applied_at) AS applied_at FROM ch_schema_migration ORDER BY id;'
}

case "${COMMAND}" in
    migrate)
        require_database
        apply_migrations
        ;;
    seed)
        require_database
        apply_seeds
        ;;
    verify)
        require_database
        verify_migrations
        verify_seeds
        ;;
    setup)
        require_database
        apply_migrations
        apply_seeds
        verify_seeds
        show_status
        ;;
    status)
        require_database
        show_status
        ;;
    -h|--help|help)
        printf '%s\n' 'Usage: ./scripts/manage-local-database.sh [migrate|seed|verify|setup|status]'
        ;;
    *)
        fail "Unknown command: ${COMMAND}"
        ;;
esac
