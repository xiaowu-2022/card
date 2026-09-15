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

## Automatic progress and deployment gate

`trc20_scan_cursors` is shared-address operational progress, not a balance or financial
state. Its id hashes network/asset/address; immutable configured `started_at` and
monotonic `scanned_through` are timestamps. Default live scanning is OFF. An operator
must choose `TRON_TOPUP_SCAN_START_AT` in UTC `YYYY-MM-DDTHH:MM:SSZ` explicitly; the
cursor refuses changing that start later. The scanner does not settle orders created
before that boundary. An intentional SaaS check of one historical order is a separate
explicit action and does not run history in bulk.

Each scan processes at most five minutes, stopping at a solidified block with the
required confirmation depth. It advances only after the complete paginated window
and its processing succeed; failure leaves the checkpoint intact for idempotent
replay. No HTTP occurs in a database transaction. Concurrent scans are safe through
Order/event locks and compare-and-update progress. Only orders created after the
configured start and expired before the verified checkpoint may expire automatically;
unseen/uncertain reservations remain held. Transaction-indexer lag or upstream data
omissions still require monitoring and targeted rechecks; offline tests are not proof
of the provider's live indexing completeness or service availability.

Production prerequisites (NOT executed implicitly by implementation):

- A company/default/Wallet/Deposit asset configuration of USDT. Existing USD Wallets
  are immutable and are not converted or deleted by this work.
- Correct shared `TRON_USDT_DEPOSIT_ADDRESS` and official `TRON_USDT_TOKEN_CONTRACT`.
- `BLOCKCHAIN_GATEWAY_DRIVER=trongrid`; `TRONGRID_API_KEY_ENCRYPTED` must contain a
  Laravel Crypt-encrypted API key under the deployment APP_KEY. No plaintext key,
  placeholder production secret or reveal endpoint is provided. Keep APP_KEY stable.
- Explicit start time, then `TRON_TOPUP_SCAN_ENABLED=true`. Review pending historical
  orders and chosen start before enabling. Do not reset persisted progress to replay.
- Run only the selected deployment's `php artisan topups:scan-trc20` every minute
  initially. Do not blindly start existing general recovery queues/scheduler: they
  may contain unrelated historical Card/Payment work. Alert on nonzero exit, stalled
  cursor, pending orders and provider indexing/rate limits.
- Test a specifically authorized live payment separately, with company/account,
  amount cap and expected result. Keep private API-key headers out of HTTP telemetry.

Sources checked during implementation:
- [TRON incoming history API](https://developers.tron.network/reference/get-trc20-transaction-info-by-account-address)
- [TRON integration and solidified receipts](https://developers.tron.network/docs/exchangewallet-integrate-with-the-tron-network)
- [Tether supported protocols](https://tether.to/es/supported-protocols/)

## Offline acceptance

141 related backend tests / 603 assertions, 18 architecture checks and 36 frontend catalog/behavior tests
passed on 2026-09-13. Coverage includes real-adapter HTTP fakes through final Ledger
credit, exact receipt decoding, wrong token/destination/hash, insufficient confirmations,
duplicate scans/manual requests, cross-company rejection, company-admin denial, API
timeouts/rate limits/redirects, explicit start gating, historical-order exclusion and
checkpoint replay with timestamp precision. No real transfers, live receipts, existing
financial settlements or general background workers were executed. The isolated
`card_mock` database received only the new permission/checkpoint and request-index migrations; its
existing USD Wallet and balances were not altered.
