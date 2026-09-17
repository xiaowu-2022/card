# Unified account activation — approved 2026-09-17

This contract replaces deposit-only card eligibility, automatic first-top-up
allocation, deposit-only activation counting and the previous annual differential
allocation. There is one runtime rule, not a legacy/new-policy switch.

## Qualification and entry

`AccountActivationStatus` determines current qualification: the configured USDT
security deposit is satisfied **or** a paid agent period is currently effective.
The Assets prompt and membership choices, card catalog, issue and load gates use
this result. KYC, tenant/user/wallet availability, provider readiness, balance and
refund-linked card restrictions remain independent. Expiry stops new issue/load
when the deposit is insufficient; it does not freeze existing cards. A configured
zero requirement retains its existing satisfied meaning.

Assets always displays Top up, Withdraw, Exchange and Transfer. Missing KYC or wallet
prerequisites route clicks to the corresponding requirements page; visibility does not
grant financial permission. Available accounts retain their normal operation routes.

The membership chooser offers the ordinary member deposit (no annual fee) and paid
agent tiers. An effective agent cannot fund a new deposit. Top-ups credit only USDT;
no new initial-deposit intents are created. The retired allocation job is inert,
including queued deliveries; `promotion:recover` no longer executes it.

## Annual settlement

An immutable quote records `amount` (wallet payment/commission base),
`deposit_applied`, `settlement_total` and `deposit_snapshot`. The server computes:

- due = target tariff minus already purchased tariff in the effective period;
- converted deposit = min(current deposit, due);
- wallet payment = due minus converted deposit;
- settlement total = converted deposit plus wallet payment.

Confirmation locks Tenant/User/order, revalidates tariff revision, current period,
expiry, deposit balance and pending refund/restoration, then uses LedgerWriter with
`promotion_fee:{order_id}`. Debit only nonzero user deposit/available legs and credit
TENANT_PROMOTION_FEE_REVENUE with the total. The deferred database constraint checks
exact account ownership, currency, amounts, sealed entry and two/three posting count.
A completed replay returns the existing order. All quote dimensions are immutable.

Deposit conversion is annual revenue and cannot subsequently be refunded as deposit;
surplus deposit remains untouched. A full deposit-funded purchase has no wallet leg
and no annual commission. Annual return entitlement sums settlement totals, whereas
annual commission uses only wallet payments. Upgrade preserves the period end and
progress; returns never reduce the purchased tariff used for upgrade differences.
Pending automatic return blocks upgrade until recovery completes. Company revenue
reports already aggregate the revenue posting, thus include both payment sources.

## First activation and commissions

`account_activations` has unique (tenant,user), sealed source Ledger evidence, source
DEPOSIT/ANNUAL, source business ID and immutable activation time.
`account_activation_relations` stores the complete tenant-scoped ancestor/depth
snapshot. Deferred evidence triggers verify source ownership and complete ancestry;
updates/deletes are rejected. Recording and SYSTEM audit occur inside the source
payment transaction, independent of reward amount. Tenant/User locking serializes
the two activation paths. Page GET never creates activation facts or money.

Deposit-first earns activation rewards; annual-first earns only annual commission.
Later deposits, refunds, upgrades and renewals do not recreate activation or activation
rewards. Refunds do not retract first activation or existing commission. Return progress
counts facts within [period start, period end): direct 1, all indirect 0.5. The existing
internal `AUTO_FIRST_FUNDING` policy identifier now denotes this single activation
rule; it does not select a second mode. Source transactions capture durable rebate
intent and attempt settlement after commit. `promotion:recover` retries, including
expired periods with already captured entitlement.

Annual allocation starts with covered rate zero; payer rate never participates.
The direct active agent receives its full rate regardless of payer rank. An indirect
ancestor participates only with effective rank >= payer purchased rank. An ineligible
ancestor neither receives money nor consumes covered rate. Eligible ancestors receive
max(own rate - maximum previously eligible rate, 0). Ordinary members have no annual
rate. Existing direct-to-USDT Ledger receipts and immutable rank/depth/rate/base/covered
snapshots remain. On a 1,000 wallet payment the approved paths produce 500+300,
400+0+400, 500, and 400+200 respectively.

## Migration and deployment

Deploy code and `2026_09_17_000400_unify_account_activation` together under maintenance
so old workers cannot write while schema and application contracts change. Back up
the database first, run `php artisan migrate --force`, restart long-running application
and queue workers, rebuild assets, then restore service. Keep the existing minute
scheduler for `promotion:recover`. Do not run a fixture, consolidation or financial
replay command as part of this deployment.

Migration adds dimensions to existing development orders without changing original
amounts. It derives each first activation from the earliest actual sealed deposit or
completed annual payment and snapshots immutable referral ancestry. It never rewrites
Ledger, awards retrospective commission, captures rebates or transfers money. Existing
completed payments remain historical facts; future operations all use the new rule.

Validation uses card_ui_test and offline provider fakes only. Verify decimal mixed/zero
wallet settlements, replay, concurrent paths, refund exclusion, expiry, weighted counts,
approved annual paths, automatic return cap/recovery, rollback, tenant isolation and
four-language responsive UI. Browser acceptance must not submit real payments/refunds.

## Acceptance evidence (2026-09-17)

- Related feature regression: 271 tests / 2,499 assertions passed before the final
  additional cases; the agent issue/load/expiry case separately passed 8 assertions.
- Paid promotion plus architecture suite: 72 tests / 590 assertions passed; the
  expanded refund cancellation/restoration case subsequently passed 8 assertions.
- Four-language frontend suite: 74 tests passed, including inactive/qualified Assets,
  ordinary membership choice and mixed-payment labels. Typecheck, ESLint and Vite
  build passed (existing bundle-size advisory remains).
- Browser acceptance checked the Chinese mixed quote at 375/768/1440 and English,
  Malay and Spanish membership selection at 375. No horizontal overflow; the bottom
  payment controls were reachable above navigation. Original Chinese preference and
  viewport were restored. Only a quote was created, never confirmed.
- Local card_mock migration derived 301 DEPOSIT activation facts. Counts and hashes
  of Ledger entries, postings and account balances were identical before migration,
  after migration and after browser inspection. No local payment/refund executed.
