# Live incoming USDT and SaaS rechecks

## Optional server API key (2026-10-10)

The user approved using a configured TronGrid key after read-only authenticated
requests successfully returned the reported transactions and solidified receipts.
`config/payment.php` reads `TRONGRID_API_KEY` from private deployment configuration.
When nonempty, the gateway sends it as `TRON-PRO-API-KEY` on every discovery,
receipt and block request to the fixed `https://api.trongrid.io` origin. Redirects
stay disabled. It is never put in URLs, bodies, frontend DTOs, source, audit or
logs. Malformed configured values fail closed before HTTP. Authentication failure
does not fall back to anonymous traffic. Blank configuration retains anonymous
reads; old encrypted credentials are not activated implicitly. This supersedes
the anonymous-only behavior described in older sections below.

The key applies equally to scheduled scans and Platform hash/automatic-search
verification. Exact receipt checks, immutable order scope, Ledger idempotency,
bounded queries and shared HTTP 429 backoff remain unchanged. Provider quotas may
depend on account plan, endpoint, IP and time window; no fixed throughput or
absence of future throttling is promised. See the
[official quota guidance](https://developers.tron.network/reference/rate-limits).

The user will configure production. Deploy the PHP/config changes, set the
following in the existing private server `.env` (substitute the real key):

```dotenv
TRONGRID_API_KEY=your_trongrid_api_key
```

Then run the site's PHP version from `/www/wwwroot/card`:

```sh
/www/server/php/84/bin/php artisan config:cache
```

Reload the site's PHP-FPM/opcache and any persistent scheduler process through
the existing deployment process. Keep the normal minute scheduler and its cache;
do not start extra scans or clear shared cooldowns. No database migration is
needed. The combined deployment bundle also includes the previously built
Platform automatic-search UI; no new frontend or H5 change is needed for the key.
The bundle excludes `.env` and all real credentials. The PHPUnit environment
explicitly clears this key; fake-request tests use synthetic credentials only.

Later 2026-09-13 approval adds [SaaS manual receipt confirmation](PLATFORM_MANUAL_TOPUP_CONFIRMATION.md)
without online verification. That separate audited order workflow supersedes the
no-manual-confirmation prohibition below; this chain adapter still requires exact proof.

Approved 2026-09-13: automatic exact chain verification is primary; SaaS may manually
trigger the same verification for one selected company/order. Company Admin remains
read-only. This narrow extension supersedes Phase 8's absence of a real read-only
chain adapter, NOT its prohibition on force-success, manual assignment or balance edits.

## Trust and accounting

`TronGridBlockchainGateway` implements `BlockchainGatewayInterface` and the additional
read-only `Trc20ChainReader` contract. Only fixed mainnet `https://api.trongrid.io` is
contacted. There are no custom URLs, redirect following, custody, signing, FX or Mock
fallback. Real withdrawal verification is deliberately not enabled by this addition.
The real adapter requires the official Tether contract
`TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t` and a valid Base58Check destination. Amounts come
from successful solidified receipts: exact Transfer signature, token address, indexed
recipient and uint256 base units at USDT's six-decimal scale. Receipt log index—not
history result position—is event identity. Confirmation depth uses solidified block
height. HTTP/parse/timeout/receipt uncertainty never means FAILED or PAID.

History pagination discovers transaction hashes only. It cannot supply credit amounts
or confirmation facts. All pages must succeed, opaque fingerprints stay on the fixed
host, receipt lookups supply final facts, and pagination exhaustion fails explicitly.
The existing `ProcessIncomingTrc20TransferAction` and `CreditWalletTopupAction` remain
the only PAID/credit path; adapter code never accesses Wallet or Ledger. Review,
cancelled and refunded states cannot be turned into success by a recheck.

`VerifyPlatformTopupAction` verifies fresh active Admin status and active Platform
membership with `wallet_topups.verify` even outside HTTP. Routes additionally enforce
Platform host/scope and throttle. The immutable selected company and order come from
authorized persisted routes, never body tenant/user IDs. A hash for another order
cannot settle that order as a side effect. Confirmation checkbox + request UUID + hash
are required; no selected amount/asset/provider status is accepted as authority.
Attempts and outcomes receive sanitized append-only audit events. Replays use the
same existing Order and chain event and deterministic Ledger credit key, not a new
financial entry. New permission defaults only to PLATFORM_OWNER/PLATFORM_ADMIN.
The initial request audit event is the immutable verification intent. A partial
unique index on actor/request UUID and a short PostgreSQL request lock bind it to
one company/order/hash before HTTP. Reusing the UUID for different details fails;
rechecking the same still-confirming transaction is allowed without duplicate credit.

## Public scanning without deployment configuration (2026-09-24)

The user superseded the credential and explicit-start deployment gates. Production
always binds the real public reader, regardless of legacy `BLOCKCHAIN_GATEWAY_DRIVER`
values. Explicit mock/unavailable selection remains local/testing-only. The fixed
mainnet endpoint is called anonymously; stored API keys are neither decrypted nor
sent. The official USDT contract is built in, ignoring environment overrides.
No endpoint, API key, contract, scan switch or start-time configuration is required.
SaaS still supplies the actual business receiving address; the existing address
environment fallback remains supported but is not required when SaaS configured it.
Withdrawal verification remains unavailable in this reader.

`trc20_scan_cursors` stores per-address progress. Existing `started_at` and
`scanned_through` remain authoritative, including previously explicit boundaries.
For an address with no cursor, the scheduled scanner starts at the earliest
TRC20_SHARED/TRON order in PENDING, PROCESSING or PAID status at that exact address.
It preserves timestamp precision and initializes using insert-or-ignore before
rereading the winning cursor. No unfinished order means no cursor and no upstream
scan for that address. Completed, cancelled, expired and review history cannot
bootstrap scans. Old scan flags and environment start times are ignored; they never
rewind, skip forward or suppress persisted progress. Orders predating an existing
cursor boundary remain excluded from automatic verification.

The forward scan processes at most five minutes per address up to the solidified
confirmation boundary. Before moving forward (also when already caught up), it
rechecks each unfinished order's previously scanned validity interval at that address.
Only PENDING/PROCESSING/PAID orders created on or after the existing cursor start are
eligible; the read interval ends at min(cursor, confirmed boundary, order expiry).
Each observation is processed with that order's immutable tenant/order scope. A
successful empty public-index response can temporarily omit a real transfer, so the
forward cursor alone must not prevent this pending-order recovery. Rechecks never
rewind cursors or select completed/expired/review/cancelled history. Failure stops
that address before forward progress or expiration; completed credits remain
idempotent. Existing expiration rules still apply after successful verification.

Existing and rotated order addresses remain eligible. Exact destination,
contract, amount, receipt event identity, validity interval and confirmation depth
still govern credit through the existing LedgerWriter path. Duplicate observations
cannot credit twice. HTTP failure, rate limiting, incomplete pagination or uncertain
receipts never advance the checkpoint or expire unseen orders. A subsequent scheduled
run retries the same window; no immediate retry burst is introduced.

Deployment requires updating application code and rebuilding configuration cache,
then the existing every-minute scheduler runs `topups:scan-trc20`. There are no new
migrations or financial data edits. Do not reset existing cursors. The public service
may rate-limit anonymous requests; errors preserve pending funds and progress.
Initial anonymous block/history connectivity was checked read-only. During the
user-reported missed-payment investigation, the selected public receipt and history
window were also read without executing an application scan or changing money. Offline tests cover anonymous receipt reads,
automatic pending-order bootstrap, unchanged cursor boundaries, errors, confirmation
retry and a synthetic 700.01 USDT credit with exactly one Ledger event. This does not
claim that any production payment was credited or that public availability is guaranteed.

Sources checked during implementation:
- [TRON incoming history API](https://developers.tron.network/reference/get-trc20-transaction-info-by-account-address)
- [TRON integration and solidified receipts](https://developers.tron.network/docs/exchangewallet-integrate-with-the-tron-network)
- [Tether supported protocols](https://tether.to/es/supported-protocols/)

## Receipt timestamp matching across database timezones (2026-09-24)

UTC receipt instants must be bound to PostgreSQL as explicit-offset timestamps
(`Y-m-d H:i:s.uP`). Laravel's default DateTime query binding drops the offset,
which makes a UTC receipt timestamp compare as local time in an Asia/Shanghai or
other non-UTC database session. This can reject an otherwise exact order as
UNMATCHED. Both active/expired candidate lookup and reservation-expiry boundaries
now preserve the offset and microseconds. No order timestamp, timezone setting,
financial identity or Ledger history is rewritten.

The regression reproduced UNMATCHED before the fix with a UTC receipt and a +08
database session. Tests cover pending and expired exact matching, rejection outside
the immutable validity interval, precise verified expiration boundaries and the
full synthetic public receipt-to-credit path under both UTC and +08 sessions.

## Pending-index recovery acceptance (2026-09-24)

81 related tests / 538 assertions passed, including an initially empty index that
later exposes a transfer behind the cursor, caught-up cursor recovery bounded by
order expiry, duplicate-credit prevention and unchanged progress on recheck failure.

## Historical offline acceptance (2026-09-13)

141 related backend tests / 603 assertions, 18 architecture checks and 36 frontend catalog/behavior tests
passed on 2026-09-13. Coverage includes real-adapter HTTP fakes through final Ledger
credit, exact receipt decoding, wrong token/destination/hash, insufficient confirmations,
duplicate scans/manual requests, cross-company rejection, company-admin denial, API
timeouts/rate limits/redirects, explicit start gating, historical-order exclusion and
checkpoint replay with timestamp precision. No real transfers, live receipts, existing
financial settlements or general background workers were executed. The isolated
`card_mock` database received only the new permission/checkpoint and request-index migrations; its
existing USD Wallet and balances were not altered.

## Public-reader failure diagnostics (2026-09-26)

A reported top-up was transferred at 13:58:06 (+08) and first detected, confirmed
and credited at 14:16:06. Production logs show discovery-request failures every
minute from 14:00 through 14:15. A later read-only check of the order's five-minute
window returned HTTP 200, `success=true` and one record in 0.73 seconds. This proves
current availability, not the historical HTTP status or a specific throttling cause.
The old exception deliberately discarded those details; they cannot be recovered
from its stack trace. A scheduler wrapper's successful exit is not proof that each
child command succeeded.

Failed HTTP requests now emit `TRC20 public reader request failed` with only:
- Fixed endpoint category (`solid_head`, `solid_block`, `transaction_receipt`,
  `account_transfers`, `unknown`), never an address-bearing URL.
- Local failure phase (`transport`, `http_status`, `response_size`, `response_json`,
  `upstream_error`), numeric HTTP status if available, monotonic elapsed milliseconds.
- Bounded numeric cURL errno extracted from a recognized connection exception's
  `cURL error N:` prefix, when available; the rest of its message is discarded.

No response body, headers, error messages, request parameters, addresses, transaction
hashes or exception chains are logged by this diagnostic. Logger failure cannot
permit credit or replace the safe domain error. These diagnostics cover the request
boundary; successful HTTP responses rejected by later receipt/pagination validation
still fail closed through the existing validation path.

This change does not alter retries, confirmation depth, scan windows/cursors,
settlement, provider configuration or financial records. Deploy the updated gateway
PHP file through the normal code-release process; no migration, frontend build or
configuration change is needed. Restart a persistent scheduler worker if used.
To inspect new failures on the server, read (do not manually run a financial scan):

```sh
grep -n 'TRC20 public reader request failed' storage/logs/laravel*.log | tail -30
```

Offline HTTP fakes cover HTTP failures, transport errors, invalid/oversized JSON,
upstream errors, redaction and logger failure, alongside the existing receipt and
shared-top-up regression suites. This adds evidence for future failures; it does
not establish that the historical latency issue has been resolved.

## Shared-address request amplification and HTTP 429 (2026-10-10)

The supplied production log at 10:49:03 records `account_transfers`,
`http_status=429`, in 100 ms. That request was throttled before a discovery list
could be read; no receipt or credit can be inferred from the failure. This log
alone does not establish the status of the two reported overnight payments.

Pending-order recovery previously queried overlapping validity intervals separately
for every order. It now groups overlapping windows within each 100-order keyset
batch, with merged spans bounded to one hour (an individual existing longer
validity window is retained). Discovery and receipt verification run once per
group; processing still binds each transfer to the original tenant/order and
individual creation/expiry interval. Cursor starts, forward five-minute windows,
completed-order exclusion, exact matching and Ledger idempotency stay unchanged.

HTTP 429 now establishes a shared cache cooldown across addresses, endpoints and
PHP processes. Retry-After seconds and HTTP dates are honored; otherwise retries
back off exponentially from 60 seconds to 15 minutes with up to 15 seconds jitter.
These are application retry delays, not assumed upstream quotas. Failure streaks
are endpoint-specific, so a successful head request does not reset repeated
discovery throttling. During cooldown no upstream request is sent, and the next
normal scheduler invocation can retry after it expires. Only transport metadata
is cached. Cache failure is fail-closed; uncertain scans retain their checkpoint
and do not release reservations. No receipt facts or balances are cached here.

Deploy both `ScanTrc20TopupsAction.php` and `TronGridBlockchainGateway.php`, preserving
the existing shared persistent cache (production Redis). Reload PHP-FPM/opcache and
any persistent scheduler process through the normal deployment procedure. No
migration, frontend build, key, cursor reset or manual financial scan is required.
Keep the existing minute scheduler; do not add extra scanner cron jobs or repeatedly
clear the cache, which would discard the cooldown. Local tests use only synthetic
orders in `card_ui_test` and fake HTTP responses.

This mitigates duplicate traffic and retry pressure; anonymous upstream availability
is not guaranteed. The [official rate-limit guidance](https://developers.tron.network/reference/rate-limits)
warns that anonymous requests may be restricted or rejected. This change preserves
the approved anonymous reader and does not activate legacy credentials. Production
deployment and each reported order's status still require live verification.

## 2026-10-10 production log and read-only runtime diagnosis

The supplied full-day log ends at 12:01 and contains 605 discovery HTTP 429s and
2 transport failures, with the last recorded scan failure at 11:35. Absence of
later errors is not proof of settlement. Separately, 722 attempts by the obsolete
card-transaction recovery command failed because the database jobs table is absent.
Retiring that card queue does not resolve chain throttling and must not disable the
deposit scanner. Stack line positions differ from the current gateway/grouped scanner,
suggesting older runtime code; the log alone cannot establish the current deployment.

Deploy the current gateway, grouped scanner and `config/payment.php` together.
The operator configures the approved optional `TRONGRID_API_KEY` privately, runs
`php artisan config:cache`, and reloads FPM/opcache and persistent scheduler processes.
Keep the minute scheduler and shared persistent cache; do not reset cursors or clear
cooldowns. A configured key does not guarantee unlimited upstream access.

An optional `php artisan topups:diagnose-trc20` reads the CLI runtime revisions,
config-cache state, key presence/syntax booleans, cache driver/cooldown, unfinished
order counts and cursor timestamps. It prints no key, key hash, receiving address,
order identifier or exception details. It does not contact TronGrid, authenticate
the key, scan/expire orders, dispatch jobs, bootstrap cursors or change funds.
CLI output does not establish the state of a separately running FPM/scheduler process.
The command is for deployment diagnosis only, not proof that any online order arrived.

## Automatic scan retention: one hour (2026-10-10)

This supersedes unlimited unfinished-order lookback. Each scan captures one cutoff
at invocation time minus 60 minutes. Only TRC20_SHARED/TRON orders created at or
after that boundary with PENDING, PROCESSING or PAID status participate. Exactly
60 minutes is included; anything older is excluded even if its configured payment
validity is longer or confirmation/settlement was pending.

Bootstrap starts at the oldest eligible recent order. Existing cursors retain their
historical started_at, but reads start no earlier than the rolling cutoff. Forward
reads still cover at most five minutes per invocation, and only successful verified
windows advance the stored cursor. Lookback, transfer processing and expiration
share the cutoff. No recent unfinished orders means no upstream head/discovery
requests for that address. Old unresolved orders are neither expired nor released
merely because they leave the automatic window; they require explicit administrator
handling. Manual hash verification/search and manual confirmation keep their existing
permission, receipt, amount, confirmation and audit requirements.

Deploy ScanTrc20TopupsAction, ProcessIncomingTrc20TransferAction and ScanTrc20Topups
command together and reload persistent PHP runtimes. No migration or frontend build
is required. Never reset cursors or clear cooldown caches during deployment.


## Direct recent-order scan (2026-10-10, supersedes five-minute cursor walking)

A lagging saved cursor previously restricted lookback to already-scanned history and
advanced by only five minutes per invocation. Platform search instead reads the
selected order's complete validity interval. Thus a scheduled command could finish
successfully while not yet querying a newer order's transfer time.

Automatic scans now query the merged validity windows of all eligible unfinished
orders directly through the confirmed head, independently of scanned_through.
The rolling one-hour creation cutoff and original started_at boundary remain; each
order retains its creation/expiry bounds. No empty historical forward query is made.
After successful reads without unresolved confirmations/settlement, scanned_through
advances monotonically to the confirmed head. Failures retain the previous progress;
future scans revisit still-unfinished windows even if progress is already current.
Overlapping windows remain batched/merged and share upstream cooldown. This neither
relaxes matching nor guarantees upstream availability or instantaneous settlement.

Deploy ScanTrc20TopupsAction, ScanTrc20Topups and DiagnoseTrc20Topups together.
Expected scanner revision: `2026-10-10-direct-pending-windows`. No migration,
frontend rebuild, manual financial scan, cache clear or cursor reset is needed.
`php artisan topups:diagnose-trc20` adds recent_unfinished_orders,
outside_automatic_window_orders, automatic_scan_cutoff and recent-order dates,
plus per-address recent counts, without contacting the provider or exposing identities.
The normal scheduler writes `TRC20 automatic scan completed` with window count,
credited/confirming/pending-settlement counts and scanner revision to application
logs. This is command completion evidence, not a promise that every payment matched.
Zero windows means no eligible time window was queried; use the read-only counts and
original start boundary to distinguish missing eligible orders from an unconfirmed head.
