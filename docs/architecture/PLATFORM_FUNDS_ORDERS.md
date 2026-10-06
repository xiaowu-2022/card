# Platform recharge and withdrawal lists

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
