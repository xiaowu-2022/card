## Partner daily trends restored (2026-10-08)

Partner stock pages in App/H5 and Platform display today plus the preceding
3/7/15/30 complete company-local calendar-day averages, including zero days.
The user selected these summary statistics rather than a daily line chart.
This supersedes the historical suppression of partner trends below.

`PartnerBusinessStock::trends` reuses the business contribution/deduction evidence
and current non-partner descendant scope used by stock totals. It excludes the
owner and enabled descendant partners personally, while retaining their branches.
Posted activation/legacy, annual and unclassified commissions retain signed manual
adjustments and beneficiary scope. Annual settlements, approved returns and signed
cooperation reimbursements reuse the exact stock evidence and posting timestamps;
reimbursements retain their separate owner/all-descendant-partner journal scope.

The deposit stream is first DEPOSIT account activation funding only, linked to
positive postings in the same user's USDT security-deposit account. It is activity,
not historical stock or balance snapshots: later conversions/refunds do not erase
the first-funding event, and repeated funding/supplements are not counted again.
No closing-stock history is invented. The seven streams remain separate and must
not be added together as a historical stock balance.

Existing 70%-progress/remaining-return/expired-pending alerts, detail pagination,
personal reconciliation and team advances remain unchanged with their existing
team scope. Reads use the report's read-only snapshot and timestamp, with no
migration, provider calls, pricing, wallet writes or financial replay. Deploy PHP
and matching Platform/H5 assets; native App changes require repackaging.

## Personal annual fees as negative advances (2026-10-06)

Personal `accountBalance.advances` equals the owner's net ADVANCE journal amount minus
completed, Ledger-linked agent annual-fee `settlement_total` in the same company/user
scope. Include converted deposits and each paid upgrade difference once; unpaid quotes
and other users' fees do not count. This is a read-only lifetime projection of existing
orders, independent of when partner status was enabled. Annual returns keep their existing
rules and are not netted against this purchase deduction.

Only personal reconciliation changes: theoretical balance uses these adjusted advances,
and difference remains theoretical minus actual. Team `totals.advances`, journals, business
stock, standard stock, wallet balances and Ledger evidence remain unchanged. No migration,
backfill or additional debit is required. Platform and App/H5 share this calculation.

# Partner stock reports and cooperation journals

Approved 2026-09-25. Local implementation only; no live partner designations, expense entries, historical financial changes, deployment or payment execution.

## 2026-10-05: business-funded partner stock (current; supersedes external-flow rules below)

Partner stock now equals **eligible held security deposit balances + completed annual
fee payments − paid activation/legacy commissions − paid annual commissions − approved
annual returns − net cooperation reimbursements**. `PartnerBusinessStock` supplies both
totals and paginated details from the same company-scoped union in the report's read-only
snapshot. `PartnerStockTeam` excludes the owner and enabled descendant partners personally,
while traversing through them to retain their non-partner descendants/current direct branch.

Security deposits use actual USDT `USER_SECURITY_DEPOSIT` balances. Converted or refunded
principal no longer lives there; annual fees use completed, Ledger-linked `settlement_total`
(including converted deposit) once. Commissions reuse the deduplicated posted income query,
restricted by **beneficiary**: the current team's non-partner descendants. This supersedes
source-scoped costs, regardless of where the original payer now belongs. Exclude the owner
and currently enabled descendant partners, but retain non-partner members below them.
Include posted signed manual commission adjustments in each beneficiary's net income;
unclassified income is shown as other commissions. Do not infer a source payer.
Details identify the recipient and their current first-level branch. Actual posted amounts,
legacy deduplication and historical financial records remain unchanged.
Returns must be approved and Ledger-linked. Reimbursements include the owner's and all
descendant partner journals, including disabled configurations, with reversals subtracted;
advances remain informational. Wallet top-ups/withdrawals, withdrawal fees, card/wealth
movements and market prices do not affect partner stock. No public price request is needed.

