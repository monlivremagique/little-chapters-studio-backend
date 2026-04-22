# Little Chapters Studio Backend

Backend Symfony + Sylius du projet Little Chapters Studio.
Il expose :

- l’admin e-commerce Sylius
- les APIs shop Sylius pour panier / checkout / compte client
- les endpoints custom de personnalisation
- le contrat catalogue adapté au front

Le frontend associé vit dans `/home/labid/little-chapters-studio`.

## Objet du projet

- administration catalogue et commerce via Sylius
- source de vérité produit via Sylius + attribut `book_blueprint_json`
- orchestration backend de la personnalisation
- génération et preview d’artefacts avant achat

## Stack

- PHP 8.3
- Symfony 7
- Sylius 2
- API Platform
- PostgreSQL 16
- Webpack Encore
- Docker Compose
- MailHog

## Prérequis

- Docker
- Docker Compose
- accès réseau sortant si vous utilisez la génération Replicate

Optionnel en local hors Docker :

- Node.js 22 pour des commandes JS ponctuelles
- Composer / PHP ne sont pas requis si vous utilisez la stack Docker prévue

## Variables d’environnement

Les variables locales principales sont dans `.env.local`.

Variables utiles :

- `APP_ENV=dev`
- `APP_SECRET`
- `DATABASE_URL`
- `DEFAULT_URI=http://localhost:8001`
- `PHP_DATE_TIMEZONE=Europe/Brussels`
- `MAILER_DSN=smtp://mailhog:1025`
- `REPLICATE_API_BASE_URI=https://api.replicate.com/v1`
- `REPLICATE_API_TOKEN`
- `REPLICATE_MODEL=black-forest-labs/flux-2-pro`
- `REPLICATE_MAX_RETRIES=2`

Important :

- ne commitez pas un vrai token Replicate dans le dépôt
- la génération preview réelle nécessite `REPLICATE_API_TOKEN`

## Architecture locale

Le `docker compose` principal démarre :

- `php`
- `postgres`
- `nginx`
- `assets`
- `mailhog`
- `frontend` via le projet `../little-chapters-studio`

Ports exposés :

- `8001` : backend HTTP
- `8080` : frontend Vite
- `8026` : MailHog
- `55432` : PostgreSQL

## Initialisation complète

```bash
cd /home/labid/little-chapters-studio-backend
docker build -t little-chapters-backend-php:phase1 -f docker/php/Dockerfile .
COMPOSE_BAKE=false docker compose up -d --build
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console sylius:fixtures:load little_chapters_phase2 --no-interaction
docker compose exec -T postgres psql -U sylius -d little_chapters_sylius < scripts/phase2-post-seed.sql
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction
docker compose exec php php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
```

Si la base n’est pas encore installée :

```bash
cd /home/labid/little-chapters-studio-backend
docker compose exec php php bin/console sylius:install --no-interaction
```

## Raccourci seed + blueprint

Le script suivant recharge les fixtures, applique le post-seed SQL et resynchronise les blueprints :

```bash
cd /home/labid/little-chapters-studio-backend
bash scripts/phase2-seed.sh
```

## Lancement backend seul

```bash
cd /home/labid/little-chapters-studio-backend
COMPOSE_BAKE=false docker compose up -d postgres php assets nginx mailhog
```

Accès backend :

- `http://localhost:8001`
- `http://localhost:8001/api/health`
- `http://localhost:8001/api/books`

## Lancement stack complète front + back

```bash
cd /home/labid/little-chapters-studio-backend
COMPOSE_BAKE=false docker compose up -d --build
```

Accès attendus :

- front : `http://localhost:8080`
- backend : `http://localhost:8001`
- MailHog : `http://localhost:8026`

## Endpoints utiles

### Santé

- `GET /api/health`

### Catalogue adapté front

- `GET /api/books`
- `GET /api/books/{slug}`
- `GET /api/collections`
- `GET /api/collections/{slug}`

### Personnalisation

- `POST /api/personalization/sessions`
- `GET /api/personalization/sessions/{id}`
- `PATCH /api/personalization/sessions/{id}`
- `POST /api/personalization/sessions/{id}/photo`
- `POST /api/personalization/sessions/{id}/generation-requests`
- `GET /api/personalization/sessions/{id}/generation-status`
- `GET /api/personalization/sessions/{id}/preview`
- `POST /api/personalization/sessions/{id}/approve`
- `POST /api/personalization/sessions/{id}/attach-to-cart`

### Compte client / commerce

- `POST /api/v2/shop/customers/register`
- `POST /api/v2/shop/customers/token`
- `GET /api/v2/shop/account/me`
- endpoints Sylius `orders`, `shipments`, `payments`, `complete`

## Commandes qualité / diagnostic

```bash
cd /home/labid/little-chapters-studio-backend
docker compose ps
docker compose logs --tail=100 php nginx frontend
docker compose exec php php bin/console about
docker compose exec php php bin/console debug:router
docker compose exec php php bin/console doctrine:migrations:status
docker compose exec php php -l src/Controller/PersonalizationSessionController.php
```

Assets backend :

```bash
cd /home/labid/little-chapters-studio-backend
docker compose run --rm assets sh -lc "npm run build"
```

## Vérifications locales minimales

```bash
curl -I http://localhost:8001/api/health
curl -I http://localhost:8001/api/books
curl -I http://localhost:8080/
curl -I http://localhost:8080/api/books
```

## Problèmes connus

- La génération preview réelle dépend d’un token Replicate valide.
- Le backend n’émet pas encore de PDF, de fulfillment Gelato ni de flux Stripe avancé dans ce dépôt.
- Le front est démarré via le `docker compose` du backend, pas via un compose séparé.

## Documentation locale complémentaire

- [README.local.md](README.local.md)
