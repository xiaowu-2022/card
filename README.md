# Aperture Cards — Virtual Card SaaS

Phase 1 administrative foundation for a tenant-aware Laravel modular monolith. Admin authentication, tenant creation, invitation acceptance, onboarding settings, domains, team membership, and foundation activation are real. Wallet, KYC applications, cards, balances, transactions, products, and providers remain intentionally absent or explicit demo UI.

## Requirements

- Docker Desktop with Docker Compose (recommended)
- Or PHP 8.4+, Composer 2, Node 22+, PostgreSQL 18, and Redis

## Install and run with Docker

```bash
cp .env.example .env
docker compose build app
docker compose run --rm app composer install
docker compose run --rm node npm install
docker compose run --rm app php artisan key:generate
docker compose up -d postgres redis mailpit
docker compose run --rm app php artisan migrate --seed
docker compose up app node
```

PostgreSQL is authoritative. Redis backs cache, queue, and local sessions. Start a queue worker with `docker compose run --rm app php artisan queue:work`.

## Local hosts and URLs

Modern browsers resolve `*.localhost` to loopback without hosts-file changes.

- Tenant A public/user: `http://a.localhost:8000/`, demo `/demo`, wallet `/demo/wallet`, cards `/demo/cards`, login `/login`
- Tenant A Admin: `http://a.localhost:8000/admin/login`, setup `/admin/onboarding`
- Tenant B: replace `a.localhost` with `b.localhost`
- Platform Admin: `http://admin.localhost:8000/platform/login`, tenants `/platform/tenants`

Local/test-only seeded credentials: `owner@platform.local`, `owner@a.localhost`, and `owner@b.localhost`, each with `local-password`. Production seeding never creates these accounts or any fixed password. Invitation mail is captured by Mailpit at `http://localhost:8025`; raw invitation tokens are never stored in the database.

## Phase 1 admin workflow

1. Sign in at `http://admin.localhost:8000/platform/login` and create a Tenant from the Tenant directory.
2. Open Mailpit at `http://localhost:8025`. The Owner invitation link targets the new Tenant's `{slug}.localhost` host, expires after 72 hours, and is single-use.
3. Accept the invitation, choose a strong password, and sign in through that Tenant's `/admin/login`. An existing Admin email confirms its current password and receives only the new Tenant membership.
4. Complete branding, locales, manual KYC policy, decimal-string security-deposit configuration, and domain settings under `/admin/onboarding`.
5. Activate the computed foundation when every required item passes. This enables the Tenant web foundation only; Card Product, Provider, Wallet, and Ledger readiness remain false and unavailable.

Custom domains begin in `PENDING_VERIFICATION`. For the local adapter, `cards.example.test` is configured as verifiable; add it, check verification, activate it, then optionally make it primary. Add any browser-resolvable local hostname mapping you need outside the application. Production must replace the local verifier and provision SSL before serving a custom host.

## Validation commands

```bash
docker compose run --rm app php artisan migrate:fresh --seed
docker compose run --rm app php artisan test
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm node npm run typecheck
docker compose run --rm node npm run lint
docker compose run --rm node npm run format:check
docker compose run --rm node npm run build
```

Without Docker, set `DB_HOST`, `REDIS_HOST`, and `MAIL_HOST` to `127.0.0.1`, then use `composer install`, `npm install`, `php artisan migrate --seed`, `php artisan serve`, and `npm run dev`.

## Environment

Copy `.env.example`; do not commit secrets. `SESSION_DOMAIN` must stay empty so authentication cookies are host-only and do not leak between tenant subdomains. `PLATFORM_ADMIN_HOST` is never resolved as a tenant. `CARD_PROVIDER_DRIVER=mock` selects the contract-compatible test provider through dependency injection.

Private future KYC files use the `private` disk. Provider credentials must use encrypted secret storage or a secret manager, never ordinary plaintext fields.

## Architecture

Start with [Architecture](docs/architecture/ARCHITECTURE.md), [Tenant Rules](docs/architecture/TENANT_RULES.md), [Money Rules](docs/architecture/MONEY_RULES.md), [Card Provider Rules](docs/architecture/CARD_PROVIDER_RULES.md), and mandatory [Agent Rules](AGENTS.md).
