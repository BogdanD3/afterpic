# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: build the React frontend.
# VITE_API_URL is left empty, so the browser calls /api on the same origin.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2: PHP 8.4 + Nginx + Supervisor (API, queue worker and scheduler)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor curl unzip \
        libjpeg-dev libpng-dev libwebp-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd exif pdo_mysql intl zip mbstring opcache pcntl \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so this layer is cached until composer files change.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --from=frontend /frontend/dist /var/www/frontend

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-photo-gallery.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-photo-gallery.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