The two existing `flow=inflow|outflow` links now mean contribution and deduction details;
`totals.inflow/outflow` are business subtotals and `cashFlow` is null. `stockBasis` is
`BUSINESS_CONTRIBUTIONS`. Per-item `source` identifies the component. Guarantee detail rows
are current balances (nullable `posted_at`, explicitly labeled), not invented transactions.
Own reimbursement entries have no direct branch; commission entries use beneficiary account/email; other entries retain source account/email
and the current direct branch. No internal notes or staff fields are added to these details.
Both UI surfaces show the six components plus two clickable subtotals and the formula.
Standard reports, personal reconciliation and independent alerts remain unchanged.

No migration, provider call, actual financial operation or historical correction. Publish
matching PHP, admin and H5 assets. Repackage native App resources for these UI changes.
Older App builds must be updated: their cached external-flow wording does not describe
this new business-stock response. Tests cover external-money exclusion, conversion,
source commission, expense reversal, status-based scope, pagination and read-only access.

## 2026-10-04: two identity-selected versions (supersedes the access/formula rules below)

Authenticated company users can open stock reports. The server selects `version=partner` only for an enabled partner configuration in that company; all other users receive `version=standard`. Request parameters cannot choose a different identity or version. SaaS uses the same report service for the selected company/account.

- Partner: **non-partner descendant external deposits − non-partner descendant gross withdrawal requests**, using completed financial evidence only. Exclude the report owner even if a corrupt referral cycle leads back to them; exclude each currently enabled same-company descendant partner personally, while retaining traversal through them and including their non-partner descendants. Merge credited legacy/TRON top-ups and multi-asset deposits, successful legacy/TRON withdrawals and completed multi-asset withdrawals. Require matching company/currency Ledger evidence. Pending/failed/cancelled orders, internal transfers, card reloads, commissions, annual fees, guarantee movements and offline journals do not change this stock.
- Value native totals using **one fresh public OKX spot quote per read** (USDT=1; USDC/ETH/BTC use their direct USDT rates). Show rate observation time and native inflow/outflow/rate breakdown. This is a current-market estimate and changes with prices; it is not historical realized profit. Quotes are read-only, never saved to market snapshots. Invalid/unavailable rates make total, share and converted totals unavailable, with native amounts retained. No stale or parity fallback. USDT-only/empty reports need no upstream quote.
- Standard: preserve `annual + deposits + fees - activation - annualCommission - rebates - reimbursements`, scoped to the owner and descendants, including the existing fixed withdrawal-fee valuations. This path makes no market calls. Ordinary consumers receive no cooperation journal notes/operator identities, no reference share, and no partner-only personal reconciliation.

Partner personal account reconciliation and team alerts remain separate informational sections; they do not enter external-flow stock. Old commission/annual-fee trends are not displayed as partner cash-flow trends. Standard reports retain those trends. All financial reads use one read-only repeatable-read database snapshot. The only new outbound read is public pricing; no provider, OCR, payment, wallet creation, Ledger writes or financial replay occurs.

No migration or historical correction is required. Deploy PHP (including `LegacyStockReport` and `PartnerCashFlow`), admin assets and H5 together. Rebuild native App resources and repackage for the new UI. Test `PartnerStockTest`, `MultiAssetTest` and offline `consumer-stock-versions.mjs` for both browser engines.

## 2026-10-04: partner external-flow drilldown

Partner composition rows open paginated incoming/outgoing details via `flow=inflow|outflow` and independent `flow_page` (20 records). Consumer identity remains authenticated and host-owned; standard/disabled partners cannot request these details. Platform retains `partners.manage` and selected company/partner scope. Opening and pagination use GET only.

`PartnerCashFlow` now shares a single successful-order/evidence union for totals and detail rows. Every descendant carries its first-level branch from the report owner; tenant scoping, owner exclusion and cycle termination apply at all depths. Filter enabled same-company partners after traversal so only their own transactions are excluded, never their whole branch. Totals and detail pagination share this filter. Detail fields are limited to member account/email, current direct-branch account/email, native asset/amount, Ledger posting time, and the USDT estimate using the same quote as that response's totals. No addresses, provider payloads or staff fields are exposed. A direct child's own transactions explicitly belong to that child's branch. Branches follow current relationships, including after approved referral changes; no historical relationship reconstruction is claimed.

