#!/usr/bin/env bash

set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_DIR="${PROJECT_ROOT}/backend/crmeb/crmeb"
CUSTOM_APP_DIR="${PROJECT_ROOT}/backend/custom/app/chamber"
CUSTOM_API_PROVIDER="${PROJECT_ROOT}/backend/custom/app/api/provider.php"
CUSTOM_ROOT_PROVIDER="${PROJECT_ROOT}/backend/custom/app/provider.php"
RUNTIME_DIR="${PROJECT_ROOT}/.build-workspace/crmeb-runtime"
COMPOSE_FILE="${PROJECT_ROOT}/deployment/local/docker-compose.crmeb.yml"

COMMAND="${1:-prepare}"
DB_USER="${CRMEB_DB_USER:-crmeb}"
DB_PASSWORD="${CRMEB_DB_PASSWORD:-123456}"
DB_NAME="${CRMEB_DB_NAME:-crmeb}"
ADMIN_ACCOUNT="${CRMEB_LOCAL_ADMIN_ACCOUNT:-admin}"
ADMIN_PASSWORD="${CRMEB_LOCAL_ADMIN_PASSWORD:-Admin@123456}"
HTTP_PORT="${CRMEB_HTTP_PORT:-8011}"

usage() {
    cat <<'EOF'
Usage: ./scripts/prepare-local-crmeb-runtime.sh [command]

Commands:
  prepare   Sync CRMEB into the ignored local runtime (default)
  start     Prepare the runtime and start Docker services
  install   Start services and initialize an empty local database
  status    Show containers, database table count, and HTTP status

Optional environment variables:
  CRMEB_DB_USER, CRMEB_DB_PASSWORD, CRMEB_DB_NAME
  CRMEB_LOCAL_ADMIN_ACCOUNT, CRMEB_LOCAL_ADMIN_PASSWORD
  CRMEB_HTTP_PORT, CRMEB_MYSQL_PORT, CRMEB_REDIS_PORT
  CHAMBER_CORS_ALLOWED_ORIGINS
EOF
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

compose() {
    docker compose -f "${COMPOSE_FILE}" "$@"
}

prepare_runtime() {
    require_command rsync
    [ -d "${SOURCE_DIR}" ] || fail "CRMEB source directory not found: ${SOURCE_DIR}"
    [ -f "${SOURCE_DIR}/public/install/crmeb.sql" ] || fail "CRMEB install SQL not found"
    [ -d "${CUSTOM_APP_DIR}" ] || fail "Chamber overlay not found: ${CUSTOM_APP_DIR}"
    [ -s "${CUSTOM_API_PROVIDER}" ] || fail "CRMEB API provider overlay not found: ${CUSTOM_API_PROVIDER}"
    [ -s "${CUSTOM_ROOT_PROVIDER}" ] || fail "CRMEB root provider overlay not found: ${CUSTOM_ROOT_PROVIDER}"
    if [ -e "${SOURCE_DIR}/app/chamber" ]; then
        [ -d "${SOURCE_DIR}/app/chamber" ] \
            || fail "CRMEB upstream app/chamber is not a directory; review the overlay conflict"
        [ -z "$(find "${SOURCE_DIR}/app/chamber" -mindepth 1 -print -quit)" ] \
            || fail "CRMEB upstream app/chamber is not empty; review the overlay conflict before continuing"
    fi

    mkdir -p "${RUNTIME_DIR}"
    rsync -a --delete \
        --exclude='/.env' \
        --exclude='/.constant' \
        --exclude='/public/install.lock' \
        --exclude='/runtime/' \
        --exclude='/public/uploads/' \
        --exclude='/public/admin/' \
        --exclude='/backup/' \
        --exclude='/supervisord.log' \
        "${SOURCE_DIR}/" "${RUNTIME_DIR}/"

    mkdir -p "${RUNTIME_DIR}/app/chamber"
    rsync -a --delete "${CUSTOM_APP_DIR}/" "${RUNTIME_DIR}/app/chamber/"
    install -m 0644 "${CUSTOM_API_PROVIDER}" "${RUNTIME_DIR}/app/api/provider.php"
    install -m 0644 "${CUSTOM_ROOT_PROVIDER}" "${RUNTIME_DIR}/app/provider.php"

    mkdir -p \
        "${RUNTIME_DIR}/runtime" \
        "${RUNTIME_DIR}/public/uploads" \
        "${RUNTIME_DIR}/backup"
    touch "${RUNTIME_DIR}/.env" "${RUNTIME_DIR}/.constant"
    chmod u+rw "${RUNTIME_DIR}/.env" "${RUNTIME_DIR}/.constant"

    if git -C "${PROJECT_ROOT}/backend/crmeb" rev-parse HEAD >/dev/null 2>&1; then
        git -C "${PROJECT_ROOT}/backend/crmeb" rev-parse HEAD > "${RUNTIME_DIR}/.source-revision"
    fi
    if git -C "${PROJECT_ROOT}" rev-parse HEAD >/dev/null 2>&1; then
        git -C "${PROJECT_ROOT}" rev-parse HEAD > "${RUNTIME_DIR}/.overlay-revision"
    fi

    printf 'CRMEB runtime and Chamber overlay prepared: %s\n' "${RUNTIME_DIR}"
}

start_services() {
    require_command docker
    compose up -d --remove-orphans
    compose exec -T nginx nginx -t >/dev/null
    compose exec -T nginx nginx -s reload >/dev/null
}

wait_for_services() {
    local attempt
    for attempt in $(seq 1 60); do
        if compose exec -T mysql sh -lc \
            'MYSQL_PWD="$MYSQL_PASSWORD" mysqladmin ping -h 127.0.0.1 -u "$MYSQL_USER" --silent' \
            >/dev/null 2>&1; then
            break
        fi
        [ "${attempt}" -lt 60 ] || fail "MySQL did not become ready"
        sleep 1
    done

    for attempt in $(seq 1 30); do
        if compose exec -T redis sh -lc \
            'redis-cli -a "$REDIS_PASSWORD" ping 2>/dev/null' \
            | grep -q '^PONG$'; then
            return
        fi
        [ "${attempt}" -lt 30 ] || fail "Redis did not become ready"
        sleep 1
    done
}

wait_for_http() {
    command -v curl >/dev/null 2>&1 || return

    local attempt status
    for attempt in $(seq 1 30); do
        status="$(curl -o /dev/null -sS --max-time 3 -w '%{http_code}' \
            "http://localhost:${HTTP_PORT}/adminapi/login/info" || true)"
        if [[ "${status}" =~ ^[234][0-9][0-9]$ ]]; then
            return
        fi
        [ "${attempt}" -lt 30 ] || fail "CRMEB HTTP service did not become ready (last status: ${status})"
        sleep 1
    done
}

database_table_count() {
    compose exec -T mysql sh -lc \
        'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"$MYSQL_DATABASE\"" -u "$MYSQL_USER"' \
        | tr -d '[:space:]'
}

baseline_table_count() {
    awk '/^CREATE TABLE/{count++} END{print count + 0}' \
        "${RUNTIME_DIR}/public/install/crmeb.sql"
}

write_runtime_config() {
    compose exec -T phpfpm php -r '
$template = file_get_contents("/var/www/public/install/.env");
if ($template === false) {
    fwrite(STDERR, "Unable to read /var/www/public/install/.env\n");
    exit(1);
}
$replacements = [
    "#DB_HOST#" => getenv("MYSQL_HOST_IP"),
    "#DB_PORT#" => getenv("MYSQL_PORT"),
    "#DB_USER#" => getenv("MYSQL_USER"),
    "#DB_PWD#" => getenv("MYSQL_PASSWORD"),
    "#DB_NAME#" => getenv("MYSQL_DATABASE"),
    "#DB_PREFIX#" => "eb_",
    "#DB_CHARSET#" => "utf8mb4",
    "#CACHE_TYPE#" => "redis",
    "#CACHE_PREFIX#" => "mingde_local_cache:",
    "#CACHE_TAG_PREFIX#" => "mingde_local_tag:",
    "#RB_HOST#" => getenv("REDIS_HOST_IP"),
    "#RB_PORT#" => getenv("REDIS_PORT"),
    "#RB_PWD#" => getenv("REDIS_PASSWORD"),
    "#RB_SELECT#" => getenv("REDIS_DATABASE"),
    "#QUEUE_NAME#" => "mingde_local",
];
$config = str_replace("APP_DEBUG = false", "APP_DEBUG = true", strtr($template, $replacements));
if (preg_match("/#[A-Z_]+#/", $config)) {
    fwrite(STDERR, "Unresolved placeholder in generated /var/www/.env\n");
    exit(1);
}
if (file_put_contents("/var/www/.env", $config) === false) {
    fwrite(STDERR, "Unable to write /var/www/.env\n");
    exit(1);
}
$constant = "<?php\n"
    . "define(\"INSTALL_DATE\", " . time() . ");\n"
    . "define(\"SERIALNUMBER\", \"MINGDELOCAL\");\n";
if (file_put_contents("/var/www/.constant", $constant) === false) {
    fwrite(STDERR, "Unable to write /var/www/.constant\n");
    exit(1);
}
touch("/var/www/public/install.lock");
'
}

configure_local_admin() {
    [[ "${ADMIN_ACCOUNT}" =~ ^[A-Za-z0-9_.@-]{3,32}$ ]] \
        || fail "CRMEB_LOCAL_ADMIN_ACCOUNT must be 3-32 simple ASCII characters"
    [ "${#ADMIN_PASSWORD}" -ge 10 ] \
        || fail "CRMEB_LOCAL_ADMIN_PASSWORD must contain at least 10 characters"

    local password_hash
    password_hash="$(compose exec -T \
        -e "CRMEB_LOCAL_ADMIN_PASSWORD=${ADMIN_PASSWORD}" \
        phpfpm php -r 'echo password_hash(getenv("CRMEB_LOCAL_ADMIN_PASSWORD"), PASSWORD_BCRYPT);')"

    compose exec -T mysql sh -lc \
        'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u "$MYSQL_USER" "$MYSQL_DATABASE"' <<SQL
UPDATE eb_system_admin
SET account = '${ADMIN_ACCOUNT}',
    pwd = '${password_hash}',
    real_name = 'Local Admin',
    roles = '1',
    status = 1,
    is_del = 0
WHERE id = 1;
UPDATE eb_system_config
SET value = '\"http://localhost:${HTTP_PORT}\"'
WHERE menu_name = 'site_url';
SQL
}

install_local_database() {
    local table_count marker_count expected_table_count
    table_count="$(database_table_count)"
    expected_table_count="$(baseline_table_count)"

    if [ "${table_count}" -gt 0 ]; then
        if [ -s "${RUNTIME_DIR}/.env" ] && [ -f "${RUNTIME_DIR}/public/install.lock" ]; then
            printf 'CRMEB is already initialized (%s tables); existing data was preserved.\n' "${table_count}"
            return
        fi
        marker_count="$(compose exec -T mysql sh -lc \
            'MYSQL_PWD="$MYSQL_PASSWORD" mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"$MYSQL_DATABASE\" AND table_name=\"eb_system_admin\"" -u "$MYSQL_USER"' \
            | tr -d '[:space:]')"
        [ "${marker_count}" -eq 1 ] \
            || fail "Database is not empty and is not a recognizable CRMEB schema; existing data was preserved."

        [ "${table_count}" -ge "${expected_table_count}" ] \
            || fail "CRMEB baseline import is incomplete (${table_count}/${expected_table_count} tables); existing data was preserved. Reset this local database before retrying install."

        printf 'Completing an interrupted CRMEB local initialization...\n'
        write_runtime_config
        configure_local_admin
        printf 'CRMEB runtime configuration repaired; existing tables were preserved.\n'
        return
    fi

    printf 'Importing CRMEB baseline database...\n'
    compose exec -T mysql sh -lc \
        'MYSQL_PWD="$MYSQL_PASSWORD" mysql --default-character-set=utf8mb4 --init-command="SET SESSION sql_mode=NO_ENGINE_SUBSTITUTION" -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
        < "${RUNTIME_DIR}/public/install/crmeb.sql"

    table_count="$(database_table_count)"
    [ "${table_count}" -ge "${expected_table_count}" ] \
        || fail "CRMEB baseline import completed with only ${table_count}/${expected_table_count} tables"

    write_runtime_config
    configure_local_admin
    printf 'CRMEB local database initialized.\n'
    printf 'Admin login: %s / %s\n' "${ADMIN_ACCOUNT}" "${ADMIN_PASSWORD}"
}

http_status() {
    local status="unreachable"
    if command -v curl >/dev/null 2>&1; then
        status="$(curl -o /dev/null -sS --max-time 15 -w '%{http_code}' \
            "http://localhost:${HTTP_PORT}/adminapi/login/info" || true)"
    fi
    printf 'Backend http://localhost:%s/adminapi/login/info -> %s\n' "${HTTP_PORT}" "${status}"
}

show_status() {
    require_command docker
    compose ps
    if compose ps --status running mysql 2>/dev/null | grep -q 'mingde_crmeb_mysql'; then
        printf 'Database tables: %s\n' "$(database_table_count)"
    fi
    http_status
}

case "${COMMAND}" in
    prepare)
        prepare_runtime
        ;;
    start)
        prepare_runtime
        start_services
        wait_for_services
        wait_for_http
        http_status
        ;;
    install)
        prepare_runtime
        start_services
        wait_for_services
        install_local_database
        wait_for_http
        http_status
        ;;
    status)
        show_status
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        usage >&2
        fail "Unknown command: ${COMMAND}"
        ;;
esac
