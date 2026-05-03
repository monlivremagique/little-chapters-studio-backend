# Stage 1: Install PHP dependencies
FROM composer:latest AS composer-stage
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

# Stage 2: Build frontend assets
FROM node:20-alpine AS assets-stage
WORKDIR /app
COPY --from=composer-stage /app /app
RUN yarn install --frozen-lockfile
RUN yarn build:prod

# Stage 3: Production runtime
FROM dunglas/frankenphp:php8.3-bookworm
WORKDIR /srv/sylius

RUN install-php-extensions pdo_pgsql intl gd opcache

COPY --from=composer-stage /app /srv/sylius
COPY --from=assets-stage /app/public/build /srv/sylius/public/build

RUN mkdir -p var/cache var/log public/media/cache var/storage/personalizations/photos \
    && chown -R www-data:www-data var public/media

EXPOSE ${PORT:-8080}

CMD ["frankenphp", "php-server", "--listen", "0.0.0.0:${PORT:-8080}", "--root", "/srv/sylius/public"]
