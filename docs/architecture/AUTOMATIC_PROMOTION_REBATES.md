# Automatic annual fee returns (2026-09-17)

Current activation, mixed annual payment and annual allocation rules are defined in
[UNIFIED_ACCOUNT_ACTIVATION.md](UNIFIED_ACCOUNT_ACTIVATION.md), approved 2026-09-17.
It supersedes conflicting deposit-only counting, automatic funding and reward rules below.

The 2026-09-17 [direct USDT commission contract](DIRECT_COMMISSION_RECEIPTS.md)
supersedes all earlier independent commission-balance, manual-transfer, refund
restriction and receiving-wallet KYC requirements below. Cumulative income is
reporting only; valuation counts actual wallet funds once. Historical implementation
notes are not alternate runtime modes.

## Single entitlement rule

The user confirmed on 2026-09-17 that the system is in development without legacy
production data. Automatic returns apply to every promotion period, including
current periods, upgrades and renewals. This replaces the earlier new-period-only
cutover. No manual application, withdrawal, review or compatibility branch remains.
AUTO_FIRST_FUNDING is the sole permitted cycle policy; upgrade retains expiry and
counts. The forward schema update normalizes development cycle metadata only:
it never settles fees or alters Ledger, orders, awards or balances.

Progress counts distinct activated source accounts (COUNT DISTINCT source user ID),
whose first successful positive deposit funding occurs within [starts_at, ends_at).
Each account appears once, regardless of repeated funding or notification processing.
The user reaffirmed weighting: each direct account 1, each indirect account 0.5.
Consumer copy describes weighted account activations, never payment occurrences. The first-funding decision
uses tenant/user Ledger history under funding locks and is recorded on the event,
including zero-reward ancestors. Repeat payments never increment progress.

## Settlement and recovery

Source funding/upgrade transactions capture an AUTO PENDING return with paid-total,
rank, target, direct/indirect counts and exact unpaid return amount. Tenant locking
serializes captures; pending-cycle and automatic cycle/paid-total uniqueness prevent
duplicates. After commit, a separate transaction settles using Tenant, claim, User,
cycle locks before LedgerWriter. The stable key is promotion_rebate:{claim_uuid}.
Only the remaining actually paid annual fee may return to USER_AVAILABLE; no
commission income is created. No external provider call is involved.

Failures leave the durable claim pending and log tenant/claim plus exception class
and diagnostic code, without sensitive payloads. Existing promotion:recover retries
all pending automatic returns with explicit tenant scope, even after expiry. GETs
never move funds. Pending automatic returns block upgrades until recovered; they
cannot be cancelled or manually approved. Upgrade after a return pays the original
tariff difference; if its new target is reached, only additional unreturned fees
become a new entitlement. Invalid wallet/Ledger conditions fail closed and retain
pending status. Already captured obligations do not require a new login or active
period to settle.

Automatic completion stores processed_at and SYSTEM audit provenance atomically
with Ledger. Database guards retain immutable economics, the automatic-only
policy/source and exact sealed posting evidence; records cannot be rejected,
withdrawn or assigned a reviewer.

## Read models and UI

Benefits props include lightweight progress (policy, pending, direct, indirect,
target, paid, returned, remaining); cycle includes rebatePolicy. Claim DTO includes
source and processedAt. Current paid level cards show weighted progress and remaining
count, processing/completed status, and potential return amount. Other grades and
ordinary members do not display another period's personal progress. All periods
show automatic progress and return history. SaaS records are read-only.

Asset overview adds cumulativeCommission independently of assets[].commission.
Both Assets and invitation data use the same exact, deduplicated own-income query:
annual + activation + legacy awards, never transfers or annual-fee returns. The
whole tile opens /promotion/invitations and honors the balance-visibility setting.
Asset valuation continues to use the remaining commission Ledger balance.

## Deployment and checks

Deploy code and migrations through 2026_09_17_000200_unify_automatic_promotion_rebates;
run `php artisan migrate --force` and build frontend assets. Keep the existing
one-minute `schedule:run` deployment running; no new queue worker is needed. Clear
application caches/reload long-running application processes using the site's normal
deployment procedure. Inspect promotion.rebate.retry_required and pending automatic
return records when recovery is delayed. Migration only changes the supported rule metadata and constraints, never funds.
Use forward migrations; rollback cannot restore the retired manual workflow.

Regression coverage includes purchase/upgrade/renewal, first-only/zero-commission
indirect counting, period boundaries, remaining-fee upgrade returns, retry/expiry,
concurrent recovery, removed manual routes, provenance, unchanged history,
cumulative commission after transfer, valuation and tenant isolation. Live money is
not used for acceptance; database tests require isolated card_ui_test.

### Unified-rule acceptance (2026-09-17)

- PaidPromotionTest: 33 passed; PromotionTest: 12 passed.
- Frontend/i18n: 73 passed, including automatic membership/progress rendering in
  all four locales; manual application/withdraw/review routes return 404.
- Type checking, affected-file lint and production build passed.
- Browser verified current membership order and current-rank card show automatic
  returns, with no manual request copy; 375/768/1440 widths have no horizontal overflow.
- Applied the rule-only migration to local card_mock; before/after ordered hashes
  of Ledger accounts/entries/postings, promotion orders and rebates are identical.
  No payment or return operation was executed for browser acceptance.

### Payment review preview

Unpaid matching quotes show a read-only "after payment" return preview using the
order's target snapshot. Same-period upgrades retain first-funding counts and
calculate remaining return as actual paid fees + quoted payment - actual returns,
using decimal strings. First purchase/renewal starts at zero counts. Completed or
superseded orders do not supply a preview; the page shows actual current progress.
An already-qualified preview says the return follows successful payment, never
"processing" before payment. Quote expiry/availability/payment validation remains
mandatory, and reading the preview creates no entitlement or financial changes.

### Membership page simplification (2026-09-17)

The user removed the bottom return-progress/after-payment-preview and rebate-history
block from the membership purchase page, including its pagination. The current-level
card in the promotion center retains its progress. Automatic return rules, settlement
and immutable receipts are unchanged.
