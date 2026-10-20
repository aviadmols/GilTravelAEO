#!/bin/sh
set -e

# SQLite lives on the Railway volume so synced data survives deploys.
if [ -n "$DB_DATABASE" ] && [ ! -f "$DB_DATABASE" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

# Caches need the runtime environment, so they are built at boot, not in the image.
php artisan optimize
php artisan migrate --force

# Daily AEO sync (05:30) runs alongside the web server.
php artisan schedule:work &

# Pull the latest AEO runs on every boot, so a new key shows data without waiting for 05:30.
if [ -n "$HUBSPOT_TOKEN" ]; then
    php artisan aeo:sync || echo "initial aeo:sync failed" >&2 &
fi

exec frankenphp run --config /app/docker/Caddyfile
