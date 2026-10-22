# syntax=docker/dockerfile:1.7
#
# Production image of the Plano de Ação Anual (PAA).
# Targets: `production` (PHP-FPM: web requests, queue worker and scheduler all run from it) and `web` (nginx that serves public/).
# Build:  docker compose -f compose.prod.yml --env-file .env.production build

# ── 1. the compiled front-end (Vite + Tailwind)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ── 2. PHP with the extensions the application needs
FROM php:8.3-fpm-alpine AS base
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql bcmath gd intl zip pcntl exif \
    # the mysql client builds a new database from the schema baseline and mysqldump makes the nightly backup
    && apk add --no-cache mariadb-client su-exec tzdata \
    && ln -sf /usr/share/zoneinfo/Asia/Dili /etc/localtime
WORKDIR /var/www/html

# ── 3. the application and its PHP dependencies (no dev packages)
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction

# ── 4. the image that runs the application
FROM base AS production
ENV APP_ENV=production APP_DEBUG=false
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
# the mysql client (schema baseline) and mysqldump (backup) talk to the database over the private compose network: no TLS there
RUN mkdir -p /etc/my.cnf.d && echo '[client]' > /etc/my.cnf.d/paa.cnf && echo 'skip-ssl' >> /etc/my.cnf.d/paa.cnf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-paa.ini
COPY docker/entrypoint.sh /usr/local/bin/paa-entrypoint
RUN chmod +x /usr/local/bin/paa-entrypoint \
    && rm -f public/hot \
    && ln -sfn ../storage/app/public public/storage \
    && mkdir -p storage/app/public storage/app/backups storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 9000
ENTRYPOINT ["paa-entrypoint"]
CMD ["php-fpm"]

# ── 5. nginx: serves public/ and hands PHP to the application container
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public

# ── 6. everything in one container, for platforms that run one container per app (CapRover, Dokku, Railway).
# It must stay the LAST stage: a build without --target (CapRover) builds the last one.
FROM production AS caprover
RUN apk add --no-cache nginx supervisor \
    && rm -f /etc/nginx/http.d/default.conf \
    && mkdir -p /run/nginx
COPY docker/caprover/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/caprover/supervisord.conf /etc/supervisord.conf
EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
