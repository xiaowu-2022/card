# Paid promotion (approved 2026-09-16)

Current activation, mixed annual payment and annual allocation rules are defined in
[UNIFIED_ACCOUNT_ACTIVATION.md](UNIFIED_ACCOUNT_ACTIVATION.md), approved 2026-09-17.
It supersedes conflicting deposit-only counting, automatic funding and reward rules below.

The 2026-09-17 automatic-return revision in AUTOMATIC_PROMOTION_REBATES.md supersedes
all manual rebate/counting rules. Every period now uses automatic first-funding returns.

Eight annual USDT tariffs: 1000/2000/5000/10000/20000/50000/100000/200000;
annual reward percentages 30/40/50/60/70/80/90/100; activation rewards
50/60/70/80/90/100/110/120; rebate targets 100/135/250/400/666/1428/2500/4000.
Ordinary members earn 20 USDT only for direct deposit funding. Fees do not activate
deposits, and deposit funding does not buy qualification. Subsequent funding remains
recorded but counts toward neither rebates nor activation commission (2026-09-17
revision). Referral identities remain immutable and tenant-scoped.

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
Activation and annual rewards credit USER_AVAILABLE directly. Receipt can provision a
missing USDT wallet without KYC; spending retains its existing checks. Refund status
does not restrict commissions. See DIRECT_COMMISSION_RECEIPTS.md.

## Fee rebates
Within the cycle, 2*direct first-funding events + indirect first-funding events >=
2*target. Every non-direct descendant counts as indirect; the owner does not count.
Counts use immutable first-funding/ancestry snapshots. Re-funding and top-ups do not
increase progress. Reaching the threshold captures a durable automatic return intent;
settlement returns only paid/unreturned fees through LedgerWriter with SYSTEM audit.
Pending returns block upgrades; captured returns may complete after expiry. Upgrade
retains expiry/counts, adopts its new threshold and returns only additional unreturned
fees. There are no manual application/review routes. See AUTOMATIC_PROMOTION_REBATES.md.

## Interfaces and presentation
User quote/confirm and read-only return history; Platform tariff configuration and
read-only automatic return records. GETs are read-only. Annual and activation tables independently group
actual awards by source rank, direct/indirect, with real order/event counts. Price
ranges represent multiple historical rates; paginated details expose account IDs,
never contacts. Historical awards without source-rank snapshots remain legacy.
Headline earnings are the viewer's own awards, never descendants' earnings. Annual
rebates have separate progress/history and company revenue/return/expense reporting.
Consumer text supports en/zh-CN/ms/es; Admin supports en/zh-CN. Existing responsive
components at 375/768/1440 px; the UI revision below supersedes the two-table layout. No withdrawal-fee changes.

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

## Promotion UI revision (approved 2026-09-16)
The center leads with membership status and an explicit apply/upgrade/renew/benefits
entry. NONE/ACTIVE/EXPIRED is derived read-only from persisted cycles; an expired
cycle never grants rights. The membership page reads the scoped USDT available
balance without creating accounts. All consumer commission amounts show USDT.

Replace the two full-width annual/activation tables with one grade list. Each grade
shows annual, activation and combined totals; expanding reveals independent direct/
indirect counts, actual amounts and historical price ranges. Paid-order counts and
funding-event counts remain separate; neither is a team headcount. Per the 2026-09-16
follow-up, show all nine grades by default and allow switching to populated grades
(team members or reward records). Legacy rewards stay outside the new
combined total, while all-time commission still includes them.

Membership selection uses unselected radio rows with configured prices and benefits.
An explicit next step obtains the existing immutable server quote, followed by the
existing password confirmation. Expired quotes and insufficient funds disable payment;
server eligibility, expiry and configuration revision checks remain authoritative.
Rebates and review history remain separate. No schema, commission rule, payment
permission, historical data or financial event changes are authorized by this UI work.
Use existing components and four locales at 375/768/1440 px without horizontal tables.

## Compact reference layout (approved 2026-09-16)
The overview places the level and compact membership entry on one line, followed by
one green team/personal-commission summary and a five-column grade table. Invitation
and secondary links follow the table. The membership purchase page is unchanged.
The table supersedes the prior stacked grade list: grade, direct people, indirect
people, annual commission, activation commission. Expanded rows retain exact rewards,
independent event counts and historical rates. Table amounts use two display decimals;
a positive amount below 0.01 displays <0.01. No amount is truncated or used for payment.

