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

# PHP dependencies (cached layer — rebuilds only when composer files change)
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Source code (vendor/ above is preserved — .dockerignore excludes it from COPY)
COPY . .

# Node deps + Webpack Encore production build
# npm needs vendor/ present for local file: references in package.json
RUN npm ci --no-audit --no-fund \
    && npm run build:prod

# Symfony assets (creates symlinks in public/bundles/)
RUN APP_ENV=prod php bin/console assets:install public --no-debug 2>/dev/null || true

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

# Copy built application (without node_modules — not needed at runtime)
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

# Persistent directories (overridden by Railway volumes for storage/media/jwt)
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

# Nginx config (FastCGI to localhost:9000 — no DNS resolver needed in single container)
COPY docker/nginx/nginx.railway.conf /etc/nginx/http.d/default.conf

# Supervisor manages: PHP-FPM + Nginx + generation worker
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Entrypoint: migrations, JWT keypair, blueprint sync, cache warmup → supervisord
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
