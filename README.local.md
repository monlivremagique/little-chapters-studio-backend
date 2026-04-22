# Little Chapters Studio Local Runbook

Guide court pour relancer toute la stack locale.

## 1. Démarrage complet

```bash
cd /home/labid/little-chapters-studio-backend
docker build -t little-chapters-backend-php:phase1 -f docker/php/Dockerfile .
COMPOSE_BAKE=false docker compose up -d --build
```

## 2. Initialisation base et données

À faire au premier démarrage ou après reset de base :

```bash
cd /home/labid/little-chapters-studio-backend
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console sylius:fixtures:load little_chapters_phase2 --no-interaction
docker compose exec -T postgres psql -U sylius -d little_chapters_sylius < scripts/phase2-post-seed.sql
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction
docker compose exec php php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
```

Ou bien :

```bash
cd /home/labid/little-chapters-studio-backend
bash scripts/phase2-seed.sh
```

## 3. URLs à vérifier

- front : `http://localhost:8080`
- backend : `http://localhost:8001`
- santé backend : `http://localhost:8001/api/health`
- catalogue backend : `http://localhost:8001/api/books`
- catalogue via front : `http://localhost:8080/api/books`
- MailHog : `http://localhost:8026`

## 4. Vérifications rapides

```bash
cd /home/labid/little-chapters-studio-backend
docker compose ps
curl -I http://localhost:8001/api/health
curl -I http://localhost:8080/
curl -I http://localhost:8080/api/books
```

## 5. Commandes utiles

Logs :

```bash
cd /home/labid/little-chapters-studio-backend
docker compose logs --tail=100 frontend nginx php
```

Backend :

```bash
cd /home/labid/little-chapters-studio-backend
docker compose exec php php bin/console about
docker compose exec php php bin/console doctrine:migrations:status
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction
```

Front :

```bash
cd /home/labid/little-chapters-studio
npm run lint
npm run build
npm run test
npm run test:e2e -- tests/e2e/user-payment-flow.spec.ts
```

## 6. Variables locales importantes

Backend `.env.local` :

- `DATABASE_URL`
- `DEFAULT_URI=http://localhost:8001`
- `PHP_DATE_TIMEZONE=Europe/Brussels`
- `REPLICATE_API_TOKEN`
- `REPLICATE_MODEL=black-forest-labs/flux-2-pro`
- `REPLICATE_MAX_RETRIES=2`

Front Docker :

- `VITE_API_BASE_URL=/api`
- `VITE_BACKEND_PROXY_TARGET=http://nginx`

## 7. Arrêt propre

```bash
cd /home/labid/little-chapters-studio-backend
docker compose down
```

Pour supprimer aussi les volumes PostgreSQL :

```bash
cd /home/labid/little-chapters-studio-backend
docker compose down -v
```
