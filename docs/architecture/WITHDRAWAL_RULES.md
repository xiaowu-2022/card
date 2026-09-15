# Withdrawal Rules

Phase 7 implements one fixed rail: USDT on TRON (TRC20). The User never selects Tenant, User, Wallet, asset, network, fee, or a hold amount. The fixed-fee extension below supersedes the historical zero-fee exclusion only. FX, bank/fiat rail, multi-chain routing, batch payout, private-key custody, automated sending, manual success control, and Security Deposit refund remain outside this flow.

## Fixed company withdrawal fee (approved 2026-09-13)

Company Settings / Business rules exposes `withdrawal_fixed_fee`, protected by the
existing exact-company active Admin membership and `tenant_settings.manage`.
It is a non-negative USDT decimal with at most two decimal places, default 0.
Changing it never moves money; omitted configuration fields preserve the saved fee.

Entered amount is gross. New Orders snapshot immutable `fee_amount`; PostgreSQL
generates `receive_amount = amount - fee_amount`, strictly positive. The additive
migration leaves old Orders at fee 0 / original payout and never rewrites Ledger.
Both database and model guard fee immutability. Migration is forward-only.

User preview and confirmation show fee and net payout. `expected_fee` acknowledges
the displayed quote, never sets the fee. Creation under the Tenant lock compares
it to persisted settings; changed quotes fail atomically before any hold/address
persists and require review again. Old clients without a quote can create only
zero-fee Orders. V2 idempotency hashes bind the canonical quote; legacy V1 hashes
remain valid for retries. Existing intent is checked before current settings, so
retries never change Order economics. No network call occurs while holding locks.

Hold and release use gross. Only independently confirmed exact **net** on-chain
payout permits settlement: hold -gross, clearing +net, fee revenue +fee (omitted
for zero fee). Pending/unknown/mismatch preserves gross hold; cancellation or
rejection releases all gross and earns no fee. Existing deterministic event keys
ensure duplicate verification cannot earn duplicate revenue. Company fund-book
withdrawals show net external payout, with fee revenue separately and unique rows.
User history/details and Admin review expose the immutable fee/net amounts; cancelled
and rejected User detail displays zero charged/received. Admin sending instructions
use net, never gross. No live payout is authorized by implementing this setting.

Acceptance (2026-09-13): 151 related Withdrawal/Ledger/Payment/Promotion/Tenant/
Admin/architecture tests passed (973 assertions); 39 frontend tests, typecheck,
lint and build passed. Browser verified both amount preview and the company fee
field without submitting any withdrawal or changing the default 0 fee. The new
migration was applied only to local `card_mock`; no historical jobs or live payout
were executed. Repeated isolated database rebuilds preserve migration compatibility.

## Eligibility and destinations

Creation requires an ACTIVE Tenant, enabled Tenant withdrawal setting, ACTIVE User, APPROVED KYC, ACTIVE USDT Wallet, ACTIVE destination owned by the same Tenant/User, and enough USER_AVAILABLE funds. Web scope always comes from Host -> Tenant Domain -> Tenant Context. Client `tenant_id`, `user_id`, `wallet_id`, `asset`, and `network` are prohibited.

TRC20 destinations are normalized and format-checked before persistence. The full address is AES-256-GCM encrypted with a dedicated stable key; a dedicated keyed HMAC prevents duplicates without plaintext lookup. Ordinary models, User/Admin lists, Inertia props, audit, and logs expose only the stored mask. Destination address identity cannot be edited. Admin reveal requires `withdrawals.review`, the exact Tenant scope, recent password authentication, throttling, and a sanitized audit event that excludes the address.

## Order and Ledger lifecycle

### Direct-entry form and history (approved 2026-09-13)

The user enters a TRON address and a two-decimal amount in one form, reviews the
full locally entered address/amount and an irreversible-transfer warning, then
explicitly submits a withdrawal request. This is not immediate automated payout.
`POST /wallet/withdrawals` accepts either a typed address with `confirmed=true` or
the legacy owned destination UUID, never both. The existing destination endpoint
remains compatible but is no longer a prerequisite or address-book step in the UI.

