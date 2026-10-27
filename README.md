# E-commerce

A full-stack e-commerce platform covering the complete order lifecycle: catalog, cart, checkout, payments, fulfilment and administration. Built with a focus on data integrity, concurrency safety and clean architecture.

## Features

- Authentication with customer and admin roles
- Catalog with categories, product variants, search, filtering and pagination
- Server-side cart
- Transactional checkout with stock locking and idempotency keys
- Order state machine with status history
- Stripe Checkout and signature-verified webhooks
- Queued order emails
- Admin panel for products, stock and orders
- CI/CD with automated tests

## Tech Stack

| Layer | Technology |
|---|---|
| API | Laravel 12 (REST), Sanctum cookie auth |
| Frontend | React |
| Database | MySQL |
| Cache and queues | Redis |
| Payments | Stripe |
| Testing | Pest |
| CI/CD | GitHub Actions |

## Architecture

```
React SPA ──▶ Laravel API ──▶ MySQL
                  │
                  ├──▶ Redis (cache, queue)
                  │        └──▶ Queue worker (emails)
                  │
                  └──▶ Stripe ──▶ Webhook ──▶ Laravel API
```

## Design Principles

Full ERD and decision log: [docs/decisions.md](docs/decisions.md)

## Order Lifecycle

```
pending ──▶ paid ──▶ shipped ──▶ delivered
   │          │
   └──▶ cancelled
```

## Roadmap

- [ ] Scope, ERD and decisions
- [ ] Auth and roles
- [ ] Catalogue
- [ ] Cart
- [ ] Checkout
- [ ] Order state machine
- [ ] Stripe payments and webhooks
- [ ] Queues and emails
- [ ] Caching and performance
- [ ] Admin panel
- [ ] React frontend
- [ ] CI/CD, deployment and monitoring
