# Mon Livre Magique — Backend

Backend de **Mon Livre Magique** (`www.monlivremagique.be`).  
Symfony 7 · Sylius 2 · API Platform · PHP 8.3 · PostgreSQL 16.

## Architecture

```
Browser → Vercel (www.monlivremagique.be)
           └── /api/* /media/* /uploads/* → proxy → Railway backend

Railway → nginx + PHP-FPM (supervisord)
           ├── Symfony 7 / Sylius 2 / API Platform
           ├── PostgreSQL (Railway managed service)
           ├── Worker génération IA (loop background process)
           └── Volumes persistants : var/storage, public/media, config/jwt
```

## Services externes

| Service | Usage | Variables Railway |
|---|---|---|
| Stripe | Paiement carte + Bancontact | `STRIPE_SECRET_KEY` `STRIPE_WEBHOOK_SECRET` |
| Replicate | Génération illustrations IA | `REPLICATE_API_TOKEN` `REPLICATE_MODEL` |
| Gelato | Fulfillment print-on-demand | `GELATO_API_KEY` `GELATO_PRODUCT_UID` |
| Brevo SMTP | Emails transactionnels | `MAILER_DSN` |
| Alerting | Email + webhook ops | `ALERT_EMAIL_TO` `ALERT_EMAIL_FROM` |

## URLs

| | URL |
|---|---|
| Frontend | https://www.monlivremagique.be |
| Backend API | https://backend.monlivremagique.be |
| Admin Sylius | https://backend.monlivremagique.be/admin/ |

## Catalogue

| Slug | Titre FR | Âge | Statut |
|---|---|---|---|
| `forest-of-lost-stars` | La Forêt des Étoiles Perdues | 4–7 | Publié |
| `ville-ecole` | Mon Grand Jour en Ville | 3–5 | Publié |
| `espace-robot` | L'Astronaute et Son Robot | 8–10 | Publié |
| `jardin-des-souvenirs` | Le Jardin des Souvenirs Lumineux | 6–8 | Publié |
| `le-secret-du-boulanger` | Le Secret du Boulanger | 3–7 | Publié |

Chaque livre : 10 pages · 3 locales (FR/EN/NL) · 9 images FLUX 2 Pro · `book_blueprint_v2` schema.

## Pipeline de création (12 étapes)

```
YAML brief
  ├─ 1  validate-brief              (validation YAML)
  ├─ 2  generate-master-from-brief  [CLAUDE] → master.json V1
  ├─ 3  qa-correct-master           [GPT]    → master.json V2
  ├─ 4  qa-correct-master           [GPT]    → master.json V3
  ├─ 5  qa-gate                     (informatif, ne bloque pas)
  ├─ 6  validate-blueprint          (schéma master.json)
  ├─ 7  generate-blueprint          → runtimes FR/NL/EN
  ├─ 8  validate-blueprint --runtime (schéma runtimes)
  ├─ 9  create-from-blueprint       [FLUX]   → cover + pages + dedication + summary + backCover
  ├─10  check-assets                (vérifie tous les PNG)
  ├─11  sync-book-blueprints        (catalogue Sylius)
  └─12  verify-catalog              (API + HTTP 200)
```

## Démarrage local

```bash
# Backend
cd backend
docker compose up -d
docker compose exec php php bin/console app:sync-catalog

# Frontend (repo séparé)
cd frontend
npm run dev
```

| Service | URL locale |
|---|---|
| Backend API | http://localhost:8001 |
| Admin Sylius | http://localhost:8001/admin |
| Frontend | http://localhost:8080 |
| MailHog | http://localhost:8026 |

## Documentation

| Document | Contenu |
|---|---|
| [README.local.md](README.local.md) | Setup local détaillé, .env, debug |
| [ADMIN_GUIDE.md](ADMIN_GUIDE.md) | Admin Sylius : création livre, pipeline, dépannage |
