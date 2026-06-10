#!/bin/sh
set -eu

cd /var/www

if [ ! -f .env ]; then
    cp .env.example .env
fi

set_env() {
    key="$1"
    value="$2"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env APP_ENV "${APP_ENV:-production}"
set_env APP_DEBUG "${APP_DEBUG:-false}"
set_env APP_KEY "${APP_KEY:-}"
set_env APP_URL "${APP_URL:-http://localhost:8080}"

set_env DB_CONNECTION "${DB_CONNECTION:-mariadb}"
set_env DB_HOST "${DB_HOST:-mariadb}"
set_env DB_PORT "${DB_PORT:-3306}"
set_env DB_DATABASE "${DB_DATABASE:-laravel}"
set_env DB_USERNAME "${DB_USERNAME:-root}"
set_env DB_PASSWORD "${DB_PASSWORD:-}"

set_env CLICKHOUSE_HOST "${CLICKHOUSE_HOST:-clickhouse}"
set_env CLICKHOUSE_PORT "${CLICKHOUSE_PORT:-8123}"
set_env CLICKHOUSE_DATABASE "${CLICKHOUSE_DATABASE:-laravel}"
set_env CLICKHOUSE_USERNAME "${CLICKHOUSE_USERNAME:-laravel}"
set_env CLICKHOUSE_PASSWORD "${CLICKHOUSE_PASSWORD:-laravelmdp}"
set_env CLICKHOUSE_SECURE "${CLICKHOUSE_SECURE:-false}"
set_env CLICKHOUSE_TIMEOUT "${CLICKHOUSE_TIMEOUT:-10}"

php artisan package:discover --ansi >/dev/null 2>&1 || true
php artisan migrate --force --ansi
php artisan clickhouse:import-data --if-empty --timeout="${CLICKHOUSE_IMPORT_TIMEOUT:-300}" --ansi

exec "$@"
