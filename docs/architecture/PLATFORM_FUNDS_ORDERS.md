# Platform recharge and withdrawal lists

## Order-scoped TRC20 automatic search (2026-10-10)

The recharge detail drawer's Recheck transfer panel supports both Verify by hash
and Automatically search for TRC20 shared-address USDT orders. Search does not
require a hash. Both actions require explicit order confirmation; the explanation
states that an exact, confirmed match can credit the wallet. Opening a drawer or
panel never calls the chain. Other deposit rails retain their existing hash flow.

The existing scoped `POST /platform/tenants/{tenant}/topups/{topup}/verify` accepts
`verification_mode=SEARCH` with no `tx_hash`; missing mode remains HASH for old
clients. HASH still requires a valid 64-character hash. The server owns address,
token, exact expected amount and creation/expiry bounds, and rejects transport
overrides. Search ends no later than now or the immutable expiry. Existing matched
receipts are looked up directly and never replaced. Multiple distinct exact
receipt events return AMBIGUOUS without settlement, asking the administrator to
specify a hash. Insufficient confirmations and no match also do not credit funds.

Use the existing Platform `wallet_topups.verify` permission, CSRF, throttle,
actor status/membership checks, tenant/order lookup, audit and settlement action.
The immutable actor/request audit binds SEARCH versus HASH as well as company,
order and hash where applicable; historical intents without a mode remain HASH.
The UI maintains separate search/hash request UUIDs and changes the hash UUID
when the input changes. All settlement continues through exact receipt validation
and the original idempotent Ledger credit. Explicit selected-order expiry review
keeps the existing rules; scheduled scanning is not broadened to closed history.

Public-reader failures and HTTP 429 stay unconfirmed and use the shared cooldown.
Automatic search cannot bypass an upstream outage. Deploy the updated verification
action/controller and compiled `public/build`; no migration or H5 build is needed.
The accompanying TRC20 rate-limit mitigation must also be deployed if absent.
Acceptance uses isolated synthetic backend orders and a fully intercepted offline
browser fixture, `tests/Browser/topup-auto-search.mjs`; no real order is verified
or credited during implementation.

## Withdrawal export (2026-10-06)

The Platform withdrawal header offers a CSV download of all records matching the
applied company/account/order/currency/network/status filters, independent of the
current list page. Both withdrawal sources share the list query and ordering.
At most 10,000 rows can be exported per request; larger results fail without a
partial file and require narrower filters.

The file includes company, account/email, full order ID, asset/network, gross
withdrawal, fee, net payout, full destination, status, request/completion timestamps
in the company timezone, and transaction hash. Additional columns show lifetime
successful gross/net withdrawal totals and count in the row's currency, current
effective agent rank (0 when none), and current enabled partner status. Totals
combine TRON SUCCEEDED and asset COMPLETED orders across networks, ignoring list
filters, within the same company/user/currency. No FX conversion is performed.
Agent rank reuses ManualPromotion's effective paid/manual projection.

POST /platform/asset-withdrawals/export requires withdrawals.read and
withdrawals.review, the current administrator password, explicit confirmation,
CSRF and throttling. Full addresses reuse the existing encryption protector;
ordinary list GETs stay masked/read-only. Per-company WITHDRAWALS_EXPORTED audit
evidence contains source/order IDs and counts, never passwords or full addresses.
Generate entirely in memory before returning a no-store attachment, with UTF-8
BOM, escaped CSV cells and formula-injection protection. Decimal strings retain
all significant digits; import amount/account/hash columns as text in spreadsheet
software to avoid its automatic numeric conversion.

No money, wallet, order, promotion or partner changes, chain/provider requests,
migration, or H5 rebuild are involved. Deploy PHP with rebuilt public/build.
Offline acceptance: PlatformWithdrawalExportTest and platform-withdrawal-export.mjs.

2026-09-27. SaaS administration is accepted on desktop, per user instruction. This does not change consumer H5/mobile requirements.

## Navigation and reads

- Recharge orders (`/platform/topups`) combine `wallet_topup_orders` and `asset_deposit_orders`.
- Withdrawal orders (`/platform/asset-withdrawals`) combine `withdrawal_orders` and `asset_withdrawal_orders`.
- Asset settings remain at `/platform/settings/assets`; wallet balances remain a separate menu.
- Old `/platform/asset-deposits` links redirect to recharge orders with query filters preserved. Old `/platform/asset-tron-withdrawals` links redirect to the unified withdrawal list filtered to TRON.
- Company-specific recharge links enforce the company in the route over the query parameter.
- `PlatformFundsQuery` uses SQL UNION ALL before filtering, sorting and pagination. Sorting is by business creation time descending, then source and ID. There are 25 rows per page. No source is independently paginated or truncated before combination.
- Company, account ID/email/order search, currency, network and status filters are validated server-side and retained through pagination. Historical USD records remain readable. Exact amounts and fees are strings, with insignificant trailing zeros removed only for display; no two-decimal rounding or JavaScript Number conversion.
- Unassigned review evidence remains visible only on the unfiltered deposits view, since it does not have an authoritative company owner.

## Details and operations

The details dialog includes full order ID, account/email, original amount, network, timestamps, operator name and existing audited operations. Email cells have a fixed width; operator IDs are not rendered in the list. The action column remains available while scrolling the table horizontally.

The union changes only the query/presentation. The original tenant/order-scoped POST endpoints handle manual receipt, chain verification, withdrawal review, address reveal and payout verification. Platform session, permissions, CSRF, confirmation, throttling, audit and idempotency remain unchanged. Withdrawal addresses remain masked; the existing sensitive reveal flow is retained. GET never queries a chain/provider or changes balances/orders/Ledger.

