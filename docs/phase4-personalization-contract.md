# Phase 4 — Contrat minimal de personnalisation

## Domaine métier custom

Le domaine custom reste séparé de Sylius.

Entités minimales :
- `App\Entity\Personalization\PersonalizationSession`
- `App\Entity\Personalization\UploadedPhoto`

## Statuts métier

### PersonalizationSession
- `draft`
- `photo_uploaded`
- `content_saved`

### UploadedPhoto
- `uploaded`

## Endpoints custom

### Créer une session

- `POST /api/personalization/sessions`

Body :

```json
{
  "bookId": "b1"
}
```

### Lire une session

- `GET /api/personalization/sessions/{id}`

### Sauvegarder le contenu personnalisé

- `PATCH /api/personalization/sessions/{id}`

Body minimal :

```json
{
  "step": 3,
  "childName": "Emma",
  "dedication": "Pour toi",
  "extraFields": {}
}
```

### Uploader une photo

- `POST /api/personalization/sessions/{id}/photo`
- `multipart/form-data`
- champ fichier : `photo`

## Réponse session normalisée

```json
{
  "id": "uuid",
  "bookId": "b1",
  "step": 3,
  "childName": "Emma",
  "childPhoto": "http://localhost:8001/uploads/personalizations/...",
  "dedication": "Pour toi",
  "extraFields": {},
  "createdAt": "2026-04-19T14:00:00+00:00",
  "updatedAt": "2026-04-19T14:05:00+00:00",
  "status": "content_saved"
}
```
