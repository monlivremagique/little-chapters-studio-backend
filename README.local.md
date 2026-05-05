# README.local — Développement local

## Prérequis

- Docker + Docker Compose
- Accès réseau sortant (Replicate, Stripe, Gelato)

---

## Premier démarrage

```bash
cd /home/labid/little-chapters-studio-backend

# 1. Build de l'image PHP
docker build -t little-chapters-backend-php:phase1 -f docker/php/Dockerfile .

# 2. Démarrage de la stack complète
COMPOSE_BAKE=false docker compose up -d --build

# 3. Dépendances PHP
docker compose exec php composer install

# 4. Migrations
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# 5. Fixtures (catalogue, clients, collections)
docker compose exec php php bin/console sylius:fixtures:load little_chapters_phase2 --no-interaction

# 6. Post-seed SQL (prix, noms, descriptions)
docker compose exec -T postgres psql -U sylius -d little_chapters_sylius < scripts/phase2-post-seed.sql

# 7. Blueprints JSON des livres
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction

# 8. Clés JWT
docker compose exec php php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
```

Raccourci (remplace les étapes 5 à 7) :

```bash
bash scripts/phase2-seed.sh
```

---

## Vérifications rapides

```bash
docker compose ps
curl -s http://localhost:8001/api/health
curl -s http://localhost:8001/api/books | head -c 200
curl -I http://localhost:8080/
```

---

## Démarrage quotidien

```bash
cd /home/labid/little-chapters-studio-backend
COMPOSE_BAKE=false docker compose up -d
```

Backend seul (sans frontend) :

```bash
COMPOSE_BAKE=false docker compose up -d postgres php assets nginx mailhog
```

Arrêt :

```bash
docker compose down
# Avec suppression des volumes PostgreSQL :
docker compose down -v
```

---

## Variables d'environnement (`.env.local`)

Créer `/home/labid/little-chapters-studio-backend/.env.local` avec les valeurs locales :

```dotenv
APP_ENV=dev
APP_SECRET=changeme_local_secret
DATABASE_URL=pgsql://sylius:sylius@postgres:5432/little_chapters_sylius?serverVersion=16&charset=utf8
DEFAULT_URI=http://localhost:8001
PHP_DATE_TIMEZONE=Europe/Brussels
MAILER_DSN=smtp://mailhog:1025
FRONTEND_BASE_URL=http://localhost:8080

# Génération IA Replicate
REPLICATE_API_BASE_URI=https://api.replicate.com/v1
REPLICATE_API_TOKEN=r8_...
REPLICATE_MODEL=black-forest-labs/flux-2-pro
REPLICATE_MAX_RETRIES=2
PERSONALIZATION_GENERATION_PAGE_LIMIT=4

# Stripe (utiliser des clés TEST en local)
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

# Gelato
GELATO_API_BASE_URI=https://order.gelatoapis.com
GELATO_API_KEY=...
GELATO_PRODUCT_UID=photobooks-hardcover_pf_200x200-mm-8x8-inch_pt_170-gsm-65lb-coated-silk_cl_4-4_ccl_4-4_bt_glued-left_ct_matt-lamination_prt_1-0_cpt_130-gsm-65-lb-cover-coated-silk_ver
GELATO_SHIPMENT_METHOD_UID=standard
GELATO_WEBHOOK_SECRET=local-gelato-secret

# Support (token arbitraire en local)
SUPPORT_OPERATIONS_TOKEN=local-support-token

# Alertes (optionnel)
# ALERT_EMAIL_TO=ops@example.com
# ALERT_EMAIL_FROM=noreply@example.com
# ALERT_WEBHOOK_URL=https://hooks.example.com/...
```

---

## Stripe local

Pré-requis : Stripe CLI installé.

```bash
stripe listen --forward-to http://localhost:8001/api/custom/payments/stripe/webhook
```

Copier le `whsec_...` affiché dans `STRIPE_WEBHOOK_SECRET` du `.env.local`.

Flow de validation :
1. Ouvrir un livre → personnaliser → approuver → panier → checkout
2. Vérifier la redirection vers Stripe Checkout hébergé
3. Payer avec la carte test `4242 4242 4242 4242`
4. Vérifier le retour sur `/confirmation?stripe_session_id=...`

---

## Gelato local

Gelato exige un webhook HTTPS public. En local, exposer via un tunnel :

```bash
# ngrok
ngrok http 8001
# ou cloudflared
cloudflared tunnel --url http://localhost:8001
```

Configurer dans Gelato (dashboard) :

```
https://<tunnel>/api/custom/fulfillment/gelato/webhook?secret=<GELATO_WEBHOOK_SECRET>
```

Test d'ordre de validation :

```bash
docker compose exec php php bin/console app:gelato:submit-validation-order https://<tunnel-public-https>
```

Webhook manuel local :

```bash
curl -X POST http://localhost:8001/api/custom/fulfillment/gelato/webhook \
  -H 'Content-Type: application/json' \
  -H 'X-Gelato-Webhook-Secret: local-gelato-secret' \
  -d '{"orderReferenceId":"ORDER_NUMBER","status":"shipped"}'
```

---

## Worker de génération Replicate

Le service `generation-worker` tourne automatiquement dans Docker.

Traitement manuel :

```bash
docker compose exec php php bin/console app:personalization:process-generation-jobs --loop --limit=10 --sleep-seconds=2 --max-runtime=60
```

Jobs échoués :

```bash
curl -H "X-Support-Token: local-support-token" \
  "http://localhost:8001/api/custom/support/personalization/generation-jobs?failedOnly=1"

curl -X POST -H "X-Support-Token: local-support-token" \
  "http://localhost:8001/api/custom/support/personalization/generation-jobs/<jobId>/retry"
```

---

## Commandes utiles

```bash
# Logs
docker compose logs --tail=100 php nginx frontend generation-worker

# Blueprint sync (après modification d'un fichier resources/book-blueprints/)
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction

# Purge photos supprimées (> 7 jours)
docker compose exec php php bin/console app:cleanup-personalization-photos --deleted-grace-days=7

# Migrations
docker compose exec php php bin/console doctrine:migrations:status
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# Debug routes
docker compose exec php php bin/console debug:router | grep api

# Shell PHP
docker compose exec php sh
```

Frontend :

```bash
cd /home/labid/little-chapters-studio
npm run lint
npm run test
npm run test:e2e -- tests/e2e/user-payment-flow.spec.ts
```

---

## Politique photo enfant

- Formats : `jpg`, `jpeg`, `png`, `webp`
- Taille max : 10 MB
- Dimensions : 256×256 min, 4096×4096 max
- Stockage privé : `var/storage/personalizations/photos/`
- Accès : `GET /api/personalization/photos/{photoId}?token=...`
- Suppression logique sur nouvel upload ou DELETE explicite
- Purge physique via la commande `app:cleanup-personalization-photos`

---

## Ownership des sessions

- Mode invité : le backend renvoie un `ownerToken` à la création de session
- Le front le renvoie via `X-Personalization-Owner-Token`
- Mode connecté : le bearer JWT suffit
- Les routes `/api/custom/orders/{orderNumber}/sessions` appliquent la même règle

---

## Reset complet

```bash
docker compose down -v
docker build -t little-chapters-backend-php:phase1 -f docker/php/Dockerfile .
COMPOSE_BAKE=false docker compose up -d --build
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
bash scripts/phase2-seed.sh
docker compose exec php php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
```
