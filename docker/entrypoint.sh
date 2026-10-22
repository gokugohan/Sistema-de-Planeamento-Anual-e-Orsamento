#!/bin/sh
# Starts every container of the application (web requests, queue worker, scheduler) from the same image.
# Environment: WAIT_FOR_DB (default true), RUN_MIGRATIONS (default false; the compose file sets it on the app container only).
set -e
cd /var/www/html

if [ "${WAIT_FOR_DB:-true}" = "true" ]; then
    echo "[paa] waiting for the database at ${DB_HOST:-db}..."
    tries=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; do
        tries=$((tries + 1))
        if [ "$tries" -gt 60 ]; then
            echo "[paa] the database did not answer in time" >&2
            exit 1
        fi
        sleep 2
    done
fi

# the storage volume may be new and empty
mkdir -p storage/app/public storage/app/backups storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

artisan() { su-exec www-data php artisan "$@"; }

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[paa] migrating the database"
    artisan migrate --force
fi

# configuration, routes and views are cached: faster, and a mistake in the environment shows up at once
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache

if [ "$1" = "php-fpm" ]; then
    exec "$@"   # the master process runs as root and its workers as www-data
fi
exec su-exec www-data "$@"
