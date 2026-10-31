# syntax=docker/dockerfile:1

FROM node:22.14.0-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.3.26-fpm-bookworm AS app
ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd zip bcmath intl opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.8.11 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/php/pqrsd.ini /usr/local/etc/php/conf.d/pqrsd.ini
RUN composer dump-autoload --optimize --no-interaction --no-scripts \
    && mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        storage/app/private \
        bootstrap/cache \
    && sed -i 's/\r$//' docker/entrypoint.sh \
    && chmod 0755 docker/entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php-fpm", "-F"]

FROM nginx:1.27.5-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
