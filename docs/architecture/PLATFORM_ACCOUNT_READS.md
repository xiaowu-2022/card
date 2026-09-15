# Platform all-company operational lists

User-corrected list contract on 2026-09-13: SaaS users, KYC, wallets, top-up orders,
card issue orders and cards default to all-company records. A company selector
filters the records in place; it is not a prerequisite company-directory page.
Every row identifies its persisted company by name. This explicitly authorizes
Platform-only read aggregation, never cross-company Tenant Admin access.

2026-09-13 company-directory extension: `/platform/tenants` displays read-only
USDT inflow (CREDITED wallet_topup_orders.amount, full credited amount including
the identification increment) and outflow (SUCCEEDED withdrawal_orders.amount,
gross including fee, consistent with the user-list total). PAID but uncredited,
pending, rejected and cancelled orders do not count. Each row is scoped by the
persisted tenant ID. Summary cards use exactly the applied name/slug/domain and
lifecycle filters across all matching companies, before pagination, never just
the displayed page. Separate subqueries prevent joins multiplying totals.
Inflow requires wallet_topups.read and outflow withdrawals.read in addition to
tenant.read; unauthorized fields and totals are omitted server-side. No new
permission grant, provider call, financial mutation, or historical replay.

- `/platform/kyc` and its legacy company link require active Platform
  membership and `kyc.read`. The queue exposes allowlisted application metadata
  only, with search, review-status filters and pagination. It does not decrypt
  identity numbers or OCR, expose documents, or add Platform review actions.
- `/platform/users` requires active Platform membership and `users.read`.
  It lists company, account ID, display name, email/phone, account status and
  registration/last-login times with company/search/status filters and pagination.
  Profile joins match both company and user; passwords, session versions, legal
  identity and residential fields are not selected or serialized. No user mutation.
  User-approved 2026-09-13: additional read-only financial columns show available
  USER_AVAILABLE balance, USER_SECURITY_DEPOSIT and USER_COMMISSION balances, plus
  cumulative SUCCEEDED withdrawal order amounts (gross including fee; not net).
  All sums are USDT-only exact PostgreSQL NUMERIC/decimal strings, correlated by
  both persisted company and user; unrelated assets and pending/rejected/cancelled
  withdrawals never contribute. Missing accounts/history display zero and do not
  imply activation. Wallet/deposit columns additionally require wallet.read,
  commission requires ledger.read, and cumulative withdrawals require withdrawals.read.
  Without these permissions the server omits the corresponding fields entirely.
  No permission grants, account creation, provider call or financial mutation.
- The independent **Payment orders** sidebar entry links to `/platform/topups`
  with `wallet_topups.read`, using existing top-up records and confirmation flow.
  It does not add a new order type, payout flow or new confirmation authority.
- `/platform/wallets` and its legacy company link require active
  Platform membership and `wallet.read`. Wallet rows include public account ID,
  contact, status, exact available/deposit/held decimal amounts and asset.
  Holds sum existing withdrawal, card-issue and card-funding accounts with Money.
- Platform-only query classes allowlist DTO fields and join owners using company
  plus resource id. The optional `company` UUID must resolve to a persisted
  company after Platform authorization. Tenant queries remain strictly scoped.
  Search/status/company filters survive pagination. Card issue and card pages
  paginate independently and retain their active tab. No 100-row truncation.
- `/platform/topups` uses `wallet_topups.read`; `/platform/cards` uses `cards.read`.
  Existing company KYC/wallet routes redirect to filtered aggregate pages.
  Existing company top-up routes retain their persisted route-company scope.
  Tenant memberships cannot authorize any aggregate page. No new permissions,
  states, tables or financial mutations.
- The wallet page links to aggregate top-ups, preserving an applied company, with
  `wallet_topups.read`; manual receipt confirmation still independently requires
  `wallet_topups.confirm` and its existing explicit confirmation flow.
  Confirmation targets the selected row's immutable company/order pair, never
  the active list filter, and displays company/account/order/amount before submit.
- Admin copy uses the existing Chinese/English catalog and shared UI primitives.
  Read-only tests use isolated databases, never live financial acceptance.

## Daily platform funds overview (2026-09-13)

