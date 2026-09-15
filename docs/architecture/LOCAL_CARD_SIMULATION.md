> 2026-09-15 update: the user approved one deployed site retaining all existing data. Directory routing may preserve LOCAL_MOCK and sandbox connections in that site. Earlier local-only deployment restrictions below are historical; see [UNIFIED_SITE_DEPLOYMENT.md](UNIFIED_SITE_DEPLOYMENT.md). Identity and financial protections remain effective.

# Isolated local Card simulation

User approved 2026-09-13 after the explicit conflict with the former testing-only rule.
This narrowly supersedes the local-runtime prohibition, not money, privacy or tenant rules.

## Selection and isolation

- `APP_ENV=local` identifies local debug mode, independent of `APP_DEBUG` error disclosure.
- `CARD_PROVIDER_DRIVER=mock` is an explicit choice; default remains `photonpay`.
- The configured AND actual PostgreSQL database must be `card_mock` (manual local debug)
  or `card_ui_test` (disposable automated acceptance). Arbitrary names, especially
  `card_platform`, fail closed. No staging/production Mock. The reserved `card_mock`
  database also refuses real-provider binding even if its driver is changed.
- Ordinary `.env`, real database, cards and queue are not switched, copied or replayed.
  Only newly simulator-created holder/card IDs are recognized. Historical TEST/MOCK/DEMO
  cards are not imported. Real PhotonPay guards still reject all test references.
- All business eligibility, ownership, recent-password/CVV no-store, exact-money,
  idempotency and LedgerWriter flows remain active. No generic balance setter exists.
- The existing `MockCardProvider` remains the compatibility fake for old automated
  tests. `LocalMockCardProvider` implements the complete interface for local debug.

## State and outcomes

### Named test merchant (approved 2026-09-13)

`platform_card_provider_references.runtime_driver` is immutable routing metadata,
UNCONFIGURED or LOCAL_MOCK. Creating a merchant named `test` (case insensitive)
in the explicitly enabled isolated local runtime selects LOCAL_MOCK automatically.
Existing records are never enabled by a name lookup during a card operation.
The local-only `cards:configure-local-mock-merchant REFERENCE_UUID` command can
register the explicitly selected existing `test` record once, with SYSTEM audit,
version increment and database rejection when card history already references it.
Renaming or changing its informational balance cannot alter its runtime.

Products retain the UNCONFIGURED legacy-provider discriminator: their bound
merchant's LOCAL_MOCK adapter is the only additional configured route, resolving
only to LocalMockCardProvider in the isolated local runtime. Other bindings fail
closed. Product settings retain the existing BIN/history lock. Simulator product
identity is `MOCK-LOCAL-PRODUCT-{product UUID}`, separate
from the displayed optional BIN. The stable test identity is snapshotted on orders;
no real BIN/card/holder is imported into the simulator. Existing operational rows
retain the PHOTONPAY compatibility discriminator of the original simulator and
its generated MOCK-LOCAL holder/card IDs; all later methods use that same simulator.

Default operation mode is SUCCESS and holder addition is READY. Explicit failure
injection for tests is preserved, as are ownership, KYC, deposit, available money,
positive amounts, idempotency and UNKNOWN recovery. No financial success is forced
when validation fails, and no real provider HTTP requests are made. Reference
balances are never debited by card operations.

`local_card_simulator_states` is infrastructure-only provider simulation, NOT a wallet
or accounting table. One encrypted versioned snapshot stores generated holders,
cards, operations, quotes and transactions. PostgreSQL transaction/advisory locking
serializes simulator updates; state survives HTTP requests and provider instances.
It never writes business Card/Wallet/Ledger tables. Actual application accounting
still uses immutable entries in the isolated database through LedgerWriter.

Amounts are decimal strings at 8 places, checked positive and within NUMERIC(20,8).
Quotes use zero simulated provider fees; this is not a real PhotonPay fee promise.
Stable operation IDs bind the card, operation, amount and holder; changed intents
are rejected and successful replays never apply a second change.

Full contract: holder creation/read/update/field readback; product availability;
issue/query; card and balance queries; transient TEST reveal (`000`, not a real CVV);
quote/confirm/legacy load; amount return and evidence query; freeze/unfreeze/cancel;
transaction list/page/detail. A cancellation with funds produces a distinct
`discard_recharge_return` trade. Wallet credit requires the existing separate
cancellation-return verification flow, never a direct simulator credit.

