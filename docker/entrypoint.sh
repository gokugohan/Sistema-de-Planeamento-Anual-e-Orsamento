#!/bin/sh
# Starts every container of the application (web requests, queue worker, scheduler) from the same image.
# Environment: WAIT_FOR_DB (default true), RUN_MIGRATIONS (default false; the compose file sets it on the app container only),
# AUTO_CREATE_DB (default false): create the database if it does not exist (the user must be allowed to; for example root),
# SEED_IF_EMPTY (default false): after migrating, run the base seeder once, when there is no user yet (creates the first administrator).
set -e
cd /var/www/html

serving=false
if [ "$1" = "php-fpm" ] || [ "$1" = "supervisord" ]; then serving=true; fi

if [ "$serving" = "true" ] && [ -z "${APP_KEY:-}" ]; then
    echo "[paa] APP_KEY is not set. Generate one with: php artisan key:generate --show   and set it as an environment variable." >&2
    exit 1
fi

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

if [ "${AUTO_CREATE_DB:-false}" = "true" ] && [ "$serving" = "true" ]; then
    php -r 'try { $p = new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); $p->exec("CREATE DATABASE IF NOT EXISTS `".str_replace("`", "", getenv("DB_DATABASE"))."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e) { fwrite(STDERR, "[paa] could not create the database: ".$e->getMessage().PHP_EOL); exit(1); }'
fi

# the storage volume may be new and empty
mkdir -p storage/app/public storage/app/backups storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

artisan() { su-exec www-data php artisan "$@"; }

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[paa] migrating the database"
    artisan migrate --force
fi

if [ "${SEED_IF_EMPTY:-false}" = "true" ] && [ "$serving" = "true" ]; then
    users=$(php -r 'try { $p = new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo (int) $p->query("select count(*) from users")->fetchColumn(); } catch (Throwable $e) { echo -1; }')
    if [ "$users" = "0" ]; then
        echo "[paa] no user yet: running the base seeder (roles, categories, settings, first administrator)"
        artisan db:seed --force
    fi
fi

# configuration, routes and views are cached: faster, and a mistake in the environment shows up at once
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache

if [ "$serving" = "true" ]; then
    exec "$@"   # php-fpm / supervisord run as root; their workers and the queue run as www-data
fi
exec su-exec www-data "$@"
