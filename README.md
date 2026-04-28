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
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `FRONTEND_BASE_URL=http://localhost:8080`

Important :

- ne commitez pas un vrai token Replicate dans le dépôt
- la génération preview réelle nécessite `REPLICATE_API_TOKEN`
- le checkout Stripe nécessite `STRIPE_SECRET_KEY`
- la validation webhook Stripe nécessite `STRIPE_WEBHOOK_SECRET`

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
- `GET /api/personalization/photos/{photoId}?token=...`
- `DELETE /api/personalization/sessions/{id}/photo`
- `POST /api/personalization/sessions/{id}/generation-requests`
- `GET /api/personalization/sessions/{id}/generation-status`
- `GET /api/personalization/sessions/{id}/preview`
- `POST /api/personalization/sessions/{id}/approve`
- `POST /api/personalization/sessions/{id}/attach-to-cart`

Règle d’accès:

- les routes de session personnalisée exigent soit le bearer token du client propriétaire, soit le header invité `X-Personalization-Owner-Token`
- le backend renvoie ce `ownerToken` à la création de session invitée
- les routes `/api/custom/orders/{orderNumber}/sessions` appliquent la même règle

### Compte client / commerce

- `POST /api/v2/shop/customers/register`
- `POST /api/v2/shop/customers/token`
- `GET /api/v2/shop/account/me`
- endpoints Sylius `orders`, `shipments`, `payments`, `complete`
- `POST /api/custom/payments/stripe/checkout-sessions`
- `GET /api/custom/payments/stripe/checkout-sessions/{providerSessionId}`
- `POST /api/custom/payments/stripe/webhook`

## Stripe local test mode

Pré-requis:

- renseigner `STRIPE_SECRET_KEY` avec une clé Stripe **test**
- renseigner `STRIPE_WEBHOOK_SECRET` avec le secret du webhook local
- laisser `FRONTEND_BASE_URL=http://localhost:8080` si le front tourne sur le port local par défaut

Webhook local recommandé avec Stripe CLI:

```bash
stripe listen --forward-to http://localhost:8001/api/custom/payments/stripe/webhook
```

Le secret affiché par Stripe CLI doit être copié dans `STRIPE_WEBHOOK_SECRET`.

Points de contrôle:

- le checkout front redirige vers une page Stripe Checkout hébergée
- le webhook `checkout.session.completed` met le paiement Sylius à `completed`
- la page de confirmation front relit `stripe_session_id` et reflète l’état réel du paiement

Limite connue:

- sans clés Stripe test valides, le flow local ne peut pas être validé de bout en bout

## Commandes qualité / diagnostic

```bash
cd /home/labid/little-chapters-studio-backend
docker compose ps
docker compose logs --tail=100 php nginx frontend
docker compose exec php php bin/console about
docker compose exec php php bin/console debug:router
docker compose exec php php bin/console doctrine:migrations:status
docker compose exec php php bin/console app:cleanup-personalization-photos --deleted-grace-days=7
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
- Le checkout Stripe local exige des secrets Stripe test valides.
- Le fulfillment Gelato réel reste dépendant d’identifiants provider valides et d’un webhook public HTTPS.
- Le front est démarré via le `docker compose` du backend, pas via un compose séparé.

## Politique locale de stockage photo enfant

- Les uploads enfant acceptés sont limités à `JPG`, `PNG`, `WEBP`.
- Taille maximale: `10 MB`.
- Dimensions minimales: `256x256`.
- Dimensions maximales: `4096x4096`.
- Les binaires uploadés sont stockés dans `var/storage/personalizations/photos`, pas dans `public/`.
- L’accès HTTP à une photo passe par un endpoint contrôlé avec token d’accès photo.
- Le `DELETE /api/personalization/sessions/{id}/photo` supprime le binaire et marque l’enregistrement comme supprimé.
- Un nouvel upload remplace l’ancien upload actif et marque l’ancien en suppression logique.
- La purge finale des enregistrements supprimés se fait via:

```bash
cd /home/labid/little-chapters-studio-backend
docker compose exec php php bin/console app:cleanup-personalization-photos --deleted-grace-days=7
```

## Documentation locale complémentaire

- [README.local.md](README.local.md)