teamByLevel is a read-only nine-row aggregate (rank/direct/indirect). One recursive
tenant-scoped query joins each descendant to its currently effective paid cycle at
the same captured timestamp as the owner's level; missing/expired qualification is
rank zero. Totals derive from those same grouped counts. Commission groups remain
historical, never regrouped by current membership. Team counts and reward counts
are distinct. Defaults show grades with either members or reward records; show-all
includes all nine grades. No schema or financial mutations.

## Benefits home and My invitations (2026-09-16)

The user approved replacing the concentrated promotion overview with a benefits-first
home. `/promotion` shows nine horizontally browsable levels (ordinary plus ranks 1–8),
initially the effective current rank. Current paid benefits and tariff come from the
saved cycle; other offers use current company settings. Expired qualifications select
ordinary. Disabled offers are readable but cannot be bought. Monetary examples are
illustrative and computed exactly, not income forecasts. Swiping never creates orders.
Explicit apply/upgrade reuses the existing stable-request quote POST and its membership
review redirect; only the existing password-confirmed action pays. Pending rebates,
wallet eligibility, expiry and price revisions continue to be validated server-side.

`/promotion/invitations` owns commission balance/transfer eligibility, personal cumulative
income and the existing expandable five-column level table. On 2026-09-16 the user
removed the standalone team-count, annual-commission, activation-commission and legacy
commission summary block. The level table and daily/direct/commission entry points
remain; historical income is still available through commission records and is not
reclassified. Counts retain current
level attribution; money retains historical event attribution. Daily/direct/commission
pages return here. The old `/promotion/team` redirects here. `/promotion/rules` explains
actual promotion rules and company-configured rebate thresholds; saved cycle terms still
apply to existing memberships. Annual rebates/history remain on the membership page at
`#annual-rebate` / `#rebate-history`, outside commission income.

A benefits-only query for the home/rules reads no team, member detail or commission data.
It exposes scoped offer/cycle DTOs, pending-rebate and claim-existence flags. The existing
income queries, tenant/authentication gates and financial POST contracts remain intact.
No schema migration, historical replay or financial-record change is involved.

Home includes three truthful invitation steps, code, native sharing and clipboard
fallback. Links remain on the current company origin. Cancelling native share is neutral;
copy failure exposes the full selectable link. Four locales, keyboard controls and
375/768/1440px layouts are required. Only the carousel scrolls horizontally, with equal
header side space keeping the title centered. Browser acceptance never sends real payments.

### Compact level cards (2026-09-16 follow-up)

The user removed calculation examples/rules from the level cards. Detailed rules
remain on the rules page. Cards use tighter spacing and whole-USDT annual fee
labels without decimal points. Any non-integer configured fee is rounded only for
the card label and marked approximate; the server quote/payment review retains
exact precision. Prices, rewards and financial calculations are unchanged.

The user corrected the share placement to a fixed bottom dock above the persistent
bottom navigation. It matches the consumer shell width and navigation safe-area
height. ResizeObserver reserves the actual dock height in page content, including
feedback/manual-copy fallback; dialogs retain their higher layer.

Level card headers now pair the level name on the left with the whole-USDT annual
price at the upper right, omitting the standalone annual-fee label and price row.
The current-level badge sits beneath the level name.

## Unified promotion reports (approved 2026-09-16)

Keep separate daily, direct-member and commission routes with shared compact filters,
USDT formatting and expandable rows. Daily defaults to the company-local current day;
commission history defaults to all dates. Date ranges are inclusive local dates mapped
to half-open UTC intervals. Legacy date URLs remain supported; explicit ranges win.
Daily summaries use actual commission posting times, while team movements use business
event times. One registration/funding/annual-order event produces one activity row;
first funding is a badge, not a duplicate movement. Activity-kind filtering only narrows
rows, never the period summary. Zero-commission source events remain visible.

Member grades are current effective cycles; income grades and relationship depth come
from immutable event/share snapshots. Paid activation shares must not be counted again
through commission_awards. Unmatched older awards remain legacy with unknown grades and
historical relationship, never reconstructed from present configuration. Members show
personal income contributions separated by annual/activation/legacy. Commission income
and transfers to balance occupy separate tabs with separate totals and filters. Fee
rebates and other members' income are excluded. Query aggregates precede pagination,
use exact decimal strings, carry tenant/user scope and batch current membership reads.
No schema migration, money mutation or historical replay is authorized by this redesign.

