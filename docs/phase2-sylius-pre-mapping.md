# Phase 2 - Mapping reel Sylius <-> front

## Etat

- `src/services/api.ts` reste intact dans le front
- ce document ne branche rien
- il capture uniquement les payloads Sylius verifies localement en phase 2

## Catalogue

### Front `BookCard`

Source Sylius verifiee:

- `GET /api/v2/shop/products`
- prix: premier variant dans `variants`
- image: premiere image `type=main`
- taxon principal: `mainTaxon`

Champs directement mappables:

- `id` <- `@id` ou `code`
- `slug` <- `slug`
- `title` <- `name`
- `coverImage` <- `images[0].path`
- `price` <- `variants[0].channelPricings[0].price`
- `description` <- `description`

Champs absents du payload natif principal:

- `subtitle`
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

Disponibilite de compensation:

- plusieurs de ces champs existent deja comme `product attributes` dans la base seedee phase 2
- ils ne sont pas exposes tels quels dans le payload principal shop natif
- validation necessaire en phase 3 pour choisir entre:
  - lecture complementaire via endpoints d attributs
  - adaptation backend headless
  - transformation serveur dediee

### Front `BookDetail`

Source Sylius verifiee:

- `GET /api/v2/shop/products-by-slug/{slug}`

Champs directement mappables:

- tous les champs `BookCard` mappables ci-dessus
- `longDescription` <- `description`
- `galleryImages` <- `images[*].path`

Champs non couverts nativement en phase 2:

- `emotionalPromise`
- `features`
- `previewPages`
- `relatedBooks`
- `reviews`
- `faq`
- `personalizationFields`

## Collections

### Front `BookCollection`

Source Sylius verifiee:

- `GET /api/v2/shop/taxons`
- `GET /api/v2/shop/taxons-by-slug/{slug}`

Champs directement mappables:

- `id` <- `@id` ou `code`
- `slug` <- `slug`
- `title` <- `name`
- `description` <- `description`

Champs absents nativement:

- `subtitle`
- `coverImage`
- `bookIds`
- `theme`

## Panier

### Front `CartItem`

Source Sylius verifiee:

- `POST /api/v2/shop/orders`
- `POST /api/v2/shop/orders/{tokenValue}/items`
- `GET /api/v2/shop/orders/{tokenValue}`

Champs directement mappables:

- `id` <- `items[*].id`
- `bookId` <- `items[*].variant.product.code`
- `bookTitle` <- `items[*].productName`
- `price` <- `items[*].unitPrice`
- `quantity` <- `items[*].quantity`

Champs absents nativement:

- `coverImage`
- `previewThumbnail`
- `childName`
- `personalizationSummary`

## Commande

### Front `CustomerOrder`

Source Sylius verifiee:

- `PATCH /api/v2/shop/orders/{tokenValue}`
- `PATCH /api/v2/shop/orders/{tokenValue}/shipments/{shipmentId}`
- `PATCH /api/v2/shop/orders/{tokenValue}/payments/{paymentId}`
- `PATCH /api/v2/shop/orders/{tokenValue}/complete`

Champs directement mappables:

- `orderNumber` <- `number`
- `items` <- `items`
- `subtotal` <- `itemsTotal`
- `shipping` <- `shippingTotal`
- `total` <- `total`
- `shippingAddress` <- `shippingAddress`

Champs encore hors scope natif phase 2:

- `status` front metier simplifie
- `trackingNumber`
- `estimatedDelivery`
- `trackingSteps`
