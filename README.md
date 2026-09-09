# Aperture Cards — Virtual Card SaaS

Phase 4 Wallet and immutable double-entry Ledger foundation for a tenant-aware Laravel modular monolith. Tenant-scoped End User authentication, hardened KYC, explicit Wallet activation, Ledger Accounts, idempotent atomic Postings, reconciliation, and read-only financial views are real. Top-up, payment, withdrawal, deposit payment/refund, cards, products, and real providers remain intentionally absent or explicit local demo UI.

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

- Tenant A public/user: `http://a.localhost:8000/`, register `/register`, login `/login`, Dashboard `/dashboard`, KYC `/kyc`
- Tenant A Admin: `http://a.localhost:8000/admin/login`, setup `/admin/onboarding`, KYC review `/admin/kyc`
- Tenant B: replace `a.localhost` with `b.localhost`
- Platform Admin: `http://admin.localhost:8000/platform/login`, tenants `/platform/tenants`

Local/test-only Admin credentials: `owner@platform.local`, `owner@a.localhost`, and `owner@b.localhost`, each with `local-password`. Seeded End Users are `user@a.localhost` and `user@b.localhost`, also with `local-password`, but each exists only in its respective Tenant. Production seeding never creates these accounts or any fixed password. Invitation and email verification mail is captured by Mailpit at `http://localhost:8025`; raw invitation tokens and OTPs are never stored in the database.

## Phase 2 End User workflow

1. Open a Tenant's `/register`, choose Email or Phone, and request a verification code.
2. Email codes arrive in Mailpit. Phone verification is behind `SmsVerificationSender`; automated tests use a safe in-memory fake, while local browser phone delivery remains unavailable until an approved SMS adapter is configured. OTPs are never printed to application logs.
3. Verify the six-digit code, create a strong password, and enter `/dashboard`. Account/contact and derived KYC status are shown; no mock balance appears.
4. Sign in through `/login`. A suspended User or a User under a suspended Tenant reaches `/account/restricted` but retains password-change and logout access.
5. Tenant Admins with `users.read` use `/admin/users`; `users.suspend` controls suspend/reactivate actions. These status actions never modify money or cards.

Forgot-password and contact-change workflows are intentionally absent because each requires its own verified recovery challenge design.

## Phase 3 local KYC workflow

Set independent, stable `KYC_DATA_ENCRYPTION_KEY` (exactly 32 bytes, optionally `base64:` encoded) and `KYC_IDENTITY_HASH_KEY` (at least 32 characters). They protect persistent data, never fall back to `APP_KEY`, and must not be casually rotated without a dedicated migration. Local documents use the non-public `private` disk under `storage/app/private`; production should point `KYC_DOCUMENT_DISK` to a private S3-compatible disk. Never place KYC files under `public/storage`.

1. Sign in as the seeded Tenant User and open `/kyc`. Submit NATIONAL_ID front/back test images (JPEG, PNG, or WEBP only; do not use real identity data).
2. Start a queue worker with `docker compose run --rm app php artisan queue:work`. `KYC_OCR_DRIVER=mock` produces a bounded encrypted OCR hint; set `KYC_MOCK_OCR_MODE=FAILED` to exercise manual review after OCR failure.
3. Sign in as Tenant Owner or a KYC Reviewer and open `/admin/kyc`. Search/filter the queue and open a submission.
4. Approve, reject, or request resubmission. Approval only creates an Identity Record; it never creates a Wallet or Card.
5. Raw document viewing requires `kyc.document.view` plus current-password confirmation. Access uses an audited, short-lived signed private stream. SUPPORT can read basic KYC metadata but cannot review or view documents; FINANCE_VIEWER has no KYC permissions.

## Phase 4 Wallet and Ledger workflow

1. KYC approval does not create a Wallet. The approved active User opens `/wallet` and explicitly activates the Tenant default-asset Wallet.
2. Activation creates one Wallet, five User Ledger Accounts, and ensures four Tenant system Accounts, all at `0.00000000`; it creates no synthetic Ledger Entry.
3. `/wallet` shows real available/security-deposit account values and backend-calculated qualification. No top-up, withdrawal, or deposit-payment action exists.
4. Tenant Admins use `/admin/users/{user}/wallet` with `wallet.read` and `/admin/users/{user}/ledger` with `ledger.read`. Both are strictly read-only.
5. Run `php artisan ledger:reconcile` to compare cached balances with Posting truth. A mismatch returns a non-zero status and is never automatically repaired.

## Phase 1 admin workflow

1. Sign in at `http://admin.localhost:8000/platform/login` and create a Tenant from the Tenant directory.
2. Open Mailpit at `http://localhost:8025`. The Owner invitation link targets the new Tenant's `{slug}.localhost` host, expires after 72 hours, and is single-use.
3. Accept the invitation, choose a strong password, and sign in through that Tenant's `/admin/login`. An existing Admin email confirms its current password and receives only the new Tenant membership.
4. Complete branding, locales, manual KYC policy, decimal-string security-deposit configuration, and domain settings under `/admin/onboarding`.
5. Activate the computed foundation when every required item passes. This enables the Tenant web foundation only; User Wallet activation still requires approved KYC, while Card Product, Provider, and payment readiness remain false and unavailable.

Custom domains begin in `PENDING_VERIFICATION`. For the local adapter, `cards.example.test` is configured as verifiable; add it, check verification, activate it, then optionally make it primary. Add any browser-resolvable local hostname mapping you need outside the application. Production must replace the local verifier and provision SSL before serving a custom host.

## Validation commands

```bash
docker compose run --rm app php artisan migrate:fresh --seed
docker compose run --rm app php artisan test
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app php artisan ledger:reconcile
docker compose run --rm node npm run typecheck
docker compose run --rm node npm run lint
docker compose run --rm node npm run format:check
docker compose run --rm node npm run build
```

Without Docker, set `DB_HOST`, `REDIS_HOST`, and `MAIL_HOST` to `127.0.0.1`, then use `composer install`, `npm install`, `php artisan migrate --seed`, `php artisan serve`, and `npm run dev`.

## Environment

Copy `.env.example`; do not commit secrets. `SESSION_DOMAIN` must stay empty so authentication cookies are host-only and do not leak between tenant subdomains. `PLATFORM_ADMIN_HOST` is never resolved as a tenant. `CARD_PROVIDER_DRIVER=mock` selects the contract-compatible test provider through dependency injection.

Private KYC files use `KYC_DOCUMENT_DISK`; the local default is `private`. Identity values and minimized OCR output use the dedicated KYC cipher. Duplicate matching uses a canonical Tenant+document-type+country+number HMAC. Mock OCR is prohibited outside local/testing. Provider credentials must use encrypted secret storage or a secret manager, never ordinary plaintext fields.

## Architecture

Start with [Architecture](docs/architecture/ARCHITECTURE.md), [Tenant Rules](docs/architecture/TENANT_RULES.md), [User Authentication Rules](docs/architecture/USER_AUTH_RULES.md), [KYC Rules](docs/architecture/KYC_RULES.md), [Money Rules](docs/architecture/MONEY_RULES.md), [Ledger Rules](docs/architecture/LEDGER_RULES.md), [Card Provider Rules](docs/architecture/CARD_PROVIDER_RULES.md), and mandatory [Agent Rules](AGENTS.md).
