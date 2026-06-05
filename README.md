<p align="center">
    <img src="https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/storefront-logo.svg" alt="Fleetbase Storefront" width="380">
</p>

<p align="center">
    Logistics-first headless commerce and marketplace infrastructure for Fleetbase.
</p>

<p align="center">
    <a href="https://www.fleetbase.io/docs/storefront">Documentation</a>
    |
    <a href="https://github.com/fleetbase/storefront">GitHub</a>
    |
    <a href="https://www.fleetbase.io/platform/storefront">Platform Overview</a>
    |
    <a href="https://www.fleetbase.io">Fleetbase</a>
</p>

<p align="center">
    <img src="https://img.shields.io/badge/license-AGPL--3.0--or--later-blue" alt="License: AGPL-3.0-or-later">
    <img src="https://img.shields.io/badge/php-%5E8.0-777bb4" alt="PHP ^8.0">
    <img src="https://img.shields.io/badge/node-%3E%3D18-339933" alt="Node >=18">
    <img src="https://img.shields.io/badge/ember-engine-e04e39" alt="Ember Engine">
</p>

---

<p align="center">
    <img src="https://www.fleetbase.io/images/screenshots/storefront/storefront-dashboard.webp" alt="Fleetbase Storefront dashboard" width="860">
</p>

## Overview

Storefront is the commerce extension for Fleetbase. It combines a Laravel API package with an Ember engine for the Fleetbase Console, giving operators the tools to manage stores, products, carts, checkout, customers, marketplace networks, and fulfillment workflows from one logistics-native system.

Unlike a generic e-commerce plugin, Storefront is built around the handoff from purchase to delivery. A checkout can create Fleet-Ops orders, attach storefront order metadata, expose customer and commerce details in the Fleetbase Console, and keep operators close to the real delivery lifecycle.