`MOCK_CARD_PROVIDER_MODE`: SUCCESS, FAILED, UNKNOWN, TIMEOUT, RATE_LIMIT,
DELAYED_SUCCESS, DELAYED_FAILURE, DUPLICATE_WEBHOOK. Ambiguous modes persist intent
without settlement; query the same request after changing to SUCCESS to resolve.
Delayed modes resolve on query. Replaying a pending write does not become a new
request. `MOCK_CARDHOLDER_MODE=READY/REJECTED` models confirmed addition/rejection;
other values remain UNKNOWN (no invented identity or blind retry).

Holder edits store only allowed fields encrypted. Identity document bytes/numbers,
PAN and CVV are not persisted by the simulator; no HTTP requests are performed.
Its small globally serialized store is intentionally unsuitable for production.

## Running locally

The user consolidated local acceptance into one application on 2026-09-15. Use
`docker compose up -d app deposit-refunds node` and open `http://a.localhost:8000`
or `http://admin.localhost:8000`. The existing `card_mock` data and provider isolation
remain unchanged. Local default seed passwords are `123456`; automated test fixtures
retain their independent credentials. No runtime is served on port 8001.

For fresh database setup and safe validation commands, see the repository README and
[LOCAL_ENVIRONMENT_CONSOLIDATION.md](LOCAL_ENVIRONMENT_CONSOLIDATION.md). Never reset
an existing database or import historical provider cards into the simulator. The
application blanks real PhotonPay credentials, preserves the existing session cookie,
uses synchronous jobs and `serve --no-reload` so Laravel does not reload ordinary
`.env` settings in a server subprocess. Existing unused USD wallets are normalized
only by the guarded SINGLE_CURRENCY migration, never by reseeding.

After creating a local mock card, simulate consumption and a signed notification:

```sh
docker compose exec app php artisan cards:mock-purchase CARD_UUID 1.25 REQUEST_UUID
```

Use actual local card/request UUIDs. Reuse the same request UUID to test duplicate
delivery. This local-only command generates an ephemeral RSA signing key and passes
the notification through the real signature verifier and trusted card-to-company
mapping; it never bypasses authentication or exposes a public unsigned Mock endpoint.
It dispatches only the selected event synchronously, never drains historical queues.
The consumer fetches simulator truth; callback amounts/tenant IDs cannot change funds.

## Automated acceptance

Use **only** the disposable `card_ui_test` database with real credentials blanked:

```sh
docker compose run --rm --no-deps -T -e APP_ENV=testing -e DB_DATABASE=card_ui_test -e PHOTONPAY_APP_ID= -e PHOTONPAY_APP_SECRET= -e PHOTONPAY_PRIVATE_KEY= app php artisan test --compact tests/Feature/LocalCardSimulationTest.php tests/Feature/CardIssueTest.php tests/Unit/MockCardProviderTest.php tests/Unit/PhotonPayCardProviderTest.php tests/Unit/PhotonPayManagementTest.php tests/Unit/PhotonPayTransactionsTest.php
```

Tests cover every interface method, fresh-instance persistence, exact decimals,
duplicate writes, wrong ownership/identity, uncertain and definitive outcomes,
real application holds/settlements, recent-auth reveal, consumption notification
signature/duplicate handling, provider-cache refresh, and cancellation return.
These tests do not prove live PhotonPay acceptance, product eligibility or fees.

### Verification record — 2026-09-13

- Named `test` merchant integration: **217 passed / 1,414 assertions**, covering
  both legacy and bound-merchant end-to-end issue/management/reveal/notifications,
  full simulator contract, production/staging rejection, persisted runtime after
  rename, idempotent local registration and blocked client runtime overrides.
  TypeScript, changed-file ESLint, 42 i18n tests and production build passed.
  Migration 000900 was applied only to card_mock; the explicitly selected existing
  `test` record was registered with SYSTEM audit. Browser confirmed its local Mock
  label; default SUCCESS/READY was read from runtime configuration. No real API,
  browser financial action, wallet modification or historical replay was performed.

- Final card/provider regression: **186 passed / 1,092 assertions**, including
  23 new local-simulation cases. The CLI duplicate-consumption and production-denial
  test passed; no external Card HTTP request was sent.
- Full backend run before the final CLI test: **825 passed / 1 failed / 4,786
  assertions**. The remaining failure is the previously known static route allowlist
  in `LedgerFinancialIntegrityAuditTest`, which omits the approved `wallet/transfers`
  route. It is unrelated to local simulation and was not relaxed in this change.
- TypeScript typecheck and changed-file PHP formatting checks passed.
- Created a fresh `card_mock` database (no source database copy), migrated/seeded it,
  started the loopback-only port 8001 service, and verified its local provider binding,
  actual server environment, user login and card eligibility screen. The seed user
  has no funded wallet/card; eligibility was not bypassed for browser inspection.
