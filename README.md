# Aperture Cards — Virtual Card SaaS

Phase 0 foundation for a tenant-aware Laravel modular monolith. All wallet, KYC, card, balance, and transaction screens currently use explicit static demo data; formal business workflows are intentionally absent.

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
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --seed
docker compose up app node
```

PostgreSQL is authoritative. Redis backs cache, queue, and local sessions. Start a queue worker with `docker compose run --rm app php artisan queue:work`.

## Local hosts and URLs

Modern browsers resolve `*.localhost` to loopback without hosts-file changes.

- Tenant A public/user: `http://a.localhost:8000/`, demo `/demo`, wallet `/demo/wallet`, cards `/demo/cards`, login `/login`
- Tenant A Admin: `http://a.localhost:8000/admin/login`, demo `/admin/demo`
- Tenant B: replace `a.localhost` with `b.localhost`
- Platform Admin: `http://admin.localhost:8000/platform/login`, demo `/platform/demo`

Local/test-only seeded credentials (login is not wired in Phase 0): `owner@platform.local`, `owner@a.localhost`, and `owner@b.localhost`, each with `local-password`. Production seeding never creates these accounts or any fixed password.

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

Without Docker, set `DB_HOST`/`REDIS_HOST` to `127.0.0.1`, then use `composer install`, `npm install`, `php artisan migrate --seed`, `php artisan serve`, and `npm run dev`.

## Environment

Copy `.env.example`; do not commit secrets. `SESSION_DOMAIN` must stay empty so authentication cookies are host-only and do not leak between tenant subdomains. `PLATFORM_ADMIN_HOST` is never resolved as a tenant. `CARD_PROVIDER_DRIVER=mock` selects the contract-compatible test provider through dependency injection.

Private future KYC files use the `private` disk. Provider credentials must use encrypted secret storage or a secret manager, never ordinary plaintext fields.

## Architecture

Start with [Architecture](docs/architecture/ARCHITECTURE.md), [Tenant Rules](docs/architecture/TENANT_RULES.md), [Money Rules](docs/architecture/MONEY_RULES.md), [Card Provider Rules](docs/architecture/CARD_PROVIDER_RULES.md), and mandatory [Agent Rules](AGENTS.md).
