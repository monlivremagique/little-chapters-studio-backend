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

echo "[entrypoint] Checking if catalog needs seeding..."
CHANNEL_COUNT=$(php -r "
require 'vendor/autoload.php';
\$url = getenv('DATABASE_URL');
preg_match('#://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', \$url, \$m);
\$pdo = new PDO('pgsql:host='.\$m[3].';port='.\$m[4].';dbname='.\$m[5], \$m[1], \$m[2]);
echo \$pdo->query('SELECT COUNT(*) FROM sylius_channel')->fetchColumn();
" 2>/dev/null || echo "0")
echo "[entrypoint] Found $CHANNEL_COUNT channel(s)"
if [ "$CHANNEL_COUNT" = "0" ]; then
    echo "[entrypoint] No channels — loading fixtures (first-time setup)..."
    php bin/console sylius:fixtures:load little_chapters_phase2 --no-interaction --env=prod || echo "[entrypoint] Fixtures failed — check logs"

    echo "[entrypoint] Applying post-seed SQL patches (names, descriptions, prices)..."
    php -r "
require 'vendor/autoload.php';
\$url = getenv('DATABASE_URL');
preg_match('#://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', \$url, \$m);
\$pdo = new PDO('pgsql:host='.\$m[3].';port='.\$m[4].';dbname='.\$m[5], \$m[1], \$m[2]);
\$sql = file_get_contents('scripts/phase2-post-seed.sql');
foreach (array_filter(array_map('trim', explode(';', \$sql))) as \$stmt) {
    try { \$pdo->exec(\$stmt); } catch (Exception \$e) { echo 'SQL warning: '.\$e->getMessage().PHP_EOL; }
}
echo 'Post-seed SQL applied'.PHP_EOL;
" || echo "[entrypoint] Post-seed SQL failed — continuing"
fi

echo "[entrypoint] Syncing book blueprints..."
php bin/console app:sync-book-blueprints --no-interaction --env=prod || echo "[entrypoint] Blueprint sync skipped"

echo "[entrypoint] Updating Sylius channel hostname to match deployment URL..."
php -r "
require 'vendor/autoload.php';
\$url = getenv('DATABASE_URL');
preg_match('#://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', \$url, \$m);
\$pdo = new PDO('pgsql:host='.\$m[3].';port='.\$m[4].';dbname='.\$m[5], \$m[1], \$m[2]);
\$defaultUri = getenv('DEFAULT_URI') ?: 'http://localhost';
\$hostname = str_replace(['https://','http://'], '', \$defaultUri);
\$stmt = \$pdo->prepare('UPDATE sylius_channel SET hostname = ? WHERE code = ?');
\$stmt->execute([\$hostname, 'LITTLE_CHAPTERS_BE_FR']);
echo '[channel] hostname set to: '.\$hostname.PHP_EOL;
" || echo "[entrypoint] Channel hostname update skipped"

echo "[entrypoint] Configuring PHP-FPM to inherit env vars (clear_env = no)..."
# PHP-FPM by default clears env vars — without this, APP_ENV stays 'dev' from .env
# and Symfony loads dev bundles (DebugBundle) that aren't installed with --no-dev
echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

echo "[entrypoint] Warming up cache..."
php bin/console cache:warmup --env=prod

echo "[entrypoint] Starting services (PHP-FPM + Nginx + worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
