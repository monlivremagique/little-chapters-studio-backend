# RUNBOOK — Little Chapters Studio Backend

Référence opérationnelle. Pour le setup local : voir [README.local.md](README.local.md).

---

## Services et ports

| Service             | Port  | Rôle                              |
|---------------------|-------|-----------------------------------|
| nginx               | 8001  | Reverse proxy → PHP-FPM           |
| php                 | 9000  | PHP 8.3 FPM (interne)             |
| postgres            | 55432 | PostgreSQL 16                     |
| frontend            | 8080  | Vite dev server (React)           |
| mailhog             | 8026  | SMTP test + UI email              |
| generation-worker   | —     | Worker Replicate (loop continu)   |

---

## Santé de la stack

```bash
# État des containers
docker compose ps

# Health check applicatif
curl -s http://localhost:8001/api/health

# Vérification catalogue
curl -s http://localhost:8001/api/books | python3 -m json.tool | head -30
```

---

## Logs

```bash
# Tous les services
docker compose logs --tail=100

# Services ciblés
docker compose logs --tail=200 php
docker compose logs --tail=100 nginx
docker compose logs --tail=100 generation-worker
docker compose logs -f php  # suivi temps réel
```

En production (Upsun/Platform.sh) :

```bash
platform log app --tail 200
platform log error --tail 100
```

---

## Worker génération Replicate

Le service `generation-worker` s'exécute en boucle dans Docker. Il traite les jobs `queued` → `processing` → `completed/failed`.

**Vérifier l'état du worker :**

```bash
docker compose logs --tail=50 generation-worker
```

**Redémarrer le worker :**

```bash
docker compose restart generation-worker
```

**Traitement manuel (si le worker est arrêté) :**

```bash
docker compose exec php php bin/console app:personalization:process-generation-jobs \
  --loop --limit=10 --sleep-seconds=2 --max-runtime=3600
```

**Job bloqué en `processing` :**
Si un job reste `processing` après 10 minutes, il est probablement orphelin. Le retenter via le support :

```bash
curl -X POST \
  -H "X-Support-Token: $SUPPORT_OPERATIONS_TOKEN" \
  "http://localhost:8001/api/custom/support/personalization/generation-jobs/<jobId>/retry"
```

---

## Endpoints support (token requis)

Toutes ces routes requièrent le header `X-Support-Token: <SUPPORT_OPERATIONS_TOKEN>`.

### Trace complète d'une commande

```bash
curl -H "X-Support-Token: $TOKEN" \
  "http://localhost:8001/api/custom/support/orders/{orderNumber}/trace"
```

Retourne : session de personnalisation, jobs de génération, paiement Stripe, soumission Gelato.

### Événements opérationnels d'une commande

```bash
curl -H "X-Support-Token: $TOKEN" \
  "http://localhost:8001/api/custom/support/orders/{orderNumber}/events"
```

### Jobs de génération échoués

```bash
curl -H "X-Support-Token: $TOKEN" \
  "http://localhost:8001/api/custom/support/personalization/generation-jobs?failedOnly=1"
```

### Relancer un job de génération

```bash
curl -X POST \
  -H "X-Support-Token: $TOKEN" \
  "http://localhost:8001/api/custom/support/personalization/generation-jobs/{jobId}/retry"
```

### Statut de fulfillment d'une commande

```bash
curl "http://localhost:8001/api/custom/orders/{orderNumber}/fulfillment"
```

---

## Debug courant

### Identifier pourquoi une commande est bloquée

1. Récupérer l'`orderNumber` depuis l'admin Sylius (`/admin/orders`)
2. Lire la trace complète :
   ```bash
   curl -H "X-Support-Token: $TOKEN" \
     "http://localhost:8001/api/custom/support/orders/{orderNumber}/trace"
   ```
3. Identifier le statut de la session (`status`) et du paiement Stripe (`payment_status`)
4. Vérifier les événements opérationnels pour les erreurs Gelato

### Vérifier qu'un webhook Stripe a bien été reçu

```bash
# Chercher dans les logs nginx un POST vers /stripe/webhook
docker compose logs nginx | grep "stripe/webhook"

# Ou chercher dans la table des événements (via psql)
docker compose exec postgres psql -U sylius -d little_chapters_sylius \
  -c "SELECT provider_event_id, provider_type, created_at FROM app_stripe_webhook_event ORDER BY created_at DESC LIMIT 10;"
```

### Vérifier les paiements Stripe

```bash
docker compose exec postgres psql -U sylius -d little_chapters_sylius \
  -c "SELECT provider_session_id, status, payment_status, sylius_order_number, created_at FROM app_stripe_checkout_session ORDER BY created_at DESC LIMIT 10;"
```

### Vérifier les soumissions Gelato

```bash
docker compose exec postgres psql -U sylius -d little_chapters_sylius \
  -c "SELECT sylius_order_number, status, provider_order_id, error_message, created_at FROM app_fulfillment_order ORDER BY created_at DESC LIMIT 10;"
```

### Sessions de personnalisation bloquées

```bash
docker compose exec postgres psql -U sylius -d little_chapters_sylius \
  -c "SELECT id, status, child_name, created_at FROM app_personalization_session WHERE status NOT IN ('cart_attached','checkout_completed','delivered') ORDER BY created_at DESC LIMIT 20;"
```

### Relancer les migrations

```bash
docker compose exec php php bin/console doctrine:migrations:status
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Opérations de maintenance

### Purge photos supprimées (> N jours)

```bash
docker compose exec php php bin/console app:cleanup-personalization-photos --deleted-grace-days=7
```

### Sync blueprints livres (après modification admin)

```bash
docker compose exec php php bin/console app:sync-book-blueprints --no-interaction
```

### Rebuild assets Sylius admin

```bash
docker compose run --rm assets sh -lc "npm run build"
```

### Vérification routes

```bash
docker compose exec php php bin/console debug:router | grep -E "api/(custom|personalization|books)"
```

---

## Pipeline post-paiement

Après `checkout.session.completed` reçu de Stripe :

1. Paiement Sylius mis à `completed`
2. Création `PreviewVersion` (snapshot figé de la preview approuvée)
3. Génération PDF (`app_pdf_artifact`)
4. Soumission Gelato → `app_fulfillment_order` avec `status=submitted`
5. Gelato envoie un webhook à la livraison → `status=shipped`
6. `app_operational_event` journalise chaque étape

Si Gelato échoue : `app_fulfillment_order.status=failed` + alerte via `ALERT_EMAIL_TO` / `ALERT_WEBHOOK_URL`.

### Vérifier le statut PDF

```bash
docker compose exec postgres psql -U sylius -d little_chapters_sylius \
  -c "SELECT id, session_id, status, public_path, created_at FROM app_pdf_artifact ORDER BY created_at DESC LIMIT 5;"
```

---

## Alertes critiques

Le backend envoie des alertes sur :
- Échec de soumission Gelato
- Échec de sync paiement Stripe
- Erreur critique inattendue dans le pipeline

Configuration dans `.env` ou `.env.local` :

```dotenv
ALERT_EMAIL_TO=ops@example.com
ALERT_EMAIL_FROM=noreply@example.com
ALERT_WEBHOOK_URL=https://hooks.slack.com/services/...
```

---

## Base de données directe (PostgreSQL)

```bash
# Shell psql
docker compose exec postgres psql -U sylius -d little_chapters_sylius

# Ou via port local
psql -h localhost -p 55432 -U sylius -d little_chapters_sylius
```

Tables clés :

| Table | Contenu |
|-------|---------|
| `app_personalization_session` | Sessions de personnalisation |
| `app_personalization_generation_job` | Jobs Replicate |
| `app_personalization_preview_artifact` | Pages générées |
| `app_stripe_checkout_session` | Sessions Stripe |
| `app_fulfillment_order` | Soumissions Gelato |
| `app_operational_event` | Journal des événements |
| `app_pdf_artifact` | PDFs générés |
| `sylius_order` | Commandes |
| `sylius_payment` | Paiements |
