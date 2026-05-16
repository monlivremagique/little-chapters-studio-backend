# README.local — Développement local

## Prérequis

- Docker + Docker Compose
- Node.js 20+ (pour le frontend)

---

## Premier démarrage

```bash
cd /home/labid/mon-livre-magique/backend

# 1. Build image PHP
docker build -t little-chapters-backend-php:phase1 -f docker/php/Dockerfile .

# 2. Démarrer la stack
COMPOSE_BAKE=false docker compose up -d --build

# 3. Seed complet (fixtures + migrations + sync blueprints)
bash scripts/phase2-seed.sh
```

Ou pas à pas :

```bash
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:sync-catalog --no-interaction
```

## URLs locales

| Service | URL |
|---|---|
| Backend API | http://localhost:8001 |
| Admin Sylius | http://localhost:8001/admin (sylius@example.com / sylius) |
| MailHog | http://localhost:8026 |
| PostgreSQL | localhost:55432 (sylius / sylius) |

Frontend (repo séparé) : http://localhost:8080

---

## Variables d'environnement

Copier `.env` vers `.env.local` et renseigner :

```dotenv
# Stripe (mode test local)
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

# Replicate (génération IA)
REPLICATE_API_TOKEN=r8_...

# Gelato (sandbox)
GELATO_API_KEY=...

# Mailer local (MailHog inclus dans Docker)
MAILER_DSN=smtp://localhost:1025

# JWT
JWT_PASSPHRASE=dev_passphrase_only
```

---

## Commandes utiles

```bash
# Logs
docker compose logs --tail=100 php nginx

# Console Symfony
docker compose exec php php bin/console <commande>

# Migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Synchroniser tout le catalogue depuis l'entree unique
docker compose exec php php bin/console app:sync-catalog

# Diagnostiquer uniquement l'etat multilingue du catalogue
docker compose exec php php bin/console app:diagnose-catalog-locales

# Worker génération IA (foreground pour debug)
docker compose exec php php bin/console app:personalization:process-generation-jobs --loop --limit=10

# Reset complet DB
docker compose exec php php bin/console doctrine:database:drop --force
docker compose exec php php bin/console doctrine:database:create
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console sylius:fixtures:load mlm_phase2 --no-interaction
```

---

## Catalogue multilingue (FR/EN/NL)

Les migrations suivantes doivent etre appliquees, puis rejouees apres fixtures via
`app:backfill-catalog-locales` pour garantir que les produits existent au moment des inserts :
- `Version20260506100000` — nl_NL locale + traductions taxons/produits
- `Version20260506110000` — attributs marketing multilingues
- `Version20260506120000` — reviews multilingues
- `Version20260506130000` — labels admin attributs
- `Version20260506150000` — blueprints EN/NL

Apres un bootstrap local propre, verifier :

```bash
docker compose exec php php bin/console app:diagnose-catalog-locales --no-interaction
```

Le diagnostic doit retourner un succes avant de valider l'environnement.

L'API accepte `?locale=fr|en|nl` sur `/api/books`, `/api/books/{slug}`, `/api/collections`, `/api/collections/{slug}`.

---

## Test Stripe local (webhook)

```bash
# Stripe CLI
stripe listen --forward-to localhost:8001/api/custom/payments/stripe/webhook
stripe trigger checkout.session.completed
```

---

## Structure des répertoires clés

```
src/
├── Controller/          # Endpoints custom (catalogue, paiement, personnalisation, fulfillment)
├── FrontCatalog/        # FrontCatalogProvider + FrontCatalogMetadata (catalogue adapté front)
├── Personalization/     # Moteur de personnalisation et génération IA
├── Stripe/              # Intégration Stripe Checkout
├── Gelato/              # Intégration Gelato fulfillment
├── Pdf/                 # Rendu PDF post-paiement (Dompdf)
├── Integration/Replicate/ # Client Replicate API
├── Support/             # CriticalAlertDispatcher, OperationalEventRecorder
└── Entity/              # Entités Doctrine (Product, Personalization, Order, Fulfillment)
```
