# Phase 5 - Personalization Flow Contract

## Session statuses

- `draft`
- `photo_uploaded`
- `content_completed`
- `preview_ready`
- `approved`
- `cart_attached`

## Endpoints

- `POST /api/personalization/sessions`
  - create a draft session
- `GET /api/personalization/sessions/{id}`
  - read session state
- `PATCH /api/personalization/sessions/{id}`
  - save personalized content and mark session as `content_completed`
- `POST /api/personalization/sessions/{id}/photo`
  - upload the child photo
- `GET /api/personalization/sessions/{id}/preview`
  - return a simulated backend preview and move `content_completed -> preview_ready`
- `POST /api/personalization/sessions/{id}/approve`
  - explicitly approve the preview and mark session as `approved`
- `POST /api/personalization/sessions/{id}/attach-to-cart`
  - persist the Sylius cart token and order item id, only if the preview was approved
- `POST /api/personalization/sessions/{id}/generation-requests`
  - exposes the explicit backend contract that phase 7 will replace with real preview generation
- `GET /api/personalization/sessions/{id}/generation-status`
  - exposes the current preview-generation readiness without starting any external integration

## Business rule

- `attach-to-cart` returns `422` until the session is explicitly approved.
- Phase 5 does not create any AI job, PDF, print, or fulfillment integration.
