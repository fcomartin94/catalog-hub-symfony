# Catalog Hub API — Symfony / API Platform

A headless product catalog API built with Symfony and API Platform, designed to be
consumed by multiple channels at once — a mobile app client and outbound sync
connectors to ecommerce platforms (PrestaShop, WooCommerce).

## Why this project

The rest of this portfolio (`brew-co-prestashop`, `mythic-books-woocommerce`,
`volt-circuit-prestashop-seo`, `feedgoogleshopping-*`, `stock-sync-hub-laravel`)
covers configuring and auditing existing ecommerce platforms, and building a
"glue" backend (Laravel) that talks to those platforms through hand-written
endpoints. This project is deliberately different: instead of writing each
endpoint by hand, it uses API Platform to expose Doctrine entities as full API
resources — CRUD, filtering, pagination, and OpenAPI documentation generated
from attributes on the entity itself, not from manually maintained routes.

This is the architectural pattern a company running ecommerce + mobile apps
needs when it wants one backend serving several channels (web, mobile, store
sync) instead of one bespoke integration per channel.

## What it does

- Exposes a product catalog (`Product`, `Category`) as a full REST/JSON-LD API
  through API Platform, with filtering (`?published=true`, `?name=`, `?category=`)
  and pagination generated declaratively from attributes on the Doctrine entities.
- Supports multiple sales channels through `StoreChannel` and `PriceList`, so the
  same product can carry a different price per store.
- Authenticates clients with JWT (stateless, no server-side session), issued via
  a login endpoint and validated on every subsequent request.
- Enforces two access levels: an admin role that sees and edits everything, and a
  mobile-app role that can only read published products and has no write access.
  The mobile-facing rule also depends on the object's own state (published vs.
  draft), which is handled with a Symfony Voter rather than a flat role check.
- Pushes catalog and pricing data outward to PrestaShop and WooCommerce stores
  through a per-platform connector, triggered via a sync endpoint on each store
  channel.

## Stack

- Symfony 7.4 (LTS), PHP 8.3
- API Platform 4.2 (`api-platform/core`, installed as a package into a plain
  Symfony skeleton rather than the full API Platform distribution, to keep the
  same Nginx + PHP-FPM + MySQL stack used across this portfolio instead of the
  distribution's default Caddy + PostgreSQL)
- MySQL 8.0, Doctrine ORM
- LexikJWTAuthenticationBundle for JWT authentication
- Docker Compose (nginx + php-fpm + mysql)
- PHPUnit with API Platform's `ApiTestCase` for functional tests

## Architecture

```
┌─────────────────┐     ┌──────────────────┐
│   Mobile client   │     │  Store sync (out) │
│  (ROLE_MOBILE_APP)│     │   → PrestaShop /   │
└────────┬─────────┘     │     WooCommerce    │
         │                └────────┬──────────┘
         │ REST (public fields only)         │
         ▼                                    ▼
┌─────────────────────────────────────────────────┐
│              API Platform (Symfony 7.4)            │
│  Product / Category / StoreChannel / PriceList      │
│  Doctrine ORM entities exposed as #[ApiResource]    │
│  Security: Voters (ROLE_ADMIN vs ROLE_MOBILE_APP)   │
│  Serialization groups: admin payload vs public       │
└─────────────────────┬─────────────────────────────┘
                       ▼
                  MySQL 8.0
```

## Data model

- **Category** — `name`, `slug`.
- **Product** — `name`, `slug`, `sku` (unique), `description`, `basePrice`
  (Doctrine `decimal`, not `float`, since money can't be represented safely with
  binary floating point), `published`, and a required relation to `Category`.
- **StoreChannel** — an outbound store (e.g. "Brew & Co. — PrestaShop"): `name`,
  `platform` (`prestashop`/`woocommerce`), `baseUrl`.
- **PriceList** — the join between `Product` and `StoreChannel`, carrying a
  channel-specific `price`, since the same product can be priced differently per
  store (margin, promotions, currency).

## API design notes

Filtering and pagination on `Product` come from API Platform's `SearchFilter`
(partial match on `name`, exact match on `sku`/`category`) and `BooleanFilter`
(on `published`), declared as attributes on the entity — there is no
hand-written filtering logic in a controller.

Read/write payload shape is controlled per property with serialization groups
(`product:read`/`write`, `category:read`/`write`, etc.), so the API can expose
different fields to different contexts without a separate DTO layer.

Authorization uses two mechanisms depending on the rule: a Symfony Voter on
`Product` (`POST_VIEW`/`POST_EDIT`) for rules that depend on the object's own
state — `ROLE_ADMIN` is granted both unconditionally, other roles are only
granted `POST_VIEW` on published products and never `POST_EDIT` — and a flat
`security: "is_granted('ROLE_ADMIN')"` check on `StoreChannel` and `PriceList`,
where the rule is unconditionally about the caller's role and doesn't need to
inspect the object. The sync endpoint (`POST /api/store_channels/{id}/sync`)
enforces its own admin-only check directly via Symfony's `Security` service in
its controller, rather than relying solely on API Platform's declarative
`security` attribute, since custom operations backed by a controller are the
one case where that attribute is not the natural fit.

The outbound sync connectors (`PrestaShopSyncConnector`, `WooCommerceSyncConnector`)
implement a shared `StoreSyncConnectorInterface` and translate the catalog into
each platform's real API shape — PrestaShop's XML Webservice API (API key as
the HTTP Basic Auth username) and WooCommerce's JSON REST API v3 (consumer
key/secret, SKU-based product lookup). `StoreSyncService` syncs every `PriceList`
in a channel and collects per-product failures instead of aborting the whole
sync on the first error, so one rejected product doesn't take down the rest of
a channel's sync.

## Local environment setup

Runs entirely in Docker: nginx, PHP-FPM (PHP 8.3), and MySQL 8.0.

```bash
docker compose build
docker compose up -d
```

Database connection is configured in `.env` via `DATABASE_URL`, pointing at the
`db` service on its internal container port (3306); port 3307 on the host is
only for connecting external DB clients.

Secrets are not committed. `.env` holds only safe defaults (empty `APP_SECRET`,
placeholder JWT passphrase); create a git-ignored `.env.local` with your own values:

```bash
echo "APP_SECRET=$(openssl rand -hex 16)" >> .env.local
echo "JWT_PASSPHRASE=$(openssl rand -hex 16)" >> .env.local
```

```bash
php bin/console doctrine:migrations:migrate
php bin/console lexik:jwt:generate-keypair
```

## Testing

A separate `catalog_hub_test` database is used for the test suite (Symfony
appends `_test` automatically in the test environment), schema-loaded directly
from the entity definitions rather than through migrations, since a test
database doesn't need migration history — just a schema matching the entities
as they currently exist.

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:schema:create --env=test
php bin/phpunit
```

Covers: unauthenticated access is rejected; admin and mobile-app roles get the
expected access to published/unpublished products; the store sync endpoint is
admin-only and correctly reports per-product success/failure against mocked
HTTP responses shaped like PrestaShop's and WooCommerce's real APIs — no live
store is required to run the suite.

## Roadmap

- [x] `Product`/`Category` as API Platform resources with filtering and pagination
- [x] JWT authentication
- [x] Role- and state-based authorization (Voter + flat role checks)
- [x] Multi-channel pricing (`StoreChannel`, `PriceList`)
- [x] Outbound sync to PrestaShop/WooCommerce, tested against mocked responses
- [x] Functional test suite (`ApiTestCase`)

## License

Released under the [MIT License](LICENSE).
