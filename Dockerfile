# ==========================================
# Stage 1: Composer — PHP deps
# ==========================================
FROM composer:latest AS composer-stage

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions exif

WORKDIR /srv/sylius

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# ==========================================
# Stage 2: Yarn — Node assets
# ==========================================
FROM node:20-alpine AS yarn-stage

WORKDIR /srv/sylius

COPY package.json yarn.lock ./
COPY --from=composer-stage /srv/sylius/vendor ./vendor
RUN yarn install --frozen-lockfile

COPY . .
RUN yarn build:prod

# ==========================================
# Stage 3: Runtime — FrankenPHP
# ==========================================
FROM dunglas/frankenphp:latest-php8.3 AS runtime

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl gd opcache exif

WORKDIR /srv/sylius

# Copy built application
COPY --from=composer-stage /srv/sylius/vendor ./vendor
COPY --from=yarn-stage /srv/sylius/public ./public
COPY . .

# Persistent directories
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

EXPOSE ${PORT:-8080}

CMD frankenphp php-server --listen 0.0.0.0:${PORT:-8080} --root /srv/sylius/public
