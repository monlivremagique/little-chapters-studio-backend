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

Le `docker compose` local est maintenant configuré pour supporter la recréation du conteneur `php`
sans devoir redémarrer `nginx` manuellement. Si `php` est recréé, `nginx` re-résout `php:9000`
automatiquement.

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
docker compose exec php php bin/console app:cleanup-personalization-photos --deleted-grace-days=7
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
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `GELATO_API_BASE_URI=https://order.gelatoapis.com`
- `GELATO_API_KEY`
- `GELATO_PRODUCT_UID`
- `GELATO_SHIPMENT_METHOD_UID=standard`
- `GELATO_WEBHOOK_SECRET`
- `SUPPORT_OPERATIONS_TOKEN`
- `FRONTEND_BASE_URL=http://localhost:8080`

Front Docker :

- `VITE_API_BASE_URL=/api`
- `VITE_BACKEND_PROXY_TARGET=http://nginx`

## 6bis. Stripe local test mode

Le checkout local passe maintenant par Stripe Checkout hébergé.

Variables à renseigner dans le backend :

- `STRIPE_SECRET_KEY=sk_test_...`
- `STRIPE_WEBHOOK_SECRET=whsec_...`
- `FRONTEND_BASE_URL=http://localhost:8080`

Webhook local recommandé :

```bash
stripe listen --forward-to http://localhost:8001/api/custom/payments/stripe/webhook
```

Le secret `whsec_...` affiché par Stripe CLI doit être recopié dans `.env.local`.

Vérification minimale Stripe :

```bash
curl -I http://localhost:8001/api/health
curl http://localhost:8001/api/books
```

Puis, côté navigateur :

1. ouvrir un livre
2. personnaliser et approuver
3. ajouter au panier
4. lancer le checkout
5. vérifier la redirection vers Stripe Checkout
6. finaliser le paiement test
7. revenir sur `/confirmation?stripe_session_id=...`

Sans secrets Stripe test valides, IMP-003 ne peut pas être validé à 100%.

## 7. Politique photo enfant locale

- formats autorisés: `jpg`, `jpeg`, `png`, `webp`
- taille max: `10 MB`
- dimensions min: `256x256`
- dimensions max: `4096x4096`
- stockage privé: `var/storage/personalizations/photos`
- plus aucun original enfant ne doit rester dans `public/uploads/personalizations`
- lecture via endpoint contrôlé:
  - `GET /api/personalization/photos/{photoId}?token=...`
- suppression utilisateur:
  - `DELETE /api/personalization/sessions/{id}/photo`
- remplacement:
  - un nouvel upload supprime logiquement l’ancien upload actif
- purge de rétention:

```bash
cd /home/labid/little-chapters-studio-backend
docker compose exec php php bin/console app:cleanup-personalization-photos --deleted-grace-days=7
```

## 8. Ownership des sessions de personnalisation

- les routes `/api/personalization/sessions/...` refusent toute session étrangère
- en mode invité, le backend renvoie un `ownerToken`
- le front renvoie ce token via `X-Personalization-Owner-Token`
- après connexion client, une session invitée peut être réclamée par le client si le bearer token et le `ownerToken` correspondent
- les routes `/api/custom/orders/{orderNumber}/sessions` appliquent la même règle d’appartenance

## 9. PDF, Gelato et support opérationnel

Après paiement Stripe confirmé, le backend déclenche automatiquement :

1. création d’une version figée de preview approuvée
2. génération PDF locale depuis cette version
3. création d’un artefact PDF avec hash SHA-256
4. tentative de soumission Gelato
5. journalisation persistée dans `app_operational_event`

Variables obligatoires pour soumission Gelato réelle :

- `GELATO_API_BASE_URI=https://order.gelatoapis.com`
- `GELATO_API_KEY`
- `GELATO_PRODUCT_UID`
- `GELATO_SHIPMENT_METHOD_UID`
- `GELATO_WEBHOOK_SECRET`
- `GELATO_PUBLIC_BASE_URL` optionnel si vous voulez figer une URL publique différente de `DEFAULT_URI`

Sans `GELATO_API_KEY`, la génération PDF reste testable localement mais la soumission fulfillment échoue proprement avec un statut exploitable.

Endpoints utiles :

```bash
curl http://localhost:8001/api/custom/orders/{orderNumber}/fulfillment
curl -H "X-Support-Token: local-support-token" http://localhost:8001/api/custom/support/orders/{orderNumber}/events
```

Webhook Gelato local :

```bash
curl -X POST http://localhost:8001/api/custom/fulfillment/gelato/webhook \
  -H 'Content-Type: application/json' \
  -H 'X-Gelato-Webhook-Secret: local-secret' \
  -d '{"orderReferenceId":"ORDER_NUMBER","status":"shipped"}'
```

Gelato exige une URL webhook publique HTTPS. En local, exposer le backend avec un tunnel type ngrok ou cloudflared, puis renseigner dans Gelato :

```text
https://<tunnel-public-https>/api/custom/fulfillment/gelato/webhook?secret=<GELATO_WEBHOOK_SECRET>
```

Commande de validation réelle Gelato :

```bash
docker compose exec -T php php bin/console app:gelato:submit-validation-order https://<tunnel-public-https>
```

## 10. Arrêt propre

```bash
cd /home/labid/little-chapters-studio-backend
docker compose down
```

Pour supprimer aussi les volumes PostgreSQL :

```bash
cd /home/labid/little-chapters-studio-backend
docker compose down -v
```