TRON `SUCCEEDED` is displayed as `COMPLETED`. `VERIFYING` remains distinct from `UNKNOWN`. No order statuses are rewritten. Asset settlement time is taken from its linked Ledger entry, not a mutable updated timestamp. TRON retains its original recorded credit/chain-confirmation time.

Sidebar and asset navigation use existing permission names and current-page highlighting. No new permissions, database migration, data consolidation or financial replay is required for deployment; deploy PHP and rebuilt React assets together.

## Manual receipt classification (2026-10-06)

Both manual confirmation endpoints accept optional `receipt_type=ACTUAL|ADVANCE`;
absence means ACTUAL. The existing order amount and currency remain server-owned.
ADVANCE requires wallet_topups.confirm plus partners.manage, an enabled same-company
partner owner and USDT. Confirmation atomically credits the wallet once, stores the
immutable manual_receipt_type/advance_journal_id, and appends a PartnerManagement
ADVANCE journal for the exact credited amount. The journal uses the company-local
confirmation date and a generated order reference note. ACTUAL creates no journal.
No chain evidence is fabricated. Same-request type changes fail idempotency checks;
an already credited order never acquires a new classification or advance, including
when chain settlement wins first. Existing journal reversals remain informational
and do not debit the wallet.

Only Platform DTOs expose classification; consumer serialization hides both fields.
Consumer successful deposit pages/activity/notifications use Actual receipt; existing
partner reports retain Advance labels. Historical null classifications remain null
in storage and display as actual receipts, without financial or journal backfill.

Deploy migration 2026_10_06_120000_add_manual_deposit_receipt_type before the new
application/admin build, then publish the compiled H5 at public/h5. Keep old hashed
H5 assets during publication. Do not roll back classification columns after real
classified receipts without preserving their audit associations. Verification uses
ManualDepositReceiptTest in isolated card_ui_test only, never real transfers.

## Actual manual receipt amounts (2026-10-08)

Both manual receipt endpoints now require `actual_received_amount` as a positive
plain decimal string within the asset's Ledger precision and 12 integer digits.
The input starts empty. Check amount opens a confirmation dialog showing original
order amount, actual received amount and actual minus order. Return to edit retains
the input and classification; only Confirm receipt sends the idempotent POST.

`actual_received_amount` is separate immutable settlement evidence. Original amount,
expected/requested amount and chain reservations remain unchanged. Existing
LedgerWriter flows credit the actual amount, with matching ADVANCE journals and
atomic actor/request/original/actual/difference audit. Same-request amount/type
changes fail; chain/manual races still credit once. Historical rows remain null and
use their original amount. Consumer receipts, notifications, support totals and
Platform inflow aggregates use the actual settlement amount when present. Legacy
consumer receipt DTO `expectedAmount` also returns the settled amount for manual
receipts because installed clients render it on completion; `orderAmount` retains
the original instruction. Pending payment instructions and database matching are unchanged.

Deploy `2026_10_08_180000_add_actual_deposit_receipt_amount` before the matching PHP
and rebuilt admin assets. Do not drop receipt evidence after real settlements;
application rollback must preserve the column and actual-amount settlement logic.
No historical replay or consumer rebuild is required.

## Receipt type display and corrections (2026-10-11)

The recharge list and detail drawer show Receipt type: Automatic receipt, Manual
confirmation or Advance amount. Pending/uncredited orders show no arrival type.
Automatic receipts retain their verified chain origin and cannot be reclassified.
Credited manual USDT receipts can switch ACTUAL/ADVANCE with explicit confirmation
and a server-owned revision. A new advance still requires an enabled same-company
partner; switching an existing advance back to manual also works after partner
status is disabled. The native settled amount, including actual_received_amount,
is server-owned and cannot be edited by this operation.

POST /platform/tenants/{tenant}/deposit-orders/{source}/{order}/receipt-type handles
both primary and asset sources. It requires wallet_topups.confirm and
partners.manage, the active Platform scope, CSRF and throttling. Tenant -> User ->
Order -> Partner locks serialize changes with settlements and partner updates.
Request UUIDs are actor-bound, reject changed inputs and remain no-ops after later
switches. Stale revisions fail without any journal or money changes.

Original manual_receipt_type, advance_journal_id, confirmation actor/time, amount,
Ledger and chain evidence remain immutable. Append-only deposit_receipt_type_changes
records before/after type, revision, exact settled amount, actor/request and scoped
journal associations. The latest event is the current classification projection.
ACTUAL -> ADVANCE appends one matching positive advance; ADVANCE -> ACTUAL appends
a matching reversal, or references an already recorded reversal. Switching again
creates a new advance rather than rewriting any journal. Journal/evidence/audit
writes commit together. Database guards reject changes to history, wrong-company
order/journal associations, uncredited/automatic receipts and stale revisions.

Order DTOs, user receipt totals and company USDT inflow splits use the latest
classification; company inflow combines both deposit sources. Partner personal
reconciliation reads the same signed journal evidence. Successful changes refresh
the order list and open details through Inertia, retaining filters, pagination and
scroll; dependent read-only summaries re-read current data. Actual wallet balances,
Ledger postings, promotions, business stock and original consumer receipts are not
changed or replayed. The immutable financial operation history includes each switch.

Deploy migration 2026_10_11_100000_add_deposit_receipt_type_changes before matching
PHP and rebuilt public/build. No H5/native rebuild or historical backfill is needed.
Do not drop the new evidence after production corrections. Verify with isolated
ManualDepositReceiptTest and the fully intercepted deposit-receipt-type.mjs browser
fixture; never use production orders for financial tests. This supersedes the prior
prohibition on post-credit classification changes only for this explicit workflow.
