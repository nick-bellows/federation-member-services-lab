#!/bin/bash
# Release entrypoint (ADR-0015). Compared with docker/api/entrypoint.sh, the
# development one:
#   - no storage:link, no optimize:clear (the image is immutable);
#   - the database wait uses the configured connection, host and port
#     through PDO instead of a hard-coded database:3306;
#   - migrations run only when RUN_MIGRATIONS=1, so that the release
#     pipeline runs them once as a task and replicas never race.
set -euo pipefail

cd /var/www/html

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

wait_for_database() {
    local attempts="${DB_WAIT_ATTEMPTS:-60}"
    local i
    for i in $(seq 1 "$attempts"); do
        if php -r '
            $c = getenv("DB_CONNECTION") ?: "mysql";
            $dsn = match ($c) {
                "pgsql" => sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 5432, getenv("DB_DATABASE")),
                "sqlite" => "sqlite:" . getenv("DB_DATABASE"),
                default => sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE")),
            };
            try { new PDO($dsn, getenv("DB_USERNAME") ?: null, getenv("DB_PASSWORD") ?: null, [PDO::ATTR_TIMEOUT => 3]); exit(0); }
            catch (Throwable $e) { exit(1); }
        '; then
            echo "Database is reachable"
            return 0
        fi
        echo "Waiting for the database ($i/$attempts)"
        sleep 2
    done
    echo "Database not reachable after $attempts attempts" >&2
    return 1
}

wait_for_database

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "Running migrations (RUN_MIGRATIONS=1)"
    php artisan migrate --force
fi

exec "$@"
