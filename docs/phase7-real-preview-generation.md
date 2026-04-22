# Phase 7 - Local Real Preview Generation

## Strategy

- no external provider
- no PDF
- no fulfillment
- persisted local generation only

## Backend flow

- `POST /api/personalization/sessions/{id}/generation-requests`
  - creates a persisted generation job
  - generates persisted SVG preview artifacts locally
- `GET /api/personalization/sessions/{id}/generation-status`
  - returns the latest job status
- `GET /api/personalization/sessions/{id}/preview`
  - returns only persisted preview artifacts
  - never simulates the preview on read

## States

- session:
  - `content_completed`
  - `generation_requested`
  - `generating`
  - `preview_partial_ready`
  - `preview_ready`
  - `approved`
- generation job:
  - `queued`
  - `processing`
  - `completed`
  - `failed`

## Front flow

- save personalized content
- trigger generation
- poll generation status
- fetch persisted preview
- approve
- attach to cart
