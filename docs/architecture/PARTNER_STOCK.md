# Partner stock reports and cooperation journals

Approved 2026-09-25. Local implementation only; no live partner designations, expense entries, historical financial changes, deployment or payment execution.

## Access and surfaces

`GET /promotion/stock` renders the partner report (or JSON with `Accept: application/json`). Identity comes exclusively from the authenticated tenant user. The daily-data filter adds a navigation option only when that user has an enabled partner configuration. Ordinary/disabled users receive 404 and no report data. Client identities cannot change the selected team. Responses are private/no-store.

Platform uses the independent `partners.manage` permission. Migration grants it to the existing PLATFORM_OWNER role; fresh seeders also include it in PLATFORM_OWNER, not PLATFORM_ADMIN or company roles. Platform access remains active-membership scoped and is rechecked inside mutations. No password re-prompt is added.

- `GET /platform/partners?tenant=...&partner=...&page=...`: company selection, paginated partners, configuration, journal entry/reversal form, report and pending fee valuations.
- `POST /platform/tenants/{tenant}/partners`: configure by platform account ID, enabled flag and 0–100 share percentage (8 decimal places).
- `POST /platform/tenants/{tenant}/partners/{partner}/journal`: append reimbursement/advance or linked reversal.
- `POST /platform/tenants/{tenant}/fee-valuations/{valuation}`: one-time audited completion of a missing fixed rate with dated evidence.

Tenant administrators and partner users cannot mutate any of these resources. Disabling a partner only revokes consumer viewing; its records remain in ancestor reports and Platform can still inspect them.

## Three additive storage categories

The new migration creates `partner_configurations`, `partner_journal_entries`, and `withdrawal_fee_valuations`. No existing business rows are migrated or rewritten. A composite unique withdrawal identity supports tenant-bound valuation foreign keys.

Journal entries are immutable at the database level. Corrections append a full, same-owner/same-kind/same-amount reversal referencing the original, followed by a separate replacement entry. Reversals cannot themselves be reversed, and a unique original-reference constraint prevents double reversal. Tenant/request-ID uniqueness, a payload hash, partner row locking and a transaction advisory lock serialize concurrent submissions. The actor and recording time are assigned by the server. Notes/business dates describe offline cooperation, not a wallet or Ledger payment. Audit entries commit atomically with each mutation.

Fixed valuations cannot be deleted or overwritten. Only a PENDING record may transition once to MANUAL with actor, evidence and request ID. Configuration and journal ownership are tenant scoped. All amounts use PostgreSQL numeric and Brick BigDecimal; report calculations never use floats.

## Scope and formula

A recursive UNION selects the partner plus all descendants by current tenant invitation relationships, including nested independent partners, without excluding equal/higher levels. UNION also deduplicates nodes. A report uses a PostgreSQL REPEATABLE READ, READ ONLY transaction (or an existing caller transaction); tree aggregates are set-based, not one query per person. Details are paginated at 20 rows. Shared page navigation advances each detail section; all lifetime totals remain unfiltered.

`stock = annual + deposits + fees - activation - rebates - reimbursements`

- Annual: completed `paid_promotion_orders.settlement_total`, including actual converted security deposit. Upgrade/renewal orders count their actual settlement, not the current tariff.
- Deposits: USDT USER_SECURITY_DEPOSIT balances for members without an effective paid cycle at the report timestamp.
- Fees: successful legacy TRON withdrawal fees in USDT plus COMPLETED asset-withdrawal fees. USDT uses its exact amount. Other assets use fixed saved valuations; zero fees need no rate. Pending/rejected/cancelled orders are excluded.
- Activation: reuse PromotionReportQuery's posted-income union and legacy-award deduplication, filter by activation source within the tree, with **no beneficiary restriction**. Thus outside ancestors' actual paid activation commissions count, while annual commissions do not.
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
