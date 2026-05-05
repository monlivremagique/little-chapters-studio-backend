# GO_PROD_CHECKLIST — Mise en production

Checklist à valider avant chaque déploiement en production.

---

## Infrastructure

- [ ] PHP 8.3+ disponible sur le serveur / container
- [ ] PostgreSQL 16 accessible depuis l'application
- [ ] Nginx configuré et pointant sur `public/`
- [ ] HTTPS activé (obligatoire pour les webhooks Gelato et Stripe)
- [ ] Domaine production configuré (ex : `app.monlivremagique.be`)
- [ ] Frontend buildé et servi depuis un CDN ou serveur statique (ou Vite en mode preview)

---

## Variables d'environnement de production

Toutes les variables suivantes doivent être définies en production (via secrets manager, `.env.local`, Upsun env, etc.).

### Symfony core

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<32-char random string>
DEFAULT_URI=https://api.monlivremagique.be
PHP_DATE_TIMEZONE=Europe/Brussels
```

### Base de données

```dotenv
DATABASE_URL=pgsql://USER:PASSWORD@HOST:5432/DATABASE?serverVersion=16&charset=utf8
```

### Mailer

```dotenv
MAILER_DSN=smtp://user:password@smtp.provider.com:587
```

### Frontend

```dotenv
FRONTEND_BASE_URL=https://app.monlivremagique.be
```

### JWT

```dotenv
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=<strong passphrase>
```

### Replicate (génération IA)

```dotenv
REPLICATE_API_BASE_URI=https://api.replicate.com/v1
REPLICATE_API_TOKEN=r8_<production token>
REPLICATE_MODEL=black-forest-labs/flux-2-pro
REPLICATE_MAX_RETRIES=2
PERSONALIZATION_GENERATION_PAGE_LIMIT=4
```

### Stripe (PRODUCTION — clés live)

```dotenv
STRIPE_SECRET_KEY=sk_live_<production key>
STRIPE_WEBHOOK_SECRET=whsec_<webhook signing secret from Stripe dashboard>
```

### Gelato

```dotenv
GELATO_API_BASE_URI=https://order.gelatoapis.com
GELATO_API_KEY=<production API key>
GELATO_PRODUCT_UID=photobooks-hardcover_pf_200x200-mm-8x8-inch_pt_170-gsm-65lb-coated-silk_cl_4-4_ccl_4-4_bt_glued-left_ct_matt-lamination_prt_1-0_cpt_130-gsm-65-lb-cover-coated-silk_ver
GELATO_SHIPMENT_METHOD_UID=standard
GELATO_WEBHOOK_SECRET=<strong random string>
GELATO_PUBLIC_BASE_URL=https://api.monlivremagique.be
```

### Support

```dotenv
SUPPORT_OPERATIONS_TOKEN=<strong random token>
```

### Alertes

```dotenv
ALERT_EMAIL_TO=ops@monlivremagique.be
ALERT_EMAIL_FROM=noreply@monlivremagique.be
ALERT_WEBHOOK_URL=https://hooks.slack.com/services/<...>
```

---

## Stripe — Configuration production

- [ ] Créer un compte Stripe production (ou passer du mode test au mode live)
- [ ] Copier la clé secrète **live** (`sk_live_...`) dans `STRIPE_SECRET_KEY`
- [ ] Dans le dashboard Stripe → **Webhooks → Ajouter un endpoint** :
  - URL : `https://api.monlivremagique.be/api/custom/payments/stripe/webhook`
  - Événements à écouter :
    - `checkout.session.completed`
    - `checkout.session.expired`
    - `payment_intent.payment_failed`
- [ ] Copier le `whsec_...` dans `STRIPE_WEBHOOK_SECRET`
- [ ] Vérifier que `FRONTEND_BASE_URL` correspond à l'URL frontend prod (pour les redirections Stripe)

---

## Gelato — Configuration production

- [ ] Récupérer la clé API Gelato production dans leur dashboard partenaire
- [ ] Configurer le webhook dans le dashboard Gelato :
  - URL : `https://api.monlivremagique.be/api/custom/fulfillment/gelato/webhook?secret=<GELATO_WEBHOOK_SECRET>`
  - L'URL doit être **HTTPS public**
- [ ] Vérifier le `GELATO_PRODUCT_UID` correspond au produit Gelato commandé (format livre 200×200mm hardcover)
- [ ] Passer un ordre de validation Gelato :
  ```bash
  php bin/console app:gelato:submit-validation-order https://api.monlivremagique.be
  ```

