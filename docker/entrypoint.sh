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

echo "[entrypoint] Syncing book blueprints (skipped if catalog not yet seeded)..."
php bin/console app:sync-book-blueprints --no-interaction --env=prod || echo "[entrypoint] Blueprint sync skipped — run after: sylius:fixtures:load little_chapters_phase2"

echo "[entrypoint] Configuring PHP-FPM to inherit env vars (clear_env = no)..."
# PHP-FPM by default clears env vars — without this, APP_ENV stays 'dev' from .env
# and Symfony loads dev bundles (DebugBundle) that aren't installed with --no-dev
echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

echo "[entrypoint] Warming up cache..."
php bin/console cache:warmup --env=prod

echo "[entrypoint] Starting services (PHP-FPM + Nginx + worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