The user replaced the `/platform/demo` mock overview with read-only real daily
inflow/outflow lines and net-retained-funds bars. The existing route remains for
bookmark compatibility; fabricated metrics and the provider-count panel are removed.
Default scope is all persisted companies and the latest 30 calendar days including
today. Users can choose a start/end date (inclusive, at most 366 days) and multiple
persisted company UUIDs. Explicit selected scope requires at least one company;
an empty selection never silently broadens into all-company reporting. Selection
is a Platform reporting filter, never a TenantContext or money mutation input.

Reporting days use Asia/Kuala_Lumpur (UTC+8), explicitly labeled in the UI. Inflows
are CREDITED USDT top-up amounts grouped by credited_at; outflows are SUCCEEDED
USDT withdrawal gross amounts (including fees) grouped by blockchain_confirmed_at,
the existing settlement completion timestamp. Half-open UTC query bounds preserve
midnight/end-date correctness, independently of server or database session timezone.
Order creation/request dates and pending/failed/unknown/uncredited orders do not
count. Separate aggregates avoid fan-out; absent days are zero-filled. Each bar is
that day's inflow minus outflow, including negatives, not a cumulative balance.
Summary cards total the selected period. Neither net figure represents company
revenue, user wallet balances, actual custody, or an adjustable fund pool.

Active Platform membership and tenant.read remain mandatory. wallet_topups.read
and withdrawals.read separately gate each financial series and total, server-side.
Net is available only with both permissions. Company Admin cannot access this
aggregate, and client flags cannot grant reporting permission. Only allowlisted
company identifiers/names, day strings and exact decimal aggregates are serialized.
PostgreSQL NUMERIC and BigDecimal retain exact amounts; frontend BigInt arithmetic
normalizes dimensionless SVG coordinates only, never converts money to JS Number.
Hover/focus details and an expandable daily table use exact-string money formatting.
No tables, states, grants, credentials, provider requests, Ledger mutations or
historical replay are introduced. Tests run only against the isolated test database.

Other historic sidebar placeholders remain outside this list-layout correction.

## Card provider entry (2026-09-13)

The user requested renaming the Providers sidebar to Card providers and an empty
default page. `/platform/card-providers` is protected by active Platform membership
and existing `provider_operation.read`. It renders an intentional empty directory
with no preloaded records or nonfunctional create/configuration buttons. The demo
overview's two hardcoded provider rows are removed and its directory count is zero.
This is presentation/navigation only: no provider registry, database deletion,
credential management, new provider integration, seed change, or runtime selection
change. Existing PhotonPay and isolated local Mock adapters are not directory records
and remain unchanged. This initial empty-page-only contract is superseded by the
explicit reference-value approval below.

## Manually maintained card-provider reference values

The user explicitly confirmed names and balances are informational reference values,
not actual Provider balances, on 2026-09-13. The directory now supports creation and
editing with `card_provider_reference.manage`, granted only to Platform Owner/Admin.
Existing `provider_operation.read` controls viewing. No record is seeded by default.

`platform_card_provider_references` is Platform-owned metadata: name, nonnegative
two-decimal reference balance stored as NUMERIC(20,8), fixed USDT label, version,
creation request hash, creator/updater and timestamps. It is not a Ledger account,
wallet balance, company budget, provider connection or provider balance cache.
Nothing in Payment, Wallet, Card, Deposit, Commission or a provider adapter reads
it to make financial decisions. It cannot change credentials or runtime drivers.
The separately approved [product binding](CARD_PRODUCT_PROVIDER_BINDINGS.md)
uses only directory IDs/names for optional product selection. This does not turn
the reference balance into a financial source or an API connection.

Create uses a stable request UUID as resource ID with payload hash and creator
binding, serializing retries and never overwriting a changed record on replay.
Updates lock the record and use version checks to prevent stale overwrites.
Identical resubmission is a no-op. Every actual create/update appends a sanitized
Platform audit with MANUAL_REFERENCE_ONLY provenance inside the same transaction.
No deletion endpoint, Ledger posting or external API call exists in this flow.
UI labels the balance as manually entered reference only in list and form, with
Chinese/English translations. Overview uses the persisted reference-record count,
never fabricated provider health. An unauthorized viewer receives no count.

Tests cover exact decimals, invalid inputs, authorization, replay/conflict,
audit transaction rollback, and unchanged Ledger entries/accounts and adapters.