Order is stable by posting time, source and ID. Outgoing amount remains gross successful withdrawal amount, not net payout. Native precision survives missing rates; converted amounts are unavailable rather than zero. Opening another page performs a fresh read, so current-rate estimates and current-team membership may change. Per-row USDT rounding can differ slightly from rounding native totals once.

Uni-app H5/App and shared SaaS report provide drilldown, empty state, pagination and four-language copy. No database migration, funds operation or backfill. Deploy PHP plus admin/H5 resources; native UI changes require repackaging.

## Access and surfaces

`GET /promotion/stock` renders the partner report (or JSON with `Accept: application/json`). Identity comes exclusively from the authenticated tenant user. The daily-data filter adds a navigation option only when that user has an enabled partner configuration. Ordinary/disabled users receive 404 and no report data. Client identities cannot change the selected team. Responses are private/no-store.

Platform uses the independent `partners.manage` permission. Migration grants it to the existing PLATFORM_OWNER role; fresh seeders also include it in PLATFORM_OWNER, not PLATFORM_ADMIN or company roles. Platform access remains active-membership scoped and is rechecked inside mutations. No password re-prompt is added.

- `GET /platform/partners?tenant=...&partner=...&page=...`: company selection, paginated partners, configuration, journal entry/reversal form, report and pending fee valuations.
- `POST /platform/tenants/{tenant}/partners`: configure by platform account ID, enabled flag and 0–100 share percentage (8 decimal places).
- `POST /platform/tenants/{tenant}/partners/{partner}/journal`: append reimbursement/advance or linked reversal.
- `POST /platform/tenants/{tenant}/fee-valuations/{valuation}`: one-time audited completion of a missing fixed rate with dated evidence.

Tenant administrators and partner users cannot mutate any of these resources. Disabling a partner switches their report to standard and restores their own completed external flows in ancestor partner reports. Enabling excludes those flows on the next read, including lifetime historical evidence. Their non-partner descendants remain included in both cases. No financial records or relationships are rewritten; Platform can still inspect the account.

## Three additive storage categories

The new migration creates `partner_configurations`, `partner_journal_entries`, and `withdrawal_fee_valuations`. No existing business rows are migrated or rewritten. A composite unique withdrawal identity supports tenant-bound valuation foreign keys.

Journal entries are immutable at the database level. Corrections append a full, same-owner/same-kind/same-amount reversal referencing the original, followed by a separate replacement entry. Reversals cannot themselves be reversed, and a unique original-reference constraint prevents double reversal. Tenant/request-ID uniqueness, a payload hash, partner row locking and a transaction advisory lock serialize concurrent submissions. The actor and recording time are assigned by the server. Notes/business dates describe offline cooperation, not a wallet or Ledger payment. Audit entries commit atomically with each mutation.

Fixed valuations cannot be deleted or overwritten. Only a PENDING record may transition once to MANUAL with actor, evidence and request ID. Configuration and journal ownership are tenant scoped. All amounts use PostgreSQL numeric and Brick BigDecimal; report calculations never use floats.

## Scope and formula

A recursive UNION selects the partner plus all descendants by current tenant invitation relationships, including nested independent partners, without excluding equal/higher levels. UNION also deduplicates nodes. A report uses a PostgreSQL REPEATABLE READ, READ ONLY transaction (or an existing caller transaction); tree aggregates are set-based, not one query per person. Details are paginated at 20 rows. Shared page navigation advances each detail section; all lifetime totals remain unfiltered.

`stock = annual + deposits + fees - activation - annualCommission - rebates - reimbursements`

