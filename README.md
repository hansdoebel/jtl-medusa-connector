# JTL-Wawi ↔ Medusa connector

An initial **catalog-only staging implementation**, using JTL's official PHP connector core **6.0.1** and Medusa **v2** Admin API. It is not yet a complete integration for accepting live orders.

## Architecture

```text
Windows server                            Shop hosting
JTL-Wawi / JTL-Worker ── outbound HTTPS ──► PHP JTL-Connector endpoint
                                                    │
                                         Medusa v2 Admin API
                                                    │
                                         Medusa commerce backend
                                                    │
                                          Next.js storefront
```

Wawi controls synchronization. The connector is a shop-side endpoint, not a plugin installed inside Wawi. You do not need to expose the Wawi SQL database or a Windows inbound API for this approach. JTL-Wawi remains the ERP and catalog master; Medusa supplies the commerce backend used by the storefront for cart, checkout and payments.

## Implemented in milestone 1

- JTL protocol, authentication and sessions delegated to the official library.
- Modern `ConnectorInterface`, `CoreConfigInterface` and variadic `PushInterface` implementations.
- Persistent SQLite identity mapping, scoped by entity type; conflicting reassignment fails.
- Configured language, currency and retail customer group exposed as JTL global data.
- Category push, including descriptions and parent relationships. Categories are internal during staging.
- Simple product push, with SKU, EAN, one localized title/description, category links, default variant and one retail net price.
- Products always synchronize as **drafts**, with inventory management enabled and backorders disabled.
- Recovery after a lost create response using a deterministic handle and matching ownership metadata. Catalog writes are serialized by a file lock within one connector installation.
- A failed remote write does not commit a mapping. Writes are not automatically retried by the HTTP client; Wawi can retry the synchronization.

Medusa v2 prices use major currency units: a JTL net price of `12.34 EUR` stays `12.34`, not `1234`. This milestone requires tax-exclusive Medusa prices. Configure the Medusa region/tax setup before importing; the connector does not set tax rates or convert net prices to gross prices.

## Run in staging

Requirements: Docker Compose, a reachable Medusa v2 backend, its secret Admin API key, and an existing Medusa shipping profile. EUR/German are example defaults; choose your actual shop currency and ISO 639-1 language.

1. Copy `.env.example` to `.env` and fill in every empty value. Generate a random connector token of at least 32 characters, for example `openssl rand -hex 32`.
2. Use a Medusa **secret** API key for `MEDUSA_ADMIN_API_KEY`. Keep it on the server. The storefront publishable key cannot authorize this integration.
3. Set `MEDUSA_ADMIN_URL` to an HTTPS URL reachable from the connector container. `localhost` inside a container refers to that container.
4. Set `MEDUSA_SHIPPING_PROFILE_ID`, currency and language. Configure the Medusa region for tax-exclusive prices.
5. Run `docker compose up --build -d`.
6. Terminate HTTPS at a reverse proxy and forward a dedicated connector hostname to `127.0.0.1:8080`. Serve only `public/` as the document root if deploying without Docker. The included PHP development server is for staging; production needs PHP-FPM or another production PHP runtime.
7. On a **test** Wawi database, configure the external shop connector URL and enter `JTL_TOKEN`. Check JTL's current custom-connector registration/licensing requirements for your Wawi edition. Connector core version and Wawi application version are separate numbers.
8. Pull global settings first, then push parent categories, child categories, and simple products. Check draft products in Medusa Admin. Do not publish these imports until the missing commerce flows below are implemented and tested.

Wawi registration, actual Windows-server reachability, and a live Medusa API round trip have not been verified in this workspace. An exact Wawi build number and Medusa version are needed for the compatibility test record; “latest” is not a reproducible version.

The Compose volume retains `var/`, including mappings, sessions and locks. Back it up. Use one connector installation/state volume per Wawi shop. Do not share this SQLite implementation across multiple independent hosts. Never clear mappings as a routine retry operation, and do not repoint an existing installation to a different Medusa store.

## Tests

```sh
docker build -t jtl-medusa-connector:test .
docker run --rm jtl-medusa-connector:test composer test
```

Alternatively, with PHP 8.3+ and the required extensions: `composer install` followed by `composer test`. Unit tests use mocked Medusa responses; the protocol test starts an actual local PHP server against the installed JTL core, with test-only credentials.

Verified in this workspace on PHP 8.3.36 and JTL core 6.0.1: **13 tests, 46 assertions passed**, running in the built container as the non-root runtime user. Medusa writes are mocked; the HTTP protocol/authentication test uses the real JTL library. This is not a Windows-Wawi or live-Medusa acceptance test.

## Required before a live shop

1. Confirm the Medusa project/version and exact Wawi build, then test connector registration and a real initial sync.
2. Implement variant families, images, tax mappings and any needed customer-group, tier or special prices. Unsupported variants, bundles and pricing are currently rejected, rather than silently flattened. Additional catalog features such as images are not advertised as supported.
3. Implement inventory item/location links and reconciliation with Medusa reservations and orders awaiting Wawi import. Blindly overwriting stock from Wawi can reopen already reserved stock. Wawi remains the inventory master while Medusa reserves stock at checkout.
4. Implement paginated order/customer export using immutable Medusa order IDs, persistent export state, JTL acknowledgements, replay after crashes, shipping/discount/tax totals, currency and payment mappings. Do not mark an order exported just because Wawi fetched it.
5. Implement Wawi shipment/tracking updates through Medusa fulfillment workflows, with duplicate protection and partial fulfillment support.
6. Define cancellation, refund and return ownership. Medusa owns payment collection; importing a paid order must never charge it again. Add reconciliation and operational error handling.
7. Add sales channel associations, production hosting, monitoring and acceptance checks, then enable publishing deliberately.

Order/customer export, stock synchronization, standalone price updates, image upload, delete, cancellation/refund and fulfillment flows are **not implemented** and are not advertised as supported. This milestone cannot run a live Wawi-backed shop by itself.

## Upstream research

- [JTL connector-core](https://github.com/jtl-software/connector-core): protocol engine, models, authentication, sessions, identity linking and events. The installed dependency is pinned to `6.0.1`; `composer.lock` records all resolved dependencies.
- [JTL connector-example](https://github.com/jtl-software/connector-example): demonstrates endpoint structure, a primary key mapper, categories and global data. Its Composer requirement targets core 5, and its old `push(AbstractModel): AbstractModel` signature must not be copied into core 6 (`push(AbstractModel ...$model): array`). It is not a complete product/order connector.
- [Core 6.0.1 changelog](https://github.com/jtl-software/connector-core/blob/6.0.1/CHANGELOG.md): PHP 8.3 minimum, modern dependencies, DBAL 4, attribute-based DI/serialization and authentication fixes. Do not add the old standalone connector mapping/DBC packages alongside core 6.
- [JTL getting started](https://jtl-devguide.readthedocs.io/projects/jtl-connector/en/master/tutorial/getting-started.html): useful for concepts, but verify its bootstrap example against current source. The current form is `new Application($directory, $config, $schema)` then `run($connector)`.
- [Medusa ERP integration](https://docs.medusajs.com/resources/recipes/erp) and [Admin API product guide](https://docs.medusajs.com/resources/commerce-modules/product/guides/manage-with-admin-api).

The endpoint is original integration code using JTL's package as a dependency; upstream example files are not vendored into this project. No connection to your Windows server, deployment or live shop changes have been performed.
