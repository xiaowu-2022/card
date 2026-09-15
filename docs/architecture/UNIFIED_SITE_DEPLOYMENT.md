# One deployed site with all existing data

## Approval and boundary — 2026-09-15

The user explicitly confirmed: all existing data stays in the deployed site, with
one site/database rather than separate production/test deployments. This supersedes
the earlier requirement to archive Mock/sandbox records outside the deployed site.
The preserved source is the previously selected card_mock database (502 users,
11 cards and 1286 Ledger events at the recorded snapshot). The old card_platform
backup is historical backup only, not a second running service or a merge source.

This approval allows persisted LOCAL_MOCK and PhotonPay sandbox merchant connections
to continue in the same deployment. It does not turn simulated provider results into
real PhotonPay confirmations, convert card identifiers, reissue existing cards,
rewrite balances, alter history or authorize replay of old financial operations.

## Routing

`CARD_PROVIDER_DRIVER=directory` resolves each product by its persisted binding:

- LOCAL_MOCK merchant: persistent LocalMockCardProvider, with its original encrypted
  simulator state and original MOCK-LOCAL identifiers.
- Existing encrypted sandbox issuing connection: the same sandbox endpoint and
  credentials; the endpoint allowlist and holder origin/ownership restrictions remain.
- Legacy PHOTONPAY product without a directory binding: configured PhotonPay adapter,
  with no fallback and continued rejection of Mock identifiers.
- Unconfigured merchant: unavailable. Merchant names or reference balances never route.

The internal historical class/enum names are retained to avoid identity/data migration.
Directory routing works across database names and deployment locations. Legacy `mock`
mode remains unchanged for the existing workstation runtime and isolated tests.
CLI fixture purchase/batch/cleanup/material archive commands remain local-only; the
new server deployment does not run them. No provider API is called by migration or
restore. Product/provider binding immutability, Tenant scope, signatures, UNKNOWN,
financial idempotency and LedgerWriter contracts remain unchanged.

Migration `2026_09_15_140000_preserve_merchant_routing_on_unified_deployment` replaces
three trigger functions, removing only database-name checks. It preserves runtime
immutability after use, original connection identity and original holder ownership
checks. It changes no rows, keys, amounts or timestamps. The original 58 migrations
are intact. Forward-only recovery remains required.

## Restore and deployment

`scripts/deploy/restore-preserved-site.php` restores the full snapshot into the one
database already selected by server `.env`. It refuses any target with business rows;
it accepts only an empty migration baseline. Target database, configuration and
private files are backed up first. Full PostgreSQL restore is transactional, followed
by the one additive migration, a Ledger reconciliation and snapshot count checks.

Original APP_KEY, KYC and withdrawal encryption/HMAC keys, OTP secret and private
storage are restored together. Target host/database credentials remain unchanged.
Only deployment/runtime values are normalized to production / debug false / directory.
No credentials are printed. The site remains in maintenance for domain/configuration
acceptance; no queue, scheduler, financial recovery or real transaction is started.
An import failure must be inspected without overwriting later writes. The saved
before-restore snapshot is evidence/recovery material, never an automatic money fix.

Production domain proof now uses exact DNS TXT matching at
`_vc-verification.<hostname>`. There is no HTTP fetch, local whitelist acceptance,
IP address or URL input. DNS errors fail closed. Existing Platform authorization,
company ownership and domain lifecycle transitions remain in Application Actions.

## Verification

Directory routing, real/Mock identity separation, unknown merchant refusal, DNS
proof and disabled fixture commands are tested offline. Full application regression
runs only on card_ui_test. Full restore is rehearsed in a newly created disposable
database with no web/queue processes, then removed; this is validation, not a second
user-facing deployment. See the single deployment runbook for the current commands.
