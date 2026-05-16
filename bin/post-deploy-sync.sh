#!/bin/sh
# Post-deploy sync: run after Railway deploy to sync new book blueprints
# This script is idempotent — safe to run on every deploy.

set -e

# Only run in production
if [ "$APP_ENV" != "prod" ]; then
    echo "[post-deploy-sync] Skipping: APP_ENV=$APP_ENV (not prod)"
    exit 0
fi

# Wait for database to be ready
echo "[post-deploy-sync] Waiting for database..."
php bin/console doctrine:query:sql "SELECT 1" --quiet 2>/dev/null || {
    echo "[post-deploy-sync] Database not ready yet — skipping"
    exit 0
}

# Sync blueprints
echo "[post-deploy-sync] Syncing book blueprints..."
php bin/console app:sync-book-blueprints --env=prod --no-interaction 2>&1 || {
    echo "[post-deploy-sync] Sync completed with warnings (non-fatal)"
}

# Backfill locales
echo "[post-deploy-sync] Backfilling locales..."
php bin/console app:backfill-catalog-locales --env=prod --no-interaction 2>&1 || true

# Diagnose catalog
echo "[post-deploy-sync] Diagnosing catalog..."
php bin/console app:diagnose-catalog-locales --env=prod --no-interaction 2>&1 || true

echo "[post-deploy-sync] Post-deploy sync complete"