- Annual: completed `paid_promotion_orders.settlement_total`, including actual converted security deposit. Upgrade/renewal orders count their actual settlement, not the current tariff.
- Deposits: USDT USER_SECURITY_DEPOSIT balances for members without an effective paid cycle at the report timestamp.
- Fees: successful legacy TRON withdrawal fees in USDT plus COMPLETED asset-withdrawal fees. USDT uses its exact amount. Other assets use fixed saved valuations; zero fees need no rate. Pending/rejected/cancelled orders are excluded.
- Activation: reuse PromotionReportQuery's posted-income union and legacy-award deduplication, filter by activation source within the tree, with **no beneficiary restriction**. Thus outside ancestors' actual paid activation commissions count; annual commissions are reported separately.
- Annual commissions (added 2026-09-27): posted annual-fee commission shares whose source payer is the partner or a descendant, including all beneficiaries inside/outside the team. Only Ledger-linked positive payments count; quotes and failed/unpaid orders do not. Reads use existing immutable records without backfill or financial writes. Consumer H5, uni-app and SaaS display this deduction after activation commissions; stock and reference share both deduct it.
- Rebates: APPROVED, Ledger-linked annual returns received by tree members.
- Reimbursements: signed journal total from partner configurations whose owner is in the tree. Enabled status does not change ownership. Nested entries occur once in one report.

Reference share is stock × current share_percent / 100, rounded half-up to 8 decimal USDT places. Negative values are allowed. Advances are reported separately and affect neither stock nor reference share. Overlapping partner reports must not be added together. Nothing here settles or transfers money.

## Settlement-time fee valuation

After exact chain payout proof matches, before the final multi-asset settlement transaction, FeeValuation obtains a fresh built-in public OKX quote. It saves rate, observation time, original asset/fee and an 8-decimal half-up USDT value atomically with the completed withdrawal. Network/validation/representability failure produces PENDING instead; the existing verified withdrawal settlement still succeeds. USDT and zero fees bypass external prices. An already completed withdrawal returns immediately, without new requests or valuation backfill.

Report reads never request public prices. Subsequent quotes cannot change saved values. Privileged manual completion requires a positive exact rate, an observation time no later than now, and supporting evidence. Idempotent identical retries return the same result; changed retries fail. No Ledger or withdrawal state is changed by supplementation.

Any positive non-USDT completed fee without a fixed valuation causes stock and reference share to be null/unavailable, with a pending-rate count and original amounts. Known fee subtotals are not displayed as a complete fee total. This also makes an older unvalued fee visible without inventing or backfilling its historical exchange rate. The admin supplementation endpoint only handles newly recorded PENDING valuations; this implementation does not backfill old rows.

## Alerts and trends

Active cycles with positive unreturned annual fees and weighted progress >=70% are aggregated and listed. The progress query reproduces PaidPromotionRebate::progress: first-activation relations within the immutable cycle window, direct 1 / indirect 0.5, LEGACY counting or eligible rank snapshot as applicable. It is not based on today's member ranks or a fixed eight-level list. Completed returns remove the remaining exposure. Expired cycles with captured PENDING obligations have a separate count, amount and detail list.

Daily trends use the company timezone. Today is a live accumulation. Averages use the preceding 3/7/15/30 full calendar days, exclude today, and divide by all days including those with zero activity. Streams are posted source activation commissions, positive deposit postings linked to the unique DEPOSIT account activation fact, and completed annual settlement totals. Repeat deposit funding and supplements are excluded from first-deposit trends.

## Verification

`tests/Feature/PartnerStockTest.php` covers access/disable/scoping, nested journals, immutable corrections, duplicate/concurrent request handling and audit identity, negative shares and independent advances, outside-beneficiary commission cost, deposit conversion, settlement-time fixed prices, price failure without payout failure, manual completion, zero-fee parity, timezone boundaries/zero days, 70% weighted threshold and expired pending returns. All money fixtures use the existing application/Ledger flows in guarded card_ui_test, with HTTP blocked or faked.

Additional regression tests: PromotionTeamSummaryTest, PromotionMemberIdentityTest, PublicWithdrawalNetworksTest. TypeScript and production builds are checked. Browser fixtures inspect all four consumer languages at 320/375/430/768/1440px, long amounts, expanded risk details and missing-rate state; no live account or money action is used.