`CreateWithdrawalAction::executeWithAddress` takes the same request advisory lock
before Tenant/User locks, creates/reuses the encrypted destination and composes
the existing order+hold action within one transaction. Invalid amount, eligibility
or insufficient balance rolls back the destination and its audit too. Retries bind
the normalized scoped address HMAC and exact amount to the original destination
and order; changed address/amount is a conflict, never a second hold. All immutable
destination, Ledger, review and chain-verification constraints stay unchanged.
Raw `address` is excluded from session old-input and redacted from structured logs.
It stays only in the unremembered browser form until submission and is not returned
as a server prop; stored destinations remain encrypted and immutable.

The user approved a separate history subpage on 2026-09-13. The form's upper-right
Withdrawal history link opens `GET /wallet/withdrawals`; the form no longer queries
or embeds history. The authenticated history route is ten-per-page, newest-first,
scoped by both resolved company and user. DTOs expose only order id, decimal
amount/asset, fee/net snapshots, masked address, presentation state and request time.
Every row links to the ownership-checked detail route, whose back link returns to
history. The history header returns to the withdrawal form. Pagination stays on
the history route and never creates orders or changes funds. No fabricated records
or default success states are introduced; raw addresses are never remembered.

Acceptance: 77 withdrawal/wallet/database-integrity/redaction/architecture tests
passed (447 assertions), plus 38 frontend tests, typecheck, lint and build on
2026-09-13. The current browser shows the direct form and empty history without
creating a withdrawal. Tests used isolated `card_ui_test`; no live payout occurred.

`PENDING -> APPROVED -> VERIFYING -> SUCCEEDED` is the successful path. A User may cancel only PENDING; an authorized Tenant Admin may reject PENDING or APPROVED only before a transaction hash is submitted. APPROVED keeps the full hold. REJECTED/CANCELLED release it exactly once. SUCCEEDED is permanent.

Creation is an idempotent, locked database transaction. `(tenant_id, request_id)` identifies the intent and a canonical hash binds User, destination, amount, USDT, and TRON. The same payload returns the original Order; changed content fails with `IDEMPOTENCY_CONFLICT`. The Order and exact Ledger event commit atomically:

```text
WITHDRAWAL_HOLD:    USER_AVAILABLE -amount, USER_WITHDRAWAL_HOLD +amount
WITHDRAWAL_RELEASE: USER_WITHDRAWAL_HOLD -amount, USER_AVAILABLE +amount
WITHDRAWAL_SETTLE:  USER_WITHDRAWAL_HOLD -amount, TENANT_WITHDRAWAL_CLEARING +receive_amount,
                   TENANT_FEE_REVENUE +fee_amount (only if positive)
```

All events use decimal `NUMERIC(20,8)` values and deterministic `withdrawal:{order_uuid}:{hold|release|settle}` keys through `LedgerWriter`. There is no generic balance/hold/release interface and no Admin financial override.

## Manual sending and verification

An authorized Admin sends the transfer outside this application, then submits its TRON transaction hash. `BlockchainGatewayInterface` verifies a successful USDT TRC20 transfer to the decrypted expected destination for the exact snapshotted net amount and minimum confirmation count. Gateway I/O occurs between two short database transactions and never inside the Ledger transaction.

Pending confirmation remains VERIFYING with its hold intact. Failed, wrong destination, wrong amount, and wrong token return the Order to APPROVED without settlement so an Admin can send correctly and submit a new hash. Every attempted `(network, tx_hash)` remains uniquely registered; a failed hash cannot be reassigned, and one hash can never settle two Orders. Duplicate verification of the same valid hash is idempotent. Timeout/unavailable is not success or failure and never releases or settles funds.

The Mock gateway is available only in local/testing. Production binds an unavailable adapter until a real read-only blockchain verification adapter is installed. There is no production route to mark success, modify balance, or choose a hold amount.

## Future deposit direction

Future V1 Top-up uses a monitored USDT-TRC20 deposit address: confirmed blockchain transfer -> PAID -> the existing exact `CreditWalletTopupAction` -> CREDITED. Existing checkout Provider settlement remains supported until that separately scoped migration. Withdrawal remains manual send plus independent blockchain verification.
