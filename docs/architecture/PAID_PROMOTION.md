# Paid promotion (approved 2026-09-16)

Eight annual USDT tariffs: 1000/2000/5000/10000/20000/50000/100000/200000;
annual reward percentages 30/40/50/60/70/80/90/100; activation rewards
50/60/70/80/90/100/110/120; rebate targets 100/135/250/400/666/1428/2500/4000.
Ordinary members earn 20 USDT only for direct deposit funding. Fees do not activate
deposits, and deposit funding does not buy qualification. Re-funding after a real
refund earns/counts again. Referral identities remain immutable and tenant-scoped.

## Qualification and money
One calendar year in company timezone, exclusive end boundary; February 29 uses
February 28 next year. Manual renewal only after expiry. Upgrade charges target
tariff less the already purchased tariff snapshot and retains expiry/counts. No
downgrades. Config changes never rewrite a period or completed order. Quotes expire
after five minutes, hold no money, and are revalidated under Tenant/User locks.
Only successful Wallet payment activates qualification. Existing manual assignments
are archived and cleared during migration; no historic billing or award replay.

Fee debit, qualification and annual awards commit atomically. Dedicated
TENANT_PROMOTION_FEE_REVENUE records fees and their returns; existing company
commission clearing records expense, never customer deposit principal. All amounts
are exact USDT strings at 8 decimal places. Percentage differences round down; no
zero postings. Each source/beneficiary posts once. Receipt snapshots preserve
source and beneficiary rank, relationship depth, rate and Ledger entry provenance.
Activation awards retain the existing commission_awards linkage for old reporting.

Only paid, effective ancestors participate in annual differentials. Activation
starts with zero already covered; an ordinary direct inviter contributes 20,
ordinary indirect ancestors contribute zero, and paid ancestors receive only
positive differences above the running maximum. The source user's own level does
not reduce their inviter's reward. Expired qualification behaves as ordinary.
Existing commission-to-Wallet restrictions continue unchanged.

## Fee rebates
Within the cycle, 2*direct funding events + indirect funding events >= 2*target.
All non-direct descendants count as indirect, the owner does not count. Counts
use immutable event/ancestry snapshots, not current balances or unique people.
User applies before expiry; one pending request per cycle. Applicant may withdraw;
rejection allows another application. Pending claims block upgrade until withdrawn.
Application snapshots preserve counts/threshold/remaining paid amount. After expiry,
already submitted claims remain reviewable. Platform promotion_refunds.review,
active membership, password and explicit confirmation are mandatory. Approve once
through LedgerWriter and atomic immutable actor/time audit. No clawback; qualification
remains. A later upgrade may claim only additional paid, unreturned fees against
the new target, retaining current-cycle counts. No automatic rebate or ordinary
early-cancellation refund is introduced.

## Interfaces and presentation
User quote/confirm, rebate apply/withdraw; Platform tariff configuration and scoped
rebate review. GETs are read-only. Annual and activation tables independently group
actual awards by source rank, direct/indirect, with real order/event counts. Price
ranges represent multiple historical rates; paginated details expose account IDs,
never contacts. Historical awards without source-rank snapshots remain legacy.
Headline earnings are the viewer's own awards, never descendants' earnings. Annual
rebates have separate progress/history and company revenue/return/expense reporting.
Consumer text supports en/zh-CN/ms/es; Admin supports en/zh-CN. Existing responsive
components and horizontal tables at 375/768/1440 px. No withdrawal-fee changes.

## Deployment and acceptance
Git release, maintenance-window additive migration, archive old assignments, then
verify Ledger fingerprints/reconciliation unchanged. No historical event replay.
Acceptance covers anniversary/leap year, repeats, idempotency, differential chains,
upgrade-after-rebate, scoped snapshots, concurrent payment/withdrawal/review, rollback,
permissions/audit, financial regressions and browser-only fixtures. Live funds require
separate explicit account/amount approval. See PROMOTION_REQUIREMENTS.md for unchanged
referral, deposit and commission-transfer contracts.

### Local migration evidence
The development `card_mock` migration on 2026-09-16 preserved exact ordered-row
fingerprints of 2,071 Ledger accounts, 1,286 entries, 2,572 postings, 540 old
commission awards, 302 funding events, 401 wallets and 501 invitation memberships
(excluding the explicitly retired `level_id`). No money events were replayed.
Legacy local assignment fixtures now fail before any write after cutover.

### Release runbook
1. Back up the database and retain the current Git revision. Put the site in
   maintenance mode and drain/pause financial queues, scanners and refund workers.
2. Record counts and ordered row fingerprints for Ledger accounts, entries,
   postings, old commission awards/transfers, funding events, wallets and referral
   identity fields. Record the count of non-null promotion member level IDs.
3. Deploy the reviewed Git revision and execute the additive paid-promotion
   migration. It archives every previous assignment, clears only those level IDs,
   seeds the eight company tariffs, and grants the new review permission to existing
   Platform Owner/Admin roles. No live payments or refunds run during migration.
4. Compare all financial fingerprints and referral identities, verify archive count
   equals the old assignment count, and run `php artisan ledger:reconcile`.
5. Check SaaS tariffs, review permissions and local-language GET pages, rebuild cached
   routes/config as appropriate, then resume workers and leave maintenance mode.
6. This is a forward-only financial schema. Do not roll back the database or deploy
   old assignment/commission logic over it. Fix with a reviewed forward migration.
   Live payment/refund acceptance still needs a named account and explicit amount.

### Verification results (2026-09-16)
- Full regression run: 1,150 passed; two tests still exercised retired manual
  assignment and were updated to assert rejection/configure the paid tariff API.
- Final affected suites: all 46 tests passed (583 assertions), including those two
  replacements and 18 new paid-promotion tests. A final scoped HTTP check passed
  after moving read DTOs into the application query. Final annual-display regression:
  all 19 paid-promotion tests passed (90 assertions); non-earning annual orders
  are excluded from the commission table.
- Independent PostgreSQL-session races cover duplicate confirmations/approvals,
  payment versus withdrawal, and approval versus a previously quoted upgrade.
- TypeScript, ESLint, 67 i18n/frontend checks and production build passed.
- Browser-only production-bundle acceptance: 42 combinations, no page errors and
  no financial/configuration writes; consumer en/zh-CN/ms/es plus admin zh-CN/en,
  each at 375/768/1440. Local screenshots and report: `/tmp/card-paid-promotion`.
- Local Ledger reconciliation passed after migration; historical fingerprints
  were unchanged. Production migration and named-account live acceptance remain
  operational deployment steps; they were not implicitly executed.
