# Little Chapters Studio — Backend

Backend Symfony 7 + Sylius 2 de la plateforme de livres personnalisés pour enfants.

## Stack

- PHP 8.3 / Symfony 7 / Sylius 2 / API Platform
- PostgreSQL 16
- Docker Compose (stack complète front + back)
- Stripe (paiement) · Replicate (génération IA) · Gelato (fulfillment print)

## Démarrage rapide

```bash
docker build -t little-chapters-backend-php:phase1 -f docker/php/Dockerfile .
COMPOSE_BAKE=false docker compose up -d --build
bash scripts/phase2-seed.sh
```

Stack disponible sur :

| Service    | URL                              |
|------------|----------------------------------|
| Frontend   | http://localhost:8080            |
| Backend    | http://localhost:8001            |
| Admin      | http://localhost:8001/admin      |
| MailHog    | http://localhost:8026            |
| PostgreSQL | localhost:55432 (sylius/sylius)  |

## Repos

- Backend (ce dépôt) : `/home/labid/little-chapters-studio-backend`
- Frontend : `/home/labid/little-chapters-studio`

## Documentation

| Document | Contenu |
|----------|---------|
| [README.local.md](README.local.md) | Setup local complet, variables d'environnement, Stripe/Gelato local |
| [RUNBOOK.md](RUNBOOK.md) | Opérationnel : worker, monitoring, support, debug |
| [ADMIN_GUIDE.md](ADMIN_GUIDE.md) | Admin Sylius : créer un produit, blueprint JSON, assets |
| [GO_PROD_CHECKLIST.md](GO_PROD_CHECKLIST.md) | Checklist avant mise en production |
