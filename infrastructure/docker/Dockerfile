FROM node:24-alpine AS frontend
WORKDIR /source/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund --fetch-timeout=60000 --fetch-retries=2
COPY frontend/ ./
RUN npm run typecheck && npm run build

FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache icu-dev libzip-dev postgresql-dev sqlite-dev oniguruma-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring intl zip opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY backend/ ./
COPY --from=frontend /source/backend/public/app/ ./public/app/
COPY infrastructure/docker/php.ini /usr/local/etc/php/conf.d/eduquest.ini
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM nginx:stable-alpine AS web
COPY infrastructure/docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public/ /var/www/html/public/
EXPOSE 80