---

## JWT — Clés de signature

- [ ] Générer une paire de clés JWT dédiée production :
  ```bash
  php bin/console lexik:jwt:generate-keypair
  ```
- [ ] Vérifier que `config/jwt/private.pem` et `config/jwt/public.pem` sont présents et non commités
- [ ] `JWT_PASSPHRASE` est différente de la valeur locale

---

## Base de données

- [ ] Migrations appliquées :
  ```bash
  php bin/console doctrine:migrations:migrate --no-interaction
  ```
- [ ] **Ne pas** charger les fixtures `little_chapters_phase2` en production (elles écrasent les données)
- [ ] Blueprints synchronisés :
  ```bash
  php bin/console app:sync-book-blueprints --no-interaction
  ```
- [ ] Sauvegarde PostgreSQL planifiée (daily minimum)

---

## Worker de génération

- [ ] Le service `generation-worker` (ou équivalent) tourne en production
- [ ] Supervision configurée (systemd, supervisord, Docker restart policy `always`)
- [ ] Logs du worker accessibles
- [ ] Commande exacte :
  ```bash
  php bin/console app:personalization:process-generation-jobs \
    --loop --limit=10 --sleep-seconds=2 --max-runtime=31536000
  ```

---

## Frontend

- [ ] Build de production généré :
  ```bash
  cd /home/labid/little-chapters-studio
  npm run build
  ```
- [ ] Assets servis avec cache HTTP long (`Cache-Control: max-age=31536000, immutable`)
- [ ] `VITE_API_BASE_URL` pointe vers le bon backend (ou proxy configuré)
- [ ] Pas de `console.log` sensibles dans le build final

---

## Sécurité

- [ ] Mot de passe admin Sylius changé depuis le défaut du seed :
  ```bash
  php bin/console sylius:admin:change-password admin@example.com <strong-password>
  ```
- [ ] `SUPPORT_OPERATIONS_TOKEN` est un token fort (≥ 32 caractères aléatoires)
- [ ] `APP_SECRET` est unique et fort (32+ caractères)
- [ ] Accès à `/admin` restreint par IP ou authentification renforcée si possible
- [ ] CORS configuré pour n'autoriser que le domaine frontend
- [ ] Aucune clé API commité dans git (`git log --all -S "sk_live" -- .` doit retourner vide)

---

## Stockage fichiers

- [ ] `var/storage/personalizations/photos/` est sur un volume persistant
- [ ] `public/uploads/` est sur un volume persistant
- [ ] `public/media/` est sur un volume persistant
- [ ] Politique de rétention photos configurée (cron `app:cleanup-personalization-photos`)

---

## Crons de maintenance

À configurer en production (crontab ou équivalent) :

```cron
# Annuler les commandes non payées (quotidien)
0 2 * * * php /srv/sylius/bin/console sylius:cancel-unpaid-orders

# Supprimer les paniers expirés (quotidien)
0 3 * * * php /srv/sylius/bin/console sylius:remove-expired-carts

# Purger les photos supprimées (hebdomadaire)
0 4 * * 0 php /srv/sylius/bin/console app:cleanup-personalization-photos --deleted-grace-days=7
```

---

## Vérifications post-déploiement

```bash
# Health check
curl -s https://api.monlivremagique.be/api/health

# Catalogue
curl -s https://api.monlivremagique.be/api/books | python3 -m json.tool | head -50

# Frontend
curl -I https://app.monlivremagique.be/

# Enregistrement client
curl -X POST https://api.monlivremagique.be/api/v2/shop/customers/register \
  -H "Content-Type: application/json" \
  -d '{"email":"test-prod@example.com","password":"TestProd123!","firstName":"Test","lastName":"Prod"}'

# Flow complet manuel
# 1. Ouvrir le catalogue en prod
# 2. Personnaliser un livre
# 3. Payer avec une vraie carte (montant minimal)
# 4. Vérifier réception email commande
# 5. Vérifier webhook Stripe reçu (dashboard Stripe)
# 6. Vérifier soumission Gelato (dashboard Gelato)
```

---

## Monitoring post-lancement

- [ ] Alertes Slack/email opérationnelles (tester avec une soumission Gelato forcée)
- [ ] Dashboard Stripe : surveiller les taux d'échec paiement
- [ ] Logs PHP : pas d'erreurs `500` répétées
- [ ] Logs worker : les jobs passent bien `queued → completed`
- [ ] Temps moyen génération < 5 minutes (surveiller `app_personalization_generation_job.created_at` vs `updated_at`)
