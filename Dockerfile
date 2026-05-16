# ==========================================
# Stage 1: Build — PHP deps + Node assets
# ==========================================
FROM ghcr.io/sylius/sylius-php:8.3-alpine AS build

USER root

RUN apk add --no-cache \
    postgresql-dev \
    nodejs \
    npm \
    && docker-php-ext-install pgsql pdo_pgsql

WORKDIR /srv/sylius

# PHP dependencies (cached layer)
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Source code (vendor/ from above is preserved — .dockerignore excludes it from COPY)
COPY . .

# Node assets — needs vendor/ present for local file: refs in package.json
RUN npm ci --no-audit --no-fund \
    && npm run build:prod

# Symfony bundle assets (public/bundles/)
RUN APP_ENV=prod php bin/console assets:install public --no-debug 2>&1 || true

# ==========================================
# Stage 2: Runtime — PHP-FPM + Nginx + Supervisor
# ==========================================
FROM ghcr.io/sylius/sylius-php:8.3-alpine AS runtime

USER root

RUN apk add --no-cache \
    postgresql-dev \
    nginx \
    supervisor \
    && docker-php-ext-install pgsql pdo_pgsql

WORKDIR /srv/sylius

# Copy built application (no node_modules)
COPY --from=build /srv/sylius/bin ./bin
COPY --from=build /srv/sylius/config ./config
COPY --from=build /srv/sylius/migrations ./migrations
COPY --from=build /srv/sylius/public ./public
COPY --from=build /srv/sylius/resources ./resources
COPY --from=build /srv/sylius/scripts ./scripts
COPY --from=build /srv/sylius/src ./src
COPY --from=build /srv/sylius/templates ./templates
COPY --from=build /srv/sylius/translations ./translations
COPY --from=build /srv/sylius/vendor ./vendor
COPY --from=build /srv/sylius/.env ./.env
COPY --from=build /srv/sylius/composer.json ./
COPY --from=build /srv/sylius/composer.lock ./
COPY --from=build /srv/sylius/symfony.lock ./

# Persistent directories (overridden by Railway volumes)
RUN mkdir -p \
    var/cache/prod \
    var/log \
    var/storage/personalizations/photos \
    var/storage/personalizations/pdfs \
    var/share \
    public/media/cache \
    public/uploads/books \
    public/uploads/personalizations/pdfs \
    public/uploads/personalizations/previews \
    config/jwt \
    && chown -R www-data:www-data var/ public/ config/jwt/

# PHP-FPM must inherit Railway env vars (APP_ENV=prod etc.)
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

# Nginx config (FastCGI to localhost:9000)
COPY docker/nginx/nginx.railway.conf /etc/nginx/http.d/default.conf

# Supervisor manages: PHP-FPM + Nginx + generation worker
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Setup helper + entrypoint
COPY docker/setup.php /entrypoint-setup.php
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
