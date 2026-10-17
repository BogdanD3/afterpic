#!/bin/bash
set -euo pipefail

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Generate one with:" >&2
    echo "  docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show" >&2
    exit 1
fi

# Volumes start empty or root-owned, so make sure the folders exist and are writable.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
find storage bootstrap/cache ! -user www-data -exec chown www-data:www-data {} +

as_app() {
    runuser -u www-data -- "$@"
}

# Wait for the database, then migrate.
attempt=0
until as_app php artisan migrate --force; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Database is not reachable after 30 attempts." >&2
        exit 1
    fi
    echo "Waiting for the database (attempt $attempt)..."
    sleep 3
done

as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan event:cache
as_app php artisan view:cache

as_app php artisan app:check-photo-environment || echo "Photo environment check reported problems." >&2

exec "$@"
