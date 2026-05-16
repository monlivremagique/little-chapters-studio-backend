#!/bin/sh
set -e

cd /srv/sylius

if [ "${APP_ENV:-prod}" = "prod" ]; then
    echo "[entrypoint] Configuring PHP runtime to suppress deprecation noise in prod..."
    cat > /usr/local/etc/php/conf.d/zz-production-runtime.ini <<'EOF'
error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED
display_errors = Off
log_errors = On
EOF
fi

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

echo "[entrypoint] Ensuring Messenger transport tables exist..."
php bin/console messenger:setup-transports --no-interaction --env=prod || true

echo "[entrypoint] Installing JWT keypair..."
mkdir -p config/jwt
if [ -n "${JWT_SECRET_KEY_CONTENT}" ] && [ -n "${JWT_PUBLIC_KEY_CONTENT}" ]; then
    printf '%s' "${JWT_SECRET_KEY_CONTENT}" > config/jwt/private.pem
    printf '%s' "${JWT_PUBLIC_KEY_CONTENT}"  > config/jwt/public.pem
    chmod 600 config/jwt/private.pem
    chmod 644 config/jwt/public.pem
    echo "[entrypoint] JWT keypair installed from environment variables (stable across restarts)."
elif [ -f "config/jwt/private.pem" ] && [ -f "config/jwt/public.pem" ]; then
    echo "[entrypoint] JWT keypair already present on disk — skipping generation."
else
    php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction --env=prod
    echo "[entrypoint] JWT keypair generated (ephemeral — set JWT_SECRET_KEY_CONTENT and JWT_PUBLIC_KEY_CONTENT on Railway to persist across restarts)."
fi

echo "[entrypoint] Installing payment encryption key..."
mkdir -p config/encryption
if [ -n "${SYLIUS_PAYMENT_ENCRYPTION_KEY_CONTENT}" ]; then
    echo -n "${SYLIUS_PAYMENT_ENCRYPTION_KEY_CONTENT}" > config/encryption/prod.key
    chmod 600 config/encryption/prod.key
    echo "[entrypoint] Payment encryption key installed from env var."
elif [ ! -f "config/encryption/prod.key" ]; then
    php -r "echo bin2hex(random_bytes(32));" > config/encryption/prod.key
    chmod 600 config/encryption/prod.key
    echo "[entrypoint] Payment encryption key generated (ephemeral — set SYLIUS_PAYMENT_ENCRYPTION_KEY_CONTENT on Railway to persist)."
else
    echo "[entrypoint] Payment encryption key already present."
fi

echo "[entrypoint] Validating PDF storage persistence..."
PDF_STORAGE_DIR="var/storage/personalizations/pdfs"
SENTINEL_FILE="${PDF_STORAGE_DIR}/.volume_check"
if [ -f "${SENTINEL_FILE}" ]; then
    echo "[entrypoint] PDF storage is persistent (sentinel file found). Setting PDF_STORAGE_PERSISTENT=true."
    export PDF_STORAGE_PERSISTENT=true
elif [ "${PDF_STORAGE_PERSISTENT:-false}" = "true" ]; then
    # Operator claims persistent storage is configured — write sentinel and trust it.
    echo "${HOSTNAME:-container}-$(date -u +%s)" > "${SENTINEL_FILE}"
    echo "[entrypoint] PDF storage sentinel written. PDF_STORAGE_PERSISTENT=true confirmed."
else
    echo "[entrypoint] FATAL: PDF storage is ephemeral. PDFs written to var/storage/personalizations/pdfs/" \
         "will be lost on container restart, breaking Gelato fulfillment downloads."
    echo "[entrypoint] ACTION REQUIRED:"
    echo "[entrypoint]   1. Attach a persistent Railway volume mounted at /srv/sylius/var/storage"
    echo "[entrypoint]   2. Set PDF_STORAGE_PERSISTENT=true in Railway environment variables"
    echo "[entrypoint]   3. Redeploy"
    echo "[entrypoint] Refusing to start production without durable PDF storage."
    exit 1
fi

echo "[entrypoint] Checking if catalog needs seeding..."
CHANNEL_COUNT=$(php /entrypoint-setup.php count-channels 2>/dev/null || echo "0")
echo "[entrypoint] Found ${CHANNEL_COUNT} channel(s)"
echo "[entrypoint] Synchronizing catalog bootstrap..."
php bin/console app:sync-catalog --no-interaction --env=prod || echo "[entrypoint] Catalog sync skipped"

echo "[entrypoint] Syncing book blueprints..."
php bin/console app:sync-book-blueprints --no-interaction --env=prod || echo "[entrypoint] Blueprint sync skipped"

echo "[entrypoint] Updating channel hostname..."
php /entrypoint-setup.php hostname || echo "[entrypoint] Hostname update skipped"

echo "[entrypoint] Installing Symfony bundle assets..."
php bin/console assets:install public --no-debug --env=prod 2>/dev/null || true

echo "[entrypoint] Warming up cache..."
php bin/console cache:warmup --env=prod

echo "[entrypoint] Fixing cache/log permissions after warmup..."
chown -R www-data:www-data var/cache var/log 2>/dev/null || true

echo "[entrypoint] Generating admin access policy..."
ADMIN_GEO_FILE="/etc/nginx/http.d/admin-access.conf"
if [ -n "${ADMIN_ALLOWED_IPS}" ]; then
    printf '# Generated by entrypoint.sh — restrict admin to specific IPs.\ngeo $admin_allowed {\n    default 0;\n' > "${ADMIN_GEO_FILE}"
    OLD_IFS="${IFS}"
    IFS=','
    for IP in ${ADMIN_ALLOWED_IPS}; do
        TRIMMED="$(printf '%s' "${IP}" | tr -d ' ')"
        if [ -n "${TRIMMED}" ]; then
            printf '    %s 1;\n' "${TRIMMED}" >> "${ADMIN_GEO_FILE}"
        fi
    done
    IFS="${OLD_IFS}"
    printf '}\n' >> "${ADMIN_GEO_FILE}"
    echo "[entrypoint] Admin IP allowlist active: ${ADMIN_ALLOWED_IPS}"
else
    printf '# ADMIN_ALLOWED_IPS not set — admin accessible from all IPs (rate-limited only).\ngeo $admin_allowed {\n    default 1;\n}\n' > "${ADMIN_GEO_FILE}"
    echo "[entrypoint] WARNING: ADMIN_ALLOWED_IPS is not set. Admin panel is accessible from all IPs. Set ADMIN_ALLOWED_IPS=x.x.x.x,y.y.y.y on Railway to restrict access."
fi

echo "[entrypoint] Injecting PORT into Nginx config..."
sed -i "s/\${PORT:-80}/${PORT:-80}/" /etc/nginx/http.d/default.conf

echo "[entrypoint] Starting services (PHP-FPM + Nginx + worker)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
