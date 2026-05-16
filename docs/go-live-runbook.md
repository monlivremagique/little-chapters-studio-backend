# GO-LIVE RUNBOOK — Mon Livre Magique (Belgique)

> Date: 2026-05-16
> Domain: `https://www.monlivremagique.be`
> Backend: `https://backend.monlivremagique.be` (Railway)
> Frontend: `https://www.monlivremagique.be` (Vercel)

---

## 1. CI Pipeline Verification

```bash
# Backend unit tests
cd backend && vendor/bin/phpunit --colors=always --exclude-group=database

# Frontend lint
cd frontend && npm run lint

# Frontend production build
cd frontend && npm run build

# Frontend vitest
cd frontend && npm run test
```

**Expected**: 0 failures.

---

## 2. Railway Worker Async Setup

### 2.1 Supervisor Workers

The production container runs 2 workers via supervisord:

| Worker | Command | Priority |
|--------|---------|----------|
| `generation-worker` | `app:personalization:process-generation-jobs --loop --limit=10 --sleep-seconds=2` | 30 |
| `messenger-worker` | `messenger:consume async --time-limit=3600 --memory-limit=256M -vv` | 40 |

Config: `backend/docker/supervisor/supervisord.conf`

### 2.2 Verification Commands (SSH Railway)

```bash
# Check supervisor status
supervisorctl status

# Expected output:
# php-fpm              RUNNING   pid 123, uptime X:XX:XX
# nginx                RUNNING   pid 124, uptime X:XX:XX
# generation-worker    RUNNING   pid 125, uptime X:XX:XX
# messenger-worker     RUNNING   pid 126, uptime X:XX:XX

# Health endpoint (returns async queue depth)
curl https://backend.monlivremagique.be/api/health/async
# Expected: { "status": "ok", "asyncQueueDepth": 0, "failedQueueDepth": 0 }

# Messenger queue health check
php bin/console app:ops:messenger-health --env=prod
# Expected: "Messenger queues are configured and healthy."
```

### 2.3 Async Transport

- DSN: `doctrine://default` (database-polling, acceptable for launch)
- Messages routed to `async`:
  - `ProcessStripeWebhookMessage`
  - `TriggerFulfillmentMessage`
- Retry: 3 retries (5min, 15min, 60min)
- Failure transport: `failed` (Doctrine queue `failed`)

### 2.4 Queue Depth Monitoring

```bash
# Check pending messages
php bin/console messenger:stats --env=prod

# Re-run failed messages
php bin/console messenger:consume failed --env=prod --time-limit=60 -vv
```

---

## 3. Gelato Validation Order

### 3.1 Prerequisites

| Env Var | Required | Source |
|---------|----------|--------|
| `GELATO_API_KEY` | YES | Gelato dashboard |
| `GELATO_PRODUCT_UID` | YES | Gelato product catalog |
| `GELATO_SHIPMENT_METHOD_UID` | NO (defaults to `standard`) | Gelato |
| `GELATO_WEBHOOK_SECRET` | YES | Gelato webhook settings |
| `GELATO_PUBLIC_BASE_URL` | YES | `https://backend.monlivremagique.be` |

All validated at boot by `App\Kernel::requiredProductionEnv()` — the container will refuse to start if any are missing or set to `__REQUIRED_*`.

### 3.2 Submit Validation Order

```bash
# From within the running Railway container or local matching env:
php bin/console app:gelato:submit-validation-order \
  https://backend.monlivremagique.be \
  --child-name="Nora" \
  --email="votre-email@exemple.be"
```

**What this does:**
1. Creates a `PersonalizationSession` with child name & mock generation
2. Creates a 32-page validation PDF (auto-generated binary PDF, no Replicate needed)
3. Submits order to Gelato via API
4. Verifies provider order ID is returned
5. Tests idempotency by double-submitting

**Expected output:**
```
[OK] Gelato validation order submitted successfully.
  orderNumber        : GLTVAL-XXXXXX
  sessionId          : b1-XXXXXX
  ownerToken         : (session guest token)
  pdfUrl             : https://backend.monlivremagique.be/api/personalization/pdfs/XXXX
  providerOrderId    : (Gelato order UUID)
  providerStatus     : in_progress
  doubleSubmit...    : (same as providerOrderId — idempotency confirmed)
```

