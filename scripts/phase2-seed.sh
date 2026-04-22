#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT_DIR"

docker compose exec php php bin/console sylius:fixtures:load little_chapters_phase2 --no-interaction
docker compose exec -T postgres psql -U sylius -d little_chapters_sylius < "$ROOT_DIR/scripts/phase2-post-seed.sql"
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction
