# RUNBOOK — Mon Livre Magique Backend

Opérations courantes, déploiement, monitoring, incidents.

---

## Déploiement production (Railway)

```bash
git push origin main
# → Build Railway automatique (~5 min)
# → Migrations auto → worker redémarre
```

**Projet :** `diplomatic-patience` · **Service :** `mon-livre-magique-backend`  
**API prod :** `https://backend.monlivremagique.be`

### Ce que le déploiement fait automatiquement (entrypoint.sh)

1. `doctrine:migrations:migrate --no-interaction`
2. `lexik:jwt:generate-keypair --skip-if-exists`
3. `app:sync-catalog`
4. Met à jour hostname du channel Sylius
5. Démarre nginx + PHP-FPM + worker IA (supervisord)

---

## Monitoring

```bash
BASE=https://backend.monlivremagique.be

# Santé API
curl $BASE/api/health

# Catalogue — doit retourner 3 livres
curl "$BASE/api/books" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d), 'livres:', [b['slug'] for b in d])"

# Vérification par livre × locale
for slug in forest-of-lost-stars ville-ecole espace-robot; do
  for locale in fr en nl; do
    status=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/books/$slug?locale=$locale")
    echo "$status $slug/$locale"
  done
done

# Diagnostic multilingue complet
railway ssh -- php bin/console app:diagnose-catalog-locales

# CORS depuis monlivremagique.be
curl -si -H "Origin: https://www.monlivremagique.be" "$BASE/api/books" | grep access-control-allow-origin
```

### Checklist post-deploy (3 livres attendus)

| Check | Commande | Attendu |
|---|---|---|
| Livres Sylius | `dbal:run-sql "SELECT code FROM sylius_product WHERE code LIKE 'BOOK_%'"` | 3 lignes |
| API catalog | `curl $BASE/api/books` | 3 objets JSON |
| Cover forest | `curl -I $BASE/uploads/books/forest-of-lost-stars/cover-generated.png` | 200 |
| Cover ville | `curl -I $BASE/uploads/books/ville-ecole/cover-generated.png` | 200 |
| Cover espace | `curl -I $BASE/uploads/books/espace-robot/cover-generated.png` | 200 |

---

## Worker génération IA

Tourne en continu via supervisord (`docker/supervisor/supervisord.conf`).

```bash
# Local : vérifier état
docker compose exec php supervisorctl status

# Local : forcer un cycle
docker compose exec php php bin/console app:personalization:process-generation-jobs --loop --limit=1

# Prod : logs Railway
railway logs -s mon-livre-magique-backend | grep -E "generation|replicate|error"
```

---

## Webhooks en production

| Webhook | Endpoint | Méthode |
|---|---|---|
| Stripe | `/api/custom/payments/stripe/webhook` | POST + HMAC signature |
| Gelato | `/api/custom/fulfillment/gelato/webhook?secret=SECRET` | GET |
| Alertes ops | `/api/custom/alerts/receive` | POST + X-Support-Token |

**Stripe → passer en LIVE :**
1. dashboard.stripe.com → Webhooks → nouveau endpoint URL prod
2. Railway vars : `STRIPE_SECRET_KEY=sk_live_...` + `STRIPE_WEBHOOK_SECRET=whsec_live_...`

---

## Debug support

```bash
TOKEN=$SUPPORT_OPERATIONS_TOKEN
BASE=https://backend.monlivremagique.be

# Jobs génération échoués
curl -H "X-Support-Token: $TOKEN" "$BASE/api/custom/support/personalization/generation-jobs?failedOnly=1"

# Trace commande complète
curl -H "X-Support-Token: $TOKEN" "$BASE/api/custom/support/orders/ORDER_NUMBER/trace"
```

---

## Variables Railway — état

| Variable | État | Action |
|---|---|---|
| `STRIPE_SECRET_KEY` | ⚠️ TEST | Passer `sk_live_...` avant premier client réel |
| `STRIPE_WEBHOOK_SECRET` | ✅ | Recréer en LIVE en même temps |
| `REPLICATE_API_TOKEN` | ✅ | — |
| `GELATO_API_KEY` | ✅ | — |
| `MAILER_DSN` | ✅ Brevo SMTP | — |
| `ALERT_EMAIL_TO` | ⚠️ | Vérifier que c'est une vraie boîte mail |
| `ALERT_EMAIL_FROM` | ⚠️ | Idem |

---

## Incidents courants

### Déploiement FAILED

Lire les logs Railway. Causes fréquentes :
- Migration SQL en erreur → vérifier `sylius_doctrine_migrations_versions`
- PHP Fatal → vérifier composer.lock + `.env` Railway

### Worker IA silencieux

```bash
# Vérifier jobs en attente
docker compose exec php php bin/console doctrine:query:sql \
  "SELECT id, status, created_at FROM app_personalization_generation_job WHERE status='pending' LIMIT 10"
```

### PDF non généré après paiement

Vérifier : webhook Stripe reçu → session `checkout_completed` → `pdf_rendering` → `print_ready`.  
Si bloqué : appeler `/api/custom/support/orders/ORDER_NUMBER/trace`.

---

## Maintenance périodique

```bash
# Purge photos (TODO: cron Railway à configurer)
php bin/console app:cleanup-personalization-photos --deleted-grace-days=7

# Resynchroniser tout le catalogue
php bin/console app:sync-catalog

# Contrôle d'intégrité multilingue du catalogue
php bin/console app:diagnose-catalog-locales
```
