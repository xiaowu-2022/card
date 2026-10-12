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

## User list funds and card summaries (2026-10-11)

The user list excludes currently enabled same-company partners. Disabled partner
configurations remain user rows. There is no partner-status filter; legacy `partner`
query parameters are ignored and omitted from pagination links. Partner detail reads
and referrer candidates remain available through their existing scoped endpoints.
Its wide summary layout retains company/referrer filters, pagination, detail drawers and
subordinate actions, with fixed identity/action columns. Current-page identities
(rank and direct inviter) and financial aggregates are batched by company.

The status filter also offers `DEPOSIT_REFUNDING` (退押中): a correlated,
same-company/user refund request in `CHECKING` with no cancellation intent.
Waiting, freezing and blocked requests are included even past their saved deadline;
completed/cancelled requests and cancellation restoration are excluded. This is
a read-only filter over refund evidence, not a new user account status.

`AssetValuation` is shared by consumer `AssetOverviewQuery` and Platform summaries.
It includes available plus withdrawal/card-issue/card-funding holds in USDT, USDC,
ETH and BTC; USDT guarantee; wealth principal per asset; and unarchived cards'
provider USD balance plus their USDT overflow. Holds are explained separately but
counted once. Only fresh saved `MarketPrices::latest()` snapshots are used; USDT
needs no quote and the existing USD/USDT 1:1 convention applies to cards. Required
missing/invalid rates or unknown card balances return null, never guessed parity
or zero. Decimal calculations round the complete total once to eight USDT places.
The modal shows native components, USDT valuations, quote time (or read time when
no quote is necessary), and total. Opening card details switches the same modal
and permits returning to funds details, without stacking dialogs.

`GET /platform/tenants/{tenant}/users/{user}/funds-summary` and `cards-summary`
require active Platform users.read, wallet.read, ledger.read and cards.read.
They validate company/user ownership and run repeatable-read/read-only snapshots,
with private/no-store responses. The list omits estimated/card totals when any
required permission is missing. These reads never call a provider, synchronize
cards, fetch prices, provision wallets or modify financial evidence.

Card details use 20-row pagination and `archived=0|1` (unarchived by default).
Totals cover the complete selected scope, independently of the displayed page.
Cumulative funding = successful initial issue funding + successful LOAD arrival
and overflow funding, excluding opening fees. Unique business order rows are
aggregated before joins; service-provider transaction copies are not counted.
Returned funds = successful RETURN/CANCEL_RETURN arrival credited to wallets.
Current balance = cached provider balance + local overflow. Estimated consumption
including fees = funding - returned funds - current balance. Negative values are
retained. Any unknown current balance makes that card and the complete scope's
balance/consumption totals unavailable, while known funding remains visible.

Annual/activation commissions reuse signed classified PromotionReportQuery income
(including activation/legacy deduplication); annual expense uses completed,
Ledger-linked settlement_total (including deposit conversion and upgrade difference).
Wallet transfer receipts are summed separately by incoming/outgoing and asset.
Successful gross withdrawal requests include their fee and remain per currency.
No mixed-currency sums are shown except the explicitly valued funds estimate.

Deploy changed PHP/routes together with rebuilt public/build. No migration,
consumer rebuild or production financial/cleanup test is required.