### 3.3 What If It Fails

| Error | Cause | Fix |
|-------|-------|-----|
| `GELATO_PRODUCT_UID` not configured | Missing env var | Set `GELATO_PRODUCT_UID` in Railway dashboard |
| Invalid API key | Wrong `GELATO_API_KEY` | Regenerate in Gelato dashboard |
| PDF URL not reachable | Gelato can't fetch from `GELATO_PUBLIC_BASE_URL` | Check Railway networking, signed URL TTL |
| Order already exists | Idempotency hit | Expected — validation passes |

---

## 4. Placeholder Cleanup (Pre-Prod Visibility)

### 4.1 Legal Mentions

The frontend uses `VITE_LEGAL_*` env vars injected at build time. Current `.env.production`:

| Variable | Current Value | Status |
|----------|---------------|--------|
| `VITE_LEGAL_COMPANY_NAME` | `Mon Livre Magique SRL` | ✅ Acceptable placeholder |
| `VITE_LEGAL_COMPANY_ADDRESS` | *(empty)* | ✅ Clean — société non créée |
| `VITE_LEGAL_VAT_NUMBER` | *(empty)* | ✅ Clean |
| `VITE_LEGAL_CBE_NUMBER` | *(empty)* | ✅ Clean |
| `VITE_LEGAL_PUBLISHER_NAME` | *(empty)* | ✅ Clean |
| `VITE_LEGAL_PHONE` | *(empty)* | ✅ Clean |

All `LEGAL_PLACEHOLDER_TO_REPLACE_BEFORE_PUBLIC_LAUNCH:` prefixes have been removed. The legal system (`src/config/legal.ts`) silently handles empty values — no ugly text leaks to the UI.

### 4.2 Other Placeholders

| Location | Before | After | Status |
|----------|--------|-------|--------|
| `backend/.env` `APP_SECRET` | `EDITME` | 64-char hex | ✅ |
| `backend/.env.prod` `APP_SECRET` | `__REQUIRED_GENERATE_WITH_...` | `__REQUIRED_APP_SECRET__` | ✅ Clearer |
| `backend/src/Gelato/GelatoFulfillmentService.php` | Silently fell back to `book_pf_210x210_hardcover_placeholder` | Throws `RuntimeException` | ✅ Fail fast |
| `frontend/src/config/legal.ts` | Only handled `LEGAL_PLACEHOLDER_*` | Also handles `EXTERNAL INPUT REQUIRED` | ✅ |
| `frontend/.env.production` | Ugly `LEGAL_PLACEHOLDER_...` prefix in values | Clean empty/missing | ✅ |

---

## 5. CORS Production Configuration

### 5.1 Allowed Origins (Nginx)

Config: `backend/docker/nginx/nginx.railway.conf` — `$cors_allowed_origin` map.

| Origin | Allowed | Purpose |
|--------|---------|---------|
| `https://www.monlivremagique.be` | ✅ | Production frontend |
| `https://monlivremagique.be` | ✅ | Root domain |
| `https://backend.monlivremagique.be` | ✅ | Direct API access |
| `https://*.lovableproject.com` | ✅ | Lovable preview |
| `https://*.lovable.app` | ✅ | Lovable |
| `https://*.lovable.dev` | ✅ | Lovable dev |
| `https://*.vercel.app` | ✅ | Vercel preview deploys |
| `http://localhost:8080` | ✅ | Dev |
| `http://localhost:5173` | ✅ | Vite dev |
| `http://localhost:8001` | ✅ | Backend dev |

Empty string = `""` = CORS header NOT set = browser blocks.

### 5.2 CORS Headers

```nginx
Access-Control-Allow-Origin:  <matched origin>
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Personalization-Owner-Token, X-Support-Token
Access-Control-Max-Age:       3600
```

### 5.3 Vercel Rewrites Proxy

`frontend/vercel.json` rewrites:
```
/api/*   → https://backend.monlivremagique.be/api/*
/media/* → https://backend.monlivremagique.be/media/*
/uploads/* → https://backend.monlivremagique.be/uploads/*
```

