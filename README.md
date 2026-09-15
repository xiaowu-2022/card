# Aperture Cards — Virtual Card SaaS

A tenant-aware Laravel application with a React/Inertia client, SaaS Platform, company operations console, and immutable PostgreSQL Ledger. Current capabilities and approved boundaries are indexed in [CURRENT_CAPABILITIES.md](docs/architecture/CURRENT_CAPABILITIES.md).

## Unified local environment

Docker Compose runs one application on **port 8000**, using the existing `card_mock` local acceptance database. SaaS, company administration and the client share this runtime; company boundaries still resolve from the host. The former port 8001 service is retired. Its accounts, promotion members, cards and Ledger history remain in the same database, with no database merge or historical replay.

- Client: `http://a.localhost:8000/`
- Company console: `http://a.localhost:8000/admin/login`
- SaaS Platform: `http://admin.localhost:8000/platform/login`
- Company B: replace `a.localhost` with `b.localhost`.

Local default accounts use `123456`: SaaS `owner@platform.local`, company owners `owner@a.localhost` and `owner@b.localhost`, and clients `user@a.localhost` and `user@b.localhost`. Automated PHP fixtures retain `local-password`. These accounts are never seeded in production. Generated promotion fixtures retain their own inaccessible credentials.

## Install and run

Requires Docker Desktop with Compose. For an existing checkout with data, preserve `.env` and its encryption keys; do not regenerate keys or reset databases.

For a fresh checkout only:

```sh
cp .env.example .env
docker compose build app deposit-refunds
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps node npm install
docker compose run --rm --no-deps app php artisan key:generate
```

Configure stable KYC and other private-data encryption/HMAC keys following the architecture docs before uploading test materials. Then initialize a fresh local database:

```sh
docker compose up -d postgres redis mailpit
docker compose run --rm app php artisan migrate --seed
```

Normal startup (also for existing data):

```sh
docker compose up -d app deposit-refunds node
```

The application and deposit-refund worker use the same explicit environment, database, session cookie and provider driver. `serve --no-reload` preserves these overrides. The app is bound to loopback; the Vite development server uses port 5173. `compose.card-mock.yaml` remains an empty compatibility include and creates no additional runtime.

Mailpit captures local email at `http://localhost:8025`. Never log raw OTPs, credentials or private documents. Card simulation is explicitly local-only and uses the complete business flow; it does not bypass KYC, deposit requirements, request idempotency or Ledger rules. Real PhotonPay credentials are blanked in this local Compose runtime. The archived `card_platform` database is not served, copied into the simulator or replayed.

## Final validation

Create the disposable `card_ui_test` database once if absent, owned by the local PostgreSQL role. Never substitute a business database. PHPUnit forces `APP_ENV=testing` and `DB_DATABASE=card_ui_test`; database-refresh tests reject any other environment/database.

```sh
docker compose exec app php artisan test
docker compose exec node npm run test:i18n
docker compose exec node npm run typecheck
docker compose exec node npm run build
docker compose exec app php artisan ledger:reconcile
```

Tests rebuild only the isolated test database. Ledger reconciliation on the current local runtime is read-only. Browser test fixtures use the unified port and local default password. Do not run historical scripts that mutate business data as an implicit part of regression testing.

## Deployment and architecture

Use the single [Deployment runbook](docs/deployment/PRODUCTION_DEPLOYMENT.md) for the
user-approved single site/database preserving all existing data. The deployed card
driver is `directory`: original merchant bindings and identities remain unchanged.
The current compose file remains the workstation development launcher, not another
required server deployment. Production uses its actual Host/TLS configuration.

Preserve APP_KEY, KYC/withdrawal encryption and HMAC keys, OTP secret and private
storage with the database. Do not seed, reset, recreate historical orders or rewrite
balances. See [Unified site deployment](docs/architecture/UNIFIED_SITE_DEPLOYMENT.md)
and [Agent rules](AGENTS.md) for the effective scope.
