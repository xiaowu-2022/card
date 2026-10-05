# Platform user fund flows

Entry: Users → More actions → Fund flows. A lazy right drawer retains list filters,
pagination and scroll. Its company/user and filter state use `funds_*` URL parameters;
closing removes only these parameters. Do not add a separate wallet-management menu.

`GET /platform/tenants/{tenant}/users/{user}/funds` requires active platform
`users.read`, `wallet.read` and `ledger.read`. A user outside the path's tenant returns
404. JSON is private/no-store and explicitly projected; no raw models, metadata,
credentials or provider responses. Opening the view never provisions a wallet.

`PlatformUserFundsQuery` reads a repeatable-read, read-only snapshot: current account
balances (grouped by currency and account type), distinct event filter options and
25 sealed Ledger entries per page. Every entry must have an owned posting. All joins
enforce tenant/user ownership; tenant clearing and other users' postings are omitted.
One entry appears once even when it moves both available funds and security deposits.
Different lifecycle entries (hold/settle/release) remain distinct actual postings.
Amounts retain native precision. Do not sum different account movements or currencies
into a misleading overall income/expense figure. Card-provider transactions are not
wallet Ledger entries and remain in the card transaction view.

Optional `asset`, `event`, `from`, `to`, `page` filters apply in the database. Date
bounds mean the company's start-day midnight inclusive and next-day midnight after the
end date exclusive, bound with explicit timezone offsets. Display times use the same
company timezone. History without filters has an explicit no-posted-events empty state;
filtered emptiness does not assert that the user never transacted.

Reuse `AssetActivityDetails` for business reason, reference and same-company sender /
recipient email; preserve commission classification. Read queries never synchronize
providers, replay business operations, fix balances, or infer an unrecorded deposit.

Validation: PlatformUserFundsTest covers empty wallets, owned postings, cross-company
and same-company isolation, all required permissions, stable pagination, date boundaries,
ETH native precision, no writes and no external requests. WalletTransferTest covers both
sides' counterparties. Deploy backend and rebuilt admin assets together. No migration,
consumer H5 build or APK packaging is required. This work does not deploy production.