Local delivery verification: the migration was applied only to the confirmed local `card_mock` database. All three new record tables remain empty there; no actual partner or cooperation expense was created. Regression run: 19 tests / 198 assertions. Consumer layout fixtures passed four languages and five viewport widths; Platform fixtures passed its supported Chinese/English locales at the same widths. Production build retains the existing >500 kB chunk warning.

## List-first administration (2026-09-25)

The Platform landing view is a paginated partner list (account, nickname, split, access and row actions). Add/edit and journal entry are separate dialogs; a row opens its report. Pending fee maintenance is collapsed by default. Adding uses a searchable member dropdown, never a free-entry account-ID field. `GET /platform/tenants/{tenant}/partner-candidates` requires `partners.manage`, returns only account ID/nickname, and searches the selected company's unconfigured members by account ID, nickname or email with 20-item pagination. Email search is case-insensitive and accepts partial addresses; results still contain only account ID and nickname. Existing partners, including disabled partners, are edited from the list instead of added again. Search requests debounce and cancel stale responses, support retry/load-more, and do not change any financial data.


### 2026-10-03 personal account reconciliation
The report adds accountBalance under the existing read-only repeatable-read snapshot. All seven fields are eight-decimal USDT strings: advances, activationCommission, annualCommission, reimbursements, theoretical, actual, difference. Personal journal net totals include reversals and are restricted to the selected partner/company; personal posted activation (including deduplicated legacy) and annual commissions are beneficiary-scoped. Theoretical = advances + activationCommission + annualCommission - reimbursements. Actual reads only the owner's USDT USER_AVAILABLE Ledger balance, defaulting to zero without wallet creation; difference = theoretical - actual. It is neither a debt determination nor a settlement action.
Uni-app and shared SaaS reports show the three figures between stock composition and team alerts. Existing team stock totals remain unchanged. No migration/backfill; deploy backend and admin/H5 assets together, and repackage native apps to include the new panel.

## Partner hierarchy drilldown (2026-10-06)

Partner-version stock cards expose Partner data in uni-app/H5 and the Platform
report drawer. Lists show the nearest enabled partner on each current invitation
branch: traverse ordinary/disabled-partner nodes, stop at each enabled partner.
Each row includes display name (account ID fallback), email, current USDT business
stock (an eight-decimal string using the same PartnerBusinessStock totals as its
detail report, including negative values), and all-descendant headcount
(excluding self, including descendant partners and their members), Details and
Subordinate partners. Lists have stable account-ID ordering and 20-row pages;
recursive UNION deduplicates nodes and terminates cycles. Counts are batched for
one page, never computed with membership creation or financial writes.

Consumer routes under `/promotion/stock/partners/{partner?}` list children;
`/promotion/stock/partners/{partner}/report` returns the complete selected report,
including personal reconciliation and cooperation notes as explicitly authorized.
The host-owned company and enabled signed-in partner scope are checked against the
current invitation tree on every list/report/flow page in the same repeatable-read
read-only transaction as the report. Unrelated, ancestor, moved or disabled targets
fail closed. Administrator actor identifiers are removed from consumer journals.
The original `/promotion/stock` self-report and ordinary-user behavior remain.

Platform `/platform/partners/{partner}/children` and `/stock` JSON endpoints require
partners.manage and enforce any explicit company filter. Nested browsing stays in
the existing drawer with its own navigation stack, leaving list filters and outer
report state intact. Consumer navigation uses independent cached pages, revalidates
on return, clears stale child reports on access failure, and restores pagination and
scroll. No client-selected tenant or alternate financial formula is introduced.

No migration is required. Publish rebuilt admin assets and compile/synchronize H5
into public/h5. `PartnerHierarchyTest` exercises scope, tree projection, pagination,
full report consistency and read-only behavior; `tests/Browser/partner-hierarchy.mjs`
uses offline fixtures to exercise desktop/mobile navigation without real mutations.
