# Wealth management — approved 2026-09-18

## Contract

USDT, USDC, ETH and BTC deposits are separate immutable orders. Fixed terms in months
and initial APR percentages: 1/6, 3/8, 6/12, 12/15, 24/16, 36/17, 60/18. Platform
`tenant.manage` alone configures each company's currency minimum and enabled terms/APRs.
Company administrators read only. Settings start absent/disabled; reads never seed rows.
Saving all four currencies is atomic, revision checked and audited. Enabling requires a
positive exact minimum. APR is nonnegative, up to eight decimal places, and APR * months
must be strictly below 1200 to guarantee positive early principal repayment.

New deposits require active Tenant/User/original currency Wallet, approved KYC and
sufficient available funds. No deposit/agent qualification is required. Orders snapshot
rate, term, minimum validation revision, source wallet, currency, company timezone,
start/maturity and complete monthly plan. Product changes never reprice existing orders.
Every user mutation uses a stable UUID and tenant/user-scoped lookup. Cancellation also
requires current password, explicit confirmation and an unchanged paid-interest preview.

## Accounting

All amounts use decimal strings and Money asset precision (USDT/BTC 8, USDC 6, ETH 18).
- Deposit: USER_AVAILABLE -> USER_WEALTH_PRINCIPAL.
- Monthly interest: TENANT_WEALTH_INTEREST_CLEARING -> original USER_AVAILABLE.
- Maturity: USER_WEALTH_PRINCIPAL -> original USER_AVAILABLE, full principal.
- Early cancellation: remove entire principal; refund principal minus actually paid
  interest to original USER_AVAILABLE and recover paid interest to company interest clearing.

The dedicated company interest clearing account records company expense and may be
negative. User principal never may. No interest is funded from another user's principal.
No historical posting is edited, including recovered interest. No activation, commission,
deposit or card business flows are invoked. Aggregate asset valuation includes in-contract
principal once; paid interest is already in available balances. Projected interest is excluded.
Cumulative paid-interest reporting is historical receipts, not a spendable balance; order
cancellation details separately show recovered interest and actual returned principal.

Every operation posts via LedgerWriter in its outer transaction. Locks follow Tenant ->
User -> order (where existing) -> wallet -> LedgerWriter sorted accounts -> derived rows.
Transactions retain locks through commit. Deferred database evidence validates each order,
installment and referenced ledger entry against exact amounts and ownership. Order snapshots,
installment economics and terminal history cannot be changed or deleted.

## Calendar, recovery and concurrency

Monthly dates are computed independently from the original timestamp in the snapshotted
company timezone using calendar months with end-of-month clamping. Thus Jan 31 -> Feb
28/29 -> Mar 31. Stored dates are UTC. Monthly interest is cumulative principal * APR *
month / 1200 truncated to asset precision minus previous cumulative amount. Zero installments
are recorded as settled without zero-posting entries. No compound interest or auto-renewal.

`wealth:recover` runs every minute under the existing scheduler, processes explicit tenant/
order pairs, and recovers all due installments oldest first followed by maturity principal.
Each order transaction is atomic, with stable ledger event keys. Product disablement and
later user/company suspension do not erase obligations. Unavailable receiving wallets or
ledger accounts are never reactivated; the transaction rolls back and remains pending for
retry, with a sanitized warning and a failing recovery exit status.

Cancellation and recovery lock the same order. Before maturity, cancellation forfeits all
unpaid installments and recovers only actual paid amounts. At/after maturity, cancellation
executes normal maturity settlement instead. Repeated or concurrent requests cannot return
principal twice. A changed preview requires review again. Reads never settle funds.

## UI and deployment

Assets keeps Wealth management last after BTC and hidden while collapsed. `/wealth` shows
currency balances/products and paginated deposits; `/wealth/orders/{id}` shows the full
monthly history and cancellation confirmation. POST `/wealth/orders` creates the deposit;
POST `/wealth/orders/{id}/cancel` cancels the entire order. Company configuration lives at
`/platform/tenants/{tenant}/configuration/wealth`; `/admin/wealth` is read-only.

The fixed notice says: 所有利息每个月一结自动结算到余额，提前支取利息扣回。
Additional copy explains principal minus paid interest, automatic principal return and no
compound interest/renewal. Consumer copy covers en/zh-CN/ms/es; Admin covers en/zh-CN.

Deployment requires the new migration and existing every-minute `php artisan schedule:run`.
Migration introduces structure only: no fund movements, orders, enabled products or historical
replay. SaaS explicitly enables each company's desired currency/term after setting minimums.
Testing is isolated to card_ui_test, including independent PostgreSQL-session races; no real
financial testing, production funds or provider operations are required.

## Wealth overview

`GET /wealth` is a read-only four-currency overview. `GET /wealth/assets/{asset}`
contains deposits and paginated orders; only USDT/USDC/ETH/BTC are accepted.
Principal uses the actual USER_WEALTH_PRINCIPAL balance. Lifetime net earnings
are settled interest minus cancellation clawbacks. Both are valued separately in
USDT using the same fresh saved market snapshot, with no upstream calls on reads.
A missing required price hides aggregate estimates and allocation percentages;
original-currency values remain visible. Zero holdings require no prices.
The last twelve calendar months use the company's current timezone and actual
settled_at/closed_at timestamps, including delayed payments in their receipt month.
Monthly paid, recovered and net figures remain in original currency; a clawback
can produce a negative month. All money arithmetic remains server-side decimal;
SVG geometry consumes only normalized ratios. Reads never provision accounts or
settle deposits. No schema migration or financial policy change is introduced.

The consumer overview prioritizes a compact valuation header and a two-column
currency grid before its analytics. Each currency exposes annualRateMin/Max from
that company's enabled terms only (null when none are enabled); this is display
information, not deposit authorization. Six- and twelve-month net-income chart
scales and signed ratios are calculated with server-side decimal arithmetic.
The UI defaults to six months and the current month, supports keyboard selection,
and retains original-currency paid/recovered/net month details. Empty principal
and unavailable valuation have distinct states; disabled products retain history.

The latest consumer design supersedes the analytics-heavy overview above: the
homepage shows deposited principal valuation, the next expected interest time
and original-currency amounts, and four currency tiles containing only name,
principal and cumulative net earnings. Tiles still open currency details.
The next payment is the earliest positive unsettled installment across ACTIVE
orders scoped to the tenant/user; matching timestamps aggregate by currency.
Past-due unpaid installments remain visible as pending, never fabricated receipts.
No upcoming installment is an explicit empty state. Reads never settle funds.

Each overview currency tile is a non-interactive container with three separate
links: details, deposit, withdraw. The currency route accepts the validated
`view=details|deposit|withdraw` parameter (default deposit) and preserves it in
pagination. Details lists all scoped orders; withdraw filters ACTIVE orders with
matures_at strictly after now. Selecting a withdrawal order opens the existing
password/acknowledgement cancellation review via `?view=withdraw`; this GET never
moves money, and confirmation rechecks all existing financial eligibility.

Currency details use compact currency selection, current principal/net-income
summary and individually linked deposit cards with explicit detail/withdraw affordances.
Net income matches overview paid interest minus cancellation clawbacks. DTO
`displayStatus=AWAITING_SETTLEMENT` is derived when ACTIVE has reached maturity;
it never mutates stored order status or permits early cancellation. All records
remain navigable, including matured/cancelled orders; withdrawal lists retain
the existing ACTIVE and strictly-before-maturity filter.
