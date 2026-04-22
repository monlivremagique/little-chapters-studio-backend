# Phase 6 - Stable Session to Cart to Order Link

## Goal

Guarantee a stable business reference for personalized commerce:

- `personalization_session_id`
- survives from personalization session
- to Sylius cart item
- to completed Sylius order

## Chosen implementation

- Sylius entities stay untouched at schema level.
- A dedicated table stores the commerce pivot:
  - `app_personalization_order_item_link`
  - unique `personalization_session_id`
  - unique `order_item_id`
- The personalization session keeps the operational commerce references:
  - `cart_token_value`
  - `cart_item_id`
  - `sylius_order_id`
  - `sylius_order_number`

## Statuses

- `approved`
- `cart_attached`
- `checkout_completed`

## Endpoints

- `POST /api/personalization/sessions/{id}/attach-to-cart`
  - requires `approved`
  - verifies that the provided `cartTokenValue` really owns the provided `cartItemId`
  - persists the dedicated order-item link
  - marks session as `cart_attached`
- `GET /api/custom/orders/{orderNumber}/sessions`
  - returns every personalization session linked to the Sylius order
- `GET /api/custom/orders/{orderNumber}/session`
  - compatibility read for the first linked session only

## Automatic propagation

- Checkout completion is now synchronized automatically on the backend when:
  - `PATCH /api/v2/shop/orders/{token}/complete` succeeds
- Cart detachment is now synchronized automatically on the backend when:
  - `DELETE /api/v2/shop/orders/{token}/items/{itemId}` succeeds
- Reading the custom order endpoints no longer carries the business responsibility of completing the propagation.

## Front impact

- `src/services/api.ts` keeps the unique branching point
- `attachPersonalizationSessionToCartItem()` validates payload, persisted cart linkage, and expected status
- `submitCheckout()` uses the real `orderNumber` returned by Sylius and reads the plural custom endpoint
- `addToCart()` resolves the real Sylius `order_item_id` from the before/after order diff instead of matching by `bookId`
