# Phase C — Blueprint Page-by-Page Generation

## Objectif

Remplacer la génération backend monolithique par une génération réelle page par page, à partir de `book_blueprint_json`, sans casser le contrat front existant.

## Source de vérité

- produit Sylius
- attribut `book_blueprint_json`
- modèle unique provider: `black-forest-labs/flux-2-pro`

## Pages générées

Le backend génère uniquement les pages illustrées du blueprint :

- `cover`
- `story`
- `backCover`

Les pages textuelles (`dedication`, `summary`) restent servies par le blueprint comme livre default.

## Règles d’orchestration

Pour chaque page illustrée :

- lecture de `prompt_template`
- fusion de `negative_prompt_default` et `negative_prompt` de page
- compilation des placeholders `{child_name}`
- envoi à Replicate avec :
  - image de référence de la page par défaut
  - photo enfant
  - prompt de page
  - aspect ratio de page

## Persistance

Un seul `PersonalizationGenerationJob` local par tentative :

- conserve le plan complet de pages
- conserve la page courante provider
- conserve la progression déjà générée

Chaque page générée produit un `PersonalizationPreviewArtifact` persistant localement.

## Contrat HTTP conservé

- `POST /api/personalization/sessions/{id}/generation-requests`
- `GET /api/personalization/sessions/{id}/generation-status`
- `GET /api/personalization/sessions/{id}/preview`
- `POST /api/personalization/sessions/{id}/approve`
- `POST /api/personalization/sessions/{id}/attach-to-cart`

## Statuts session

- `content_completed`
- `generation_requested`
- `generating`
- `preview_partial_ready`
- `preview_ready`
- `approved`
- `cart_attached`
- `checkout_completed`

## Effet front

Le wizard peut maintenant :

- lancer la génération réelle
- voir les pages arriver progressivement
- lire la preview persistée partielle puis finale
- garder `approve -> attach-to-cart` inchangé
