# Decisions log and ERD

## Scope
Single vendor, one currency, physical goods. Guests browse; only registered users check out.

**In (MVP):** auth with roles (customer, admin); catalog (categories, products, variants; list/filter/search/pagination); server-side cart; checkout with stock locking and idempotency key; order state machine with history; Stripe Checkout + webhooks; queued order emails; admin (product CRUD, stock, order status); React frontend; CI/CD, deploy, monitoring.

**Out (revisit later):** guest checkout / cart merge; multi-currency; tax engine; shipping rates (flat from config); coupons; reviews; wishlists; refunds UI (manual in Stripe); multi-vendor.

## ERD v1
```
users            id, name, email UQ, password, role ENUM(customer,admin), timestamps
addresses        id, user_id FK, line1, line2, city, region, postal_code, country CHAR(2), is_default
categories       id, parent_id FK NULL, name, slug UQ
products         id, category_id FK, name, slug UQ, description, status ENUM(draft,active,archived),
                 timestamps, deleted_at                      IDX(status, category_id)
variants         id, product_id FK, sku UQ, name, price INT UNSIGNED (minor units),
                 stock INT UNSIGNED, timestamps

carts            id, user_id FK UQ, timestamps
cart_items       id, cart_id FK, variant_id FK, quantity     UQ(cart_id, variant_id)

orders           id, number CHAR(26) UQ (ULID, public), user_id FK, status, currency CHAR(3),
                 subtotal, shipping_total, grand_total (all INT),
                 shipping_address JSON, billing_address JSON (snapshots),
                 idempotency_key, placed_at, timestamps     UQ(user_id, idempotency_key)
order_items      id, order_id FK, variant_id FK NULL (SET NULL), sku, product_name, variant_name,
                 unit_price, quantity, line_total            (snapshots)
order_status_history  id, order_id FK, from_status, to_status, actor_id NULL, reason, created_at

payments         id, order_id FK, provider, provider_ref UQ, status, amount, currency, timestamps
webhook_events   id, provider, event_id UQ, type, payload JSON, processed_at NULL
```

## Decisions (approved 2026-10-27)
| # | Decision | Why |
|---|---|---|
| D0 | Price and stock live on `variants` (every product has >= 1 variant). | The SKU is what is sold; avoids a later migration touching cart, orders, locking. |
| D1 | Money = INT minor units; currency in config, stored per order. | No float errors; config change can't alter old orders. |
| D2 | Public order id = ULID `number`; auto-increment ids internal. | Not guessable, doesn't leak volume. |
| D3 | Price/name/address snapshots on orders; `order_items.variant_id` nullable. | Order stays correct after product edits/deletes. |
| D4 | Stock: `UPDATE variants SET stock = stock - ? WHERE id = ? AND stock >= ?` in a transaction; no reservation at add-to-cart. | Atomic check+decrement; cart is not a commitment. |
| D5 | Idempotency: unique `(user_id, idempotency_key)`; webhooks: unique `event_id`. | DB enforces it, not app code. |
| D6 | Role = enum column + Laravel Policies; no Spatie. | Two roles don't justify a package. |
| D7 | Sanctum SPA cookie auth, not bearer tokens. | No token in localStorage; built-in CSRF. |
| D8 | All status changes via one `OrderStateMachine`; each writes a history row. | Legal transitions in one place + audit trail. |
| D9 | Products soft-deleted; orders never deleted. | Keeps references and audit trail. |
