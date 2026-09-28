# Platform manual promotion levels

Approved 2026-09-27. SaaS Users uses a bounded, truncated email column, removes the
phone column and links each effective promotion level to its adjustment/history page.
The company and account are always visible in the preview. A reason (1–500 characters)
and an explicit confirmation are required; no repeat password or recharge is needed.

## Authorization and history

Both routes require an active Platform session and `users.read`; POST also requires
`promotion_members.manage`, CSRF and throttling. The incremental migration and seeder
grant the new permission to Platform Owner/Admin, never company administrators.
Every user and level lookup includes company scope. Disabled purchase levels may be
assigned deliberately, and configured ranks are dynamic. Customer requests cannot
set a tenant or grant themselves a rank.

`manual_promotion_adjustments` is an append-only journal guarded against UPDATE/DELETE.
It stores company, recipient, prior/effective ranks, selected level, snapshotted reward,
percent and revision, operator ID/name, reason, business time and request UUID. It is
written atomically with the audit event, under the existing company-then-user lock order.
A stable request UUID deduplicates retries and rejects changed intent; the expected
latest adjustment ID rejects stale forms. All histories are paginated and scoped.

## Effective benefits

The latest adjustment overrides a paid cycle indefinitely until another adjustment:
- Positive rank: use its snapshotted activation reward and annual commission percent.
- Ordinary (rank 0): ordinary rules apply, including any existing satisfied deposit.
- Restore paid rules (null rank): use the original paid cycle if it is still active;
  otherwise ordinary rules apply. Paid cycles keep their original expiration dates.

Manual ranks affect account activation qualification, card/deposit eligibility through
that shared qualification, future direct/differential commissions, first-activation
rank snapshots, team reports, and the platform user list. KYC, active-user/wallet,
refund and other access gates remain mandatory. The grant creates no wallet, money,
annual order, activation/count event, commission or rebate. Subsequent real business
transactions still use LedgerWriter and existing eligibility and idempotency rules.
Commission shares retain the exact manual adjustment ID with a company/user FK.

Existing paid cycles, fees, rebates, activations, rewards and Ledger are never rewritten.
Paid annual-return accounting continues against actual paid cycles and actual paid sums;
free grants have no annual fee to return. Partner financial stock continues to report
actual paid-cycle economics, not fictional free annual-fee receipts.

While manual mode is active, annual quote/confirmation is unavailable. The consumer
sees that the platform manages the level and no annual fee is needed. Restoring paid
rules re-enables normal self-service purchasing. Completed payment retries retain
existing idempotent results; old unpaid quotes cannot bypass the manual-mode check.
No historical rewards are recalculated; configuration changes do not silently mutate
previous manual snapshots. A new adjustment captures the newly configured benefits.

## Deployment and verification

Run normal `php artisan migrate --force` before serving the new PHP/frontend build.
The migration adds journal/provenance schema and permissions only: no member is granted
a rank and no financial data is changed. Build React administration and the uni-app H5
with their existing release commands. Do not reseed a deployed database. History is
permanent; restore paid rules through the UI rather than deleting adjustments.

Offline coverage is in `ManualPromotionTest.php` and manual-level cases in
`PaidPromotionTest.php`: free grant, permanent validity, downgrade, restoration,
immutable audit history, same-company ownership, permission/confirmation, rollback,
retry/stale intent, snapshotted benefits, new rewards, team reports and unpaid quotes.
All mutations during verification use the isolated `card_ui_test` database, never
real financial or issuing operations.

Validation on 2026-09-27: the full paid/manual promotion run passed 77 tests (661
assertions); the final 10-test manual suite, including two additional permission and
dynamic-rank cases, passed 92 assertions. Admin i18n passed 77 checks; React and
uni-app TypeScript checks and both H5 builds passed. Browser inspection verified a
224px email column / 192px ellipsis text, no phone column, the level link, preview,
and confirmation invalidation when the selected level changes. Local card_mock received
the additive migration only; its manual adjustment table remains empty. No existing
account was adjusted during browser acceptance.
