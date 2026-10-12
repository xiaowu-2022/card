# Platform operating overview

Approved 2026-10-12. `/platform/demo` keeps its existing route and becomes a
full-platform operating dashboard. Platform root and authorized administrator login
land on it; identities without tenant.read retain their company-directory fallback.
The main overview is independent of the retained
historical-analysis company/date filters. Historical charts are collapsed by default
and keep their previous gross-withdrawal accounting, explicitly labeled separately
from the new net-payout overview.

## Confirmed amounts

- Stock, theoretical balance, actual balance and difference sum **currently enabled
  partner reports** across every company, including signed negative values. Disabled
  partners are excluded. Actual means the report's own USDT available-wallet amount,
  not all consumers' wallets or multi-asset valuation. Theoretical retains own annual
  expenses, classified/unclassified commissions, net advances/reimbursements and
  signed adjustments. Difference sums the existing report differences.
- Stock intentionally sums individual partner stock totals, including overlapping
  teams, as requested. Operational events and alerts are independently aggregated
  once per company/business record rather than summing overlapping partner reports.
- Reimbursements sum all existing reimbursement journals net of reversals.
- Recharge counts successful credited primary/multi-asset orders and their immutable
  actual receipt amount (original amount only for older receipt evidence). Withdrawal
  counts successful primary/multi-asset payouts **excluding fees**. Completion/credit
  times own the reporting dates; pending/unknown/failed operations are excluded.
- Card balance sums saved provider balances plus local overflow of all issued cards,
  including archived cards. Funding sums unique successful initial issue arrivals
  and LOAD arrivals plus locally funded overflow, excluding opening/load fees. Returns
  are not deducted from cumulative funding. Provider transaction copies never count.
- Third-party balance uses the same source as the provider directory: API-connected
  entries use the existing 60-second cached, verified PhotonPay merchant report;
  unconnected entries use manual `reference_balance`. Sum all active directory entries
  across pages, excluding deletion audit tombstones. USD/USDT follow the existing U
  presentation; no market conversion is introduced. Any missing or unsupported balance
  makes the entire total null (Unavailable), with no manual fallback or partial total.
- Partner/non-partner asset estimates partition all users by current enabled partner
  state; disabled partners are non-partners. Reuse AssetValuation with four native
  wallets/holds, USDT deposit, wealth principal and unarchived card/overflow funds.
  Saved fresh rates only; necessary unknown rates/card balances yield null. Aggregate
  native components before valuation and round the complete group once.
- Bubble remains null and its visual field is empty.

## Time windows and operating evidence

Platform calendar days use Asia/Kuala_Lumpur (UTC+8), matching historical overview.
Today runs from local midnight through the captured read timestamp. Three/seven/
fifteen/thirty-day figures are **sums including today**, not averages; the N-day window
starts N-1 days before today. Lifetime receipt/payout/funding sums are independent of
recent windows. T+1 describes the arrival rule, not a shift in reporting date.

The six operating columns reuse PartnerBusinessStock evidence: first successful
deposit activation, completed annual settlement, completed deposit refund, signed
activation/legacy commission, approved annual return and signed annual commission.
Only the two ordinary-member deposit columns exclude currently enabled partners;
other operating totals cover all company users. Disabled configurations remain ordinary
members. No historical balances or repeat funding are invented as first activation.

70% annual alerts reuse active-cycle weighted progress and outstanding paid amounts
after approved returns. Deposit alerts reuse CHECKING requests without cancellation
intent and saved eligible dates between now and the next 3/10 days inclusive. Undated,
already-overdue, completed/cancelled requests do not count; 3 days is a subset of 10.

## Read isolation and delivery

2026-10-12 presentation correction: remove repeated calculation, evidence-source and
deduplication explanations from the homepage, including collapsed history. Retain only
scope/unit/time metadata, T+1, rate timestamp when present, unavailable states and short
net/gross/reference qualifiers in labels. Accounting and permission rules are unchanged.

Active Platform scope and tenant.read remain mandatory; existing scoped permission
catalogs gate each section server-side. Partner/operation sections additionally require
users.read, wallet.read, ledger.read and partners.manage; assets require the first
three plus cards.read. Card funding/balances require cards.read and ledger.read;
provider references require provider_operation.read. Cash flows retain their existing
wallet_topups.read / withdrawals.read gates. Company administrators cannot use this
global endpoint.

Controller reads run in one repeatable-read/read-only snapshot and return private,
no-store responses. Never provision wallets/members, change user status, refresh
prices, contact chains or replay financial operations from this page. The only remote
read exception is the existing provider-directory merchant report for third-party balance.
All financial values remain decimal strings, formatted with insignificant zeros
removed; null is never rendered as a fabricated zero.

Deploy matching PHP and rebuilt public/build together. No schema migration, H5/App
rebuild or production financial/cleanup test is needed. Automated evidence lives in
the isolated card_ui_test fixtures and offline Chromium/WebKit overview fixtures.

Local verification: PartnerStockTest, PlatformDailyFundsTest, PlatformFundsSummaryTest,
AdminAuthenticationTest and PlatformUserFinancialsTest plus the new card/actual-receipt
overview scenarios pass. TypeScript and changed-file ESLint/Pint checks pass. The
offline browser fixture covers Chinese/English at 375/768/1440/1920px, exact large
and negative amounts, missing balances, a blank bubble, refresh and gated sections.
Full i18n checks retain five pre-existing failures outside this feature (obsolete
wallet/manual-receipt source assertions, a cleanup-dialog translation, a consumer
breadcrumb and a KYC error translation); relevant funds/overview checks pass.
