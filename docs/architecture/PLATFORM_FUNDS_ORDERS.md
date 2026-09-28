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