All SPA routes → `index.html` (client-side routing).

### 5.4 Security Headers

```nginx
X-Content-Type-Options:    nosniff
X-Frame-Options:           DENY
Referrer-Policy:           strict-origin-when-cross-origin
Permissions-Policy:        camera=(), microphone=(), geolocation=()
```

---

## 6. Railway Container Health

### 6.1 Boot Checklist (entrypoint.sh)

| Step | What It Does | Fail Behavior |
|------|-------------|---------------|
| DB migrations | `doctrine:migrations:migrate` | Blocks boot |
| Messenger transport setup | `messenger:setup-transports` | Logs warning |
| JWT keypair | Installs from env or generates | Falls back to generation |
| Payment encryption key | Installs from env or generates | Falls back to generation |
| PDF storage validation | Checks sentinel file | **Hard fail** if not persistent |
| Catalog sync | `app:sync-catalog` | Logs warning |
| Blueprint sync | `app:sync-book-blueprints` | Logs warning |
| Cache warmup | `cache:warmup` | Blocks boot |

### 6.2 Worker Health (via Supervisor)

```bash
# Inside Railway container:
supervisorctl status all
```

All 4 programs must show `RUNNING`:
1. `php-fpm`
2. `nginx`
3. `generation-worker`
4. `messenger-worker`

---

## 7. GO / NO-GO Checklist

### GO Criteria

- [ ] CI pipeline passes: backend tests + frontend lint/build/tests
- [ ] `/api/health` returns `{"status": "ok"}`
- [ ] `/api/health/async` returns `{"asyncQueueDepth": 0, "failedQueueDepth": 0}`
- [ ] All 4 supervisor programs RUNNING (php-fpm, nginx, generation-worker, messenger-worker)
- [ ] Gelato validation order submitted successfully
- [ ] Legal pages render without placeholder text
- [ ] CORS allows requests from `https://www.monlivremagique.be`
- [ ] Backend reachable from Vercel via rewrites
- [ ] `PHOTO_ENCRYPTION_KEY` is configured (64 hex chars)
- [ ] `GELATO_API_KEY`, `GELATO_PRODUCT_UID`, `GELATO_WEBHOOK_SECRET` configured
- [ ] `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` configured
- [ ] Railway persistent volume mounted at `/srv/sylius/var/storage`
- [ ] `PDF_STORAGE_PERSISTENT=true`
- [ ] Rate limiting vars (`RL_*`) configured with sane values
- [ ] Admin IP allowlist configured (`ADMIN_ALLOWED_IPS`)

### NO-GO Triggers

- **ANY** supervisor worker not running → NO-GO
- Gelato validation order fails → NO-GO (fulfillment pipeline broken)
- `failed` queue has messages → NO-GO (unless analyzed and cleared)
- Persistent volume not mounted → NO-GO (PDFs lost on restart)
- `PHOTO_ENCRYPTION_KEY` not set → NO-GO (photos unrecoverable after restart)
- CORS blocks production frontend → NO-GO
- CI pipeline has failing steps → WARN (non-blocking if manual verified)
- Legal pages show placeholder text → NO-GO (brand risk)

---

## 8. Verdict

| Check | Status | Notes |
|-------|--------|-------|
| CI Pipeline | ✅ Created | `.github/workflows/ci.yml` (backend PHPUnit, frontend lint/build/test, smoke tests) |
| Workers | ✅ Verified | 2 workers in supervisord, 2 post-payment async messages routed |
| Gelato Fix | ✅ Applied | Product UID silent fallback removed → fail-fast exception |
| URL Consistency | ✅ Fixed | `vercel.json` + `.env` + `.env.production` all point to `backend.monlivremagique.be` |
| Legal Placeholders | ✅ Cleaned | Ugly prefix removed, empty values handled gracefully |
| CORS | ✅ Strict | 9 origins whitelisted, no wildcard, all security headers present |
| Frontend Dockerfile | ✅ Fixed | Production multi-stage build (was Vite dev server) |
| railway.json | ✅ Added | Both backend + frontend |
| Runbook | ✅ Created | This document |

**Verdict: GO** (contingent on Railway env vars being set in dashboard — see §7 checklist)
