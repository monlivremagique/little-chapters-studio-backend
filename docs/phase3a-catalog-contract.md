# Phase 3A - Contrat catalogue adapte front

## Strategie retenue

La strategie retenue est celle d'endpoints read adaptes front.

Justification:
- le front client reste gele et inchange
- le contrat attendu par `BookCard`, `BookDetail` et `BookCollection` depasse ce que Sylius expose nativement en shop API
- enrichir directement les ressources Sylius shop aurait couple le contrat front a l'API commerce native
- des endpoints read dedies permettent de stabiliser le contrat catalogue sans modifier le domaine commerce ni `src/services/api.ts`

## Endpoints exposes

- `GET /api/books`
- `GET /api/books/{slug}`
- `GET /api/collections`
- `GET /api/collections/{slug}`

## Stabilisation runtime locale

En environnement `dev`, ces 4 routes sont marquees:

- `stateless`
- avec `defaults: ['_profiler_collect' => false]`

Le backend utilise `framework.profiler.collect_parameter = _profiler_collect` pour ces routes uniquement.

But:
- eviter que le profiler Symfony dev perturbe ces endpoints read JSON
- conserver les autres comportements de debug du projet local
- ne pas introduire de contournement global sur Sylius ou sur le front

## Couverture du contrat

### BookCard

`GET /api/books` retourne une liste d'objets avec les cles:

- `id`
- `slug`
- `title`
- `subtitle`
- `coverImage`
- `price`
- `originalPrice`
- `rating`
- `reviewCount`
- `ageMin`
- `ageMax`
- `theme`
- `occasion`
- `badge`
- `isNew`
- `isBestseller`
- `personalizationLevel`
- `language`

### BookDetail

`GET /api/books/{slug}` retourne toutes les cles de `BookCard` plus:

- `description`
- `longDescription`
- `emotionalPromise`
- `features`
- `pages`
- `format`
- `coverType`
- `printQuality`
- `galleryImages`
- `previewPages`
- `relatedBooks`
- `reviews`
- `faq`
- `personalizationFields`

### BookCollection

`GET /api/collections` et `GET /api/collections/{slug}` retournent:

- `id`
- `slug`
- `title`
- `subtitle`
- `description`
- `coverImage`
- `bookIds`
- `theme`

## Mapping final

### BookCard

- `id`: metadata backend stable, volontairement alignee sur le front (`b1` a `b5`)
- `slug`: `sylius_product_translation.slug`
- `title`: `sylius_product_translation.name`
- `subtitle`: attribut produit `book_subtitle`
- `coverImage`: image produit Sylius resolue en URL absolue backend
- `price`: `sylius_channel_pricing.price / 100`
- `originalPrice`: `sylius_channel_pricing.original_price / 100`
- `rating`: metadata backend
- `reviewCount`: metadata backend
- `ageMin`: attribut produit `book_age_min`
- `ageMax`: attribut produit `book_age_max`
- `theme`: attribut produit `book_theme`
- `occasion`: metadata backend
- `badge`: attribut produit `book_badge`
- `isNew`: metadata backend
- `isBestseller`: metadata backend
- `personalizationLevel`: attribut produit `book_personalization_level` normalise vers `simple | avancée | premium`
- `language`: attribut produit `book_language` normalise vers `Français`

### BookDetail

- `description`: metadata backend stable
- `longDescription`: metadata backend stable
- `emotionalPromise`: metadata backend stable
- `features`: metadata backend stable
- `pages`: attribut produit `book_pages`
- `format`: attribut produit `book_format`
- `coverType`: attribut produit `book_cover_type` normalise pour rester lisible par le front
- `printQuality`: metadata backend stable
- `galleryImages`: images produit Sylius, completees si necessaire
- `previewPages`: metadata backend stable avec URLs d'image backend
- `relatedBooks`: metadata backend stable, alignee sur les IDs front
- `reviews`: metadata backend stable
- `faq`: metadata backend stable
- `personalizationFields`: metadata backend stable

### BookCollection

- `id`: metadata backend stable (`c1` a `c5`)
- `slug`: `sylius_taxon_translation.slug`
- `title`: `sylius_taxon_translation.name`
- `subtitle`: metadata backend stable
- `description`: metadata backend stable
- `coverImage`: image du premier livre de la collection, fallback premier livre catalogue
- `bookIds`: derives des taxons reels Sylius puis remappes vers les IDs front
- `theme`: metadata backend stable

## Decision de phase

Le contrat backend catalogue est implemente et demontre cote backend.

Conformement a la consigne courante, aucun fichier du repo `little-chapters-studio/` n'a ete modifie a cette phase.