Read the official guide at [fleetbase.io/docs/storefront](https://www.fleetbase.io/docs/storefront).

## Features

- **Store and marketplace management**: run a single store or organize many stores into Storefront networks.
- **Product catalog**: manage products, categories, variants, variant options, addons, addon categories, images, pricing, tags, and availability hours.
- **Locations and mobile commerce**: configure store locations, service areas, food trucks, Fleet-Ops vehicle links, and catalogs assigned to mobile stores.
- **Carts and checkout**: support persistent carts, line items, variants, addons, scheduled items, service quotes, checkout initialization, and order capture.
- **Payments**: configure Stripe, QPay, and cash-on-delivery flows through Storefront gateways.
- **Customers**: support customer registration, login, SMS verification, social login hooks, saved customer metadata, device registration, order history, and account closure flows.
- **Orders and fulfillment**: manage Storefront orders in the console, advance order activity, assign/unassign drivers, mark preparation states, complete pickups, and inspect commerce details alongside route and tracking data.
- **Reviews and votes**: expose API resources for customer reviews and voting.
- **Promotions and notifications**: manage notification channels and send broadcast or transactional push notifications through APNs, FCM, and related Storefront notification classes.
- **Dashboard analytics**: ship Storefront widgets for revenue, order volume, average order value, active orders, completed orders, customers, cart conversion, cancellation rate, revenue trends, top products, customer insights, order status mix, recent orders, and recent customers.
- **Fleet-Ops integration**: register Storefront summaries inside Fleet-Ops order details and add product-as-entity tooling to Fleet-Ops order creation.
- **Internationalization**: include translations for multiple locales through the extension translation files.

## Architecture

This repository contains both sides of the Storefront extension:

| Area | Path | Purpose |
| --- | --- | --- |
| Ember engine | `addon/`, `app/`, `config/`, `translations/` | Fleetbase Console UI, routes, models, components, services, widgets, and translations. |
| Laravel API | `server/src/`, `server/config/`, `server/migrations/` | Storefront API controllers, models, resources, middleware, providers, observers, notifications, jobs, and database migrations. |
| Tests | `tests/`, `server/tests/` | Ember integration tests and backend test scaffolding. |

The Ember package is published as `@fleetbase/storefront-engine`. The Laravel package is published as `fleetbase/storefront-api`.

## Console Modules

Storefront registers a Console entry under the `storefront` route and exposes these primary work areas:

- **Dashboard**: Storefront-specific operational and analytics widgets.
- **Products**: product catalog, categories, variants, addons, import processing, and product entity creation.
- **Orders**: incoming Storefront orders, activity actions, customer details, commerce summary, documents, route/tracking context, and comments.
- **Customers**: customer records and order history.
- **Networks**: multi-store marketplace networks, store assignment, categories, invitations, and network-level views for stores, customers, and orders.
- **Catalogs**: product bundles and catalog categories, including food truck catalog assignment.
- **Food Trucks**: Fleet-Ops vehicle-linked mobile stores with service area and zone support.
- **Promotions**: push notification campaigns and notification channel workflows.
- **Settings**: store profile, API keys, locations, gateways, and notifications.

## API Surface

Storefront exposes two API families.

### Public Storefront API

The public customer-facing API is mounted under `storefront/v1` and protected by Storefront API middleware. It includes:

- Store lookup, store/network information, locations, gateways, search, tags, and network stores.
- Categories, products, food trucks, reviews, and customer-facing catalog browsing.
- Persistent carts with add, update, remove, empty, and delete operations.
- Service quotes from carts.
- Checkout initialization, status, capture, Stripe setup intents, Stripe payment intent updates, and QPay capture callbacks.
- Customer registration, login, SMS code verification, social login endpoints, device registration, saved places, customer orders, phone verification, Stripe customer helpers, and account closure flows.
- Order pickup completion and receipt generation.

### Internal Console API

The protected internal API is mounted under `storefront/int/v1`. It powers the Fleetbase Console and includes:

- CRUD resources for orders, customers, stores, store hours, store locations, products, product hours, variants, variant options, addons, addon categories, gateways, notification channels, reviews, votes, food trucks, catalogs, catalog categories, and catalog hours.
- Order actions for accept, preparing, ready, completed, cancel, and unassign driver.
- Network actions for adding stores, removing stores, categories, invitations, and network lookup.
- Analytics endpoints for overview, revenue trends, order status mix, top products, and customer insights.
- Metrics and operational action endpoints.

## Getting Started

Storefront is designed to run inside a Fleetbase installation with `fleetbase/core-api` and `fleetbase/fleetops-api` available.

### Requirements

- PHP `^8.0`
- Node.js `>=18`
- pnpm
- Composer
- Fleetbase Core API
- Fleetbase FleetOps API

### Install Dependencies

```bash
pnpm install
composer install
```

### Backend Package

```bash
composer require fleetbase/core-api
composer require fleetbase/fleetops-api
composer require fleetbase/storefront-api
```

### Frontend Package

```bash
pnpm install @fleetbase/storefront-engine
```

## Development

For the full local Fleetbase workflow, see the [Fleetbase Development Setup guide](https://www.fleetbase.io/docs/platform/quickstart/development-setup). That guide covers cloning the main repository with submodules, mounting live package source, linking extensions, running the Console dev server, and reloading the API after backend changes.

### Use Local Storefront Source in Fleetbase

When developing Storefront inside the Fleetbase monorepo, run the package linker from the repository root so the local `packages/storefront` source replaces the published Storefront packages used by Console and API:

```bash
flb-package-linker enable storefront
flb-package-linker install storefront
```

The linker updates the local Console and API manifests for development. To inspect the current link state:

```bash
flb-package-linker status
flb-package-linker doctor
```

If the linker is not installed yet, install it once from the Fleetbase repository root:

```bash
npm link
```

After linking backend package changes, reload the running API worker so Laravel Octane picks up PHP changes:

```bash
docker compose exec application php artisan octane:reload
```

For frontend changes, run the Fleetbase Console dev server as described in the development setup guide; linked Ember packages are watched and live-reloaded by the dev server.

### Package Commands

Run the Ember engine locally:

```bash
pnpm start
```

Build the Ember engine:

```bash
pnpm build
```

Run frontend lint and tests:

```bash
pnpm lint
pnpm test
pnpm test:ember
```

Run backend checks:

```bash
composer test:lint
composer test:types
composer test:unit
composer test
```

## Configuration

Storefront configuration is provided through the Laravel package config files and environment variables:

- `server/config/storefront.php`: API routing, Storefront app verification settings, database connection, and request throttling.
- `server/config/api.php`: Storefront API configuration.
- `server/config/database.connections.php`: Storefront database connection defaults.

Important environment variables include:

| Variable | Purpose |
| --- | --- |
| `STOREFRONT_DB_CONNECTION` | Database connection name for Storefront models. |
| `STOREFRONT_BYPASS_VERIFICATION_CODE` | Development bypass code for Storefront app verification flows. |
| `STOREFRONT_THROTTLE_REQUESTS_PER_MINUTE` | Public Storefront API request limit. |
| `STOREFRONT_THROTTLE_DECAY_MINUTES` | Public Storefront API throttle decay window. |

For customer app configuration, see the [Storefront App configuration docs](https://www.fleetbase.io/docs/storefront/app/configuration).

## Documentation

- [Storefront documentation](https://www.fleetbase.io/docs/storefront)
- [Products and catalog](https://docs.fleetbase.io/guides/storefront/products/)
- [Orders and checkout](https://www.fleetbase.io/docs/storefront/orders/overview)
- [Checkout flow](https://www.fleetbase.io/docs/storefront/orders/checkout)
- [Store locations](https://www.fleetbase.io/docs/storefront/stores/store-locations)
- [Storefront App configuration](https://www.fleetbase.io/docs/storefront/app/configuration)

## Contributing

Storefront follows the same review expectations as the rest of Fleetbase:

- Keep changes small and reviewable.
- Preserve existing architecture and naming conventions.
- Add or update tests when practical.
- Run the relevant frontend or backend validation commands before opening a pull request.
- For API behavior changes, check whether the API specification and public documentation also need updates.

See [CONTRIBUTING.md](CONTRIBUTING.md) for general contribution guidance.

## License

Fleetbase Storefront is open-source software licensed under the [AGPL-3.0-or-later](LICENSE.md).
