# Phase 8 — Replicate Preview Generation

## Objectif

Remplacer la génération locale minimale par une vraie orchestration Replicate derrière le contrat déjà stabilisé :

- `POST /api/personalization/sessions/{id}/generation-requests`
- `GET /api/personalization/sessions/{id}/generation-status`
- `GET /api/personalization/sessions/{id}/preview`
- `POST /api/personalization/sessions/{id}/approve`
- `POST /api/personalization/sessions/{id}/attach-to-cart`

Le front ne change pas visuellement. `src/services/api.ts` reste le point unique de branchement.

## Stratégie retenue

- polling backend sur Replicate, sans webhook
- persistance systématique des métadonnées provider :
  - `provider`
  - `provider_job_id`
  - `provider_status`
  - `model_reference`
  - `attempt_number`
  - `request_payload`
  - `response_payload`
  - `error_message`
- téléchargement immédiat des outputs provider puis stockage local dans `public/uploads/personalizations/`
- `preview` ne sert jamais d’URL provider temporaire

## Variables locales

Définies dans `.env.local` :

- `REPLICATE_API_BASE_URI`
- `REPLICATE_API_TOKEN`
- `REPLICATE_MODEL`
- `REPLICATE_MAX_RETRIES`

## Modèle Replicate

Le backend appelle le modèle configuré par `REPLICATE_MODEL`, au format `owner/name`, puis résout sa `latest_version` avant de créer la prédiction via `POST /v1/predictions`.

Le modèle retenu est `black-forest-labs/flux-2-pro`.

## Flux

1. Le wizard appelle `generation-requests`.
2. Le backend crée un job local, appelle Replicate et stocke `provider_job_id`.
3. Le wizard poll `generation-status`.
4. Le backend poll Replicate, persiste `response_payload` et :
   - si `succeeded` :
     - télécharge les outputs
     - écrit les fichiers localement
     - crée les `PersonalizationPreviewArtifact`
     - passe la session à `preview_ready`
   - si `failed` ou `canceled` :
     - stocke l’erreur
     - laisse la session en `content_completed`
5. `preview` lit uniquement les artefacts persistés.
6. `approve` reste interdit tant qu’aucune vraie preview persistée n’existe.
7. `attach-to-cart` reste interdit tant que la session n’est pas `approved`.

## Retry

- retry contrôlé par `REPLICATE_MAX_RETRIES`
- un nouvel appel `generation-requests` après échec crée un nouveau job uniquement si la limite n’est pas atteinte

## Limite locale

Sans `REPLICATE_API_TOKEN`, l’intégration reste correctement branchée mais la vraie génération provider ne peut pas être exécutée. Le backend retourne alors une erreur explicite de configuration.
