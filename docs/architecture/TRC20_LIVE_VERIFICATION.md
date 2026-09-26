# Live incoming USDT and SaaS rechecks

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
