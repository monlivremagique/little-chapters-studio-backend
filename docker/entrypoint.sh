#!/bin/sh
set -e

cd /srv/sylius

echo "[entrypoint] Creating required directories..."
mkdir -p \
    var/cache/prod \
    var/log \
    var/storage/personalizations/photos \
    var/storage/personalizations/pdfs \
    var/share \
    public/media/cache \
    public/uploads/books \
    public/uploads/personalizations/pdfs \
    public/uploads/personalizations/previews \
    config/jwt

chown -R www-data:www-data \
    var/ \
    public/media/ \
    public/uploads/ \
    config/jwt/ 2>/dev/null || true

echo "[entrypoint] Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

echo "[entrypoint] Generating JWT keypair (skips if already exists)..."
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction --env=prod

echo "[entrypoint] Syncing book blueprints..."
php bin/console app:sync-book-blueprints --no-interaction --env=prod

echo "[entrypoint] Warming up cache..."
php bin/console cache:warmup --env=prod

echo "[entrypoint] Starting services (PHP-FPM + Nginx + worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