The user rejected the first report-like presentation during acceptance. Consumer views
therefore lead with income, followed by a single date/filter toolbar and compact
statement rows. Advanced filters open in an accessible bottom dialog on mobile and
a centered dialog on larger screens, with explicit apply/reset and removable active
filter chips. They do not occupy the first screen. A statement row expands as a whole;
settlement calculations, full precision, and statistics notes stay behind disclosure.
Member rows emphasize account/current grade and personal income contribution; its
breakdown expands in place and the contribution links to the source-filtered statement.
These presentation changes do not change query periods, aggregation, or money rules.

## First-funding-only activation commission (2026-09-17)

The user removed rewards for reactivation. Only the first positive, sealed
security-deposit funding for a tenant/user may earn activation rewards. Refunds,
requirement increases, inviter level upgrades and a first payment with no eligible
reward do not create another opportunity. The allocator checks immutable Ledger
funding history under the funding flow's existing Tenant/User locks. It retains
every funding/event snapshot for reporting and rebate progress, and records
zero-value shares for subsequent funding, without creating awards or commission
postings. Their applicable standard, covered amount and reward are zero, while the
current qualification/rank references remain recorded. These shares preserve rebate
counts and routing evidence. Existing awards
and snapshots are never changed or clawed back; annual commissions are unchanged.
No migration or historical replay is required. Invitation-rule copy in all four
consumer languages describes first funding and removes repeat-reward messaging.


## Upgrade eligibility by current-cycle activations (2026-09-20)

For active cycles, enabled targets must have higher rank and fee than the current
cycle and satisfy `2 * target > 2 * direct + indirect`. Counts come from the same
immutable first-account-activation relations/time boundaries as automatic returns.
The maximum enabled rank within the company is exempt only from this count test.
Disabled levels, lower/equal ranks or prices, and pending returns are never exempt.
No enabled levels means no purchase choices. Each target is evaluated independently,
even when configured targets are not monotonic. First purchases and expired-cycle
purchases retain existing behavior; upgrades preserve the cycle and its progress.

PromotionUpgradeEligibility supplies page choices and quote/confirmation validation.
Current counts and max enabled rank are re-read during payment under the Tenant lock;
new activations or a changed maximum rank can invalidate a quoted choice without any
money movement. Existing revision checks and completed-order idempotency remain.
Page data includes upgradeEligibility (weightedUnits, weightedCount, highestEnabledRank,
pending) and per-level selectable/unavailableCode/unavailableReason. Disabled choices
remain visible with explanations in the membership page and promotion carousel.

Rank limits no longer assume eight levels. Database migration expands only the rank
upper bounds to the existing integer storage capacity, retaining other checks. Reporting
and filter options use configured plus historically recorded ranks (including ordinary
rank zero); the upgrade exception uses enabled configurations only. Configuration batch
limits match company catalog size. No new level-creation endpoint/UI is introduced.
No existing orders, cycle snapshots, configuration values or Ledger entries are changed.

## 2026-09-21: First-activation five-generation counting snapshots

New activations use FIVE_GENERATION_SNAPSHOT for annual return progress and upgrade
eligibility only. At the first activation, snapshot the source's effective paid
rank and each ancestor's rank (ordinary/expired = 0). For an ancestor, include the
source only at depth <= 5 and when that ancestor's rank is strictly greater than
every rank below it on the path, including the source. Equal/higher nodes exclude
themselves and their entire branch. Direct counts 1; depths 2–5 count 0.5.
Ranks are taken after the qualifying annual purchase creates its paid cycle.

Snapshots never change on subsequent upgrades or expiry. An upgrade changes only
eligibility of future first activations; it never recovers excluded historical
counts. Upgrade quotes/payments compare against existing counted progress, not a
hypothetical target-rank recalculation. The current-cycle time window, highest
currently enabled rank exception, pending return gate and idempotency remain.

The user explicitly chose to retain all pre-migration activation counting (LEGACY),
including the 501 direct fixture members. Adding a policy column marks that boundary;
all future inserts require the new policy. New activation_count_snapshots preserve
complete scoped ancestry, rank/path evidence, eligibility and exclusion reasons.
Deferred database guards reject missing/inconsistent snapshots; snapshots and
activation records remain immutable. Original full activation relations and both
commission flows are unchanged. No historical money or entitlement is replayed.

### Direct member identity display (2026-09-25)

The user approved showing current profile nicknames and masked email beside the
platform account ID in direct-member reports. Join profiles by both tenant and
user; retain the existing direct inviter scope and pagination. Apply ContactMasker
on the server before serialization; never return the full member email. Render
nicknames as plain text and omit absent values. Commission/deposit rules do not change.
