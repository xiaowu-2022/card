# User operation restrictions

2026-10-09: Platform Users → More actions → Operation restrictions provides four
independent flags, default-off for ordinary new accounts:

- `withdrawal_blocked`: new USDT/TRON and multi-asset withdrawal confirmations.
- `deposit_refund_blocked`: new security-deposit refund applications only.
- `card_transfer_blocked`: confirming wallet-to-card funding (card recharge).
  This application has no card-ownership transfer workflow; the UI labels the
  setting “禁止转卡（卡片充值）”. Card returns, card viewing and initial issuance
  retain their existing policies.
- `wallet_transfer_blocked`: outgoing same-company wallet transfers in all
  supported assets. Incoming transfers retain their original eligibility rules.

Platform requires active `users.read` and `users.restrictions.manage` permissions.
The migration grants the new permission to existing Platform Owner/Admin roles;
the seeder includes it for fresh installations. Company administrators and
consumers cannot modify these flags. The scoped settings GET is lazy/read-only;
consumer DTOs do not disclose internal restriction flags.

Saving all four values requires explicit confirmation, a revision and request UUID.
Tenant then User locks serialize updates with operation acceptance. Each successful
save increments the revision and appends actor, request, before/after evidence to
the existing immutable audit log in the same transaction. Exact request replay
reuses the recorded result; stale/conflicting edits fail without changing flags.
No wallet, Ledger, card, commission or identity state changes when saving settings.

Existing operation pages stay usable. New confirmations check current persisted
flags on the server and return `USER_OPERATION_RESTRICTED` (403) with the allowlisted
message `Please contact support.`; Chinese consumers see exactly “请联系客服”.
No browser-supplied flag can bypass the check. Card quotes remain available, but
confirmation rechecks after the existing Tenant/User locks and before holds or
provider submission, including quotes created before the restriction was enabled.
A definitive restriction rejection is not displayed as an uncertain card payment.

Existing accepted orders retain idempotent replay, settlement/recovery and cancellation
semantics. Restrictions do not cancel or refund existing financial orders and do not
block deposit-refund cancellation. No real financial operations were used for testing.

## Deployment

Deploy PHP/routes and migration `2026_10_09_190000_add_user_operation_restrictions.php`;
run `php artisan migrate --force` before serving the new code, then refresh route
caches/workers through the standard deployment workflow. Deploy `public/build`
for the Platform/legacy browser UI and `public/h5` for the App's remote H5 content.
A WebView APK rebuild is not required for these server/H5 changes.

## Validation

- `tests/Feature/UserOperationRestrictionsTest.php`: permission/company scope,
  confirmation, revisions/replays, independent flags, audit rollback, zero financial
  writes/provider requests on denial, H5 and legacy confirmation responses.
- `tests/Feature/CardIssueTest.php` restricted card-funding case: a pre-existing
  quote cannot be funded after enabling the restriction; disabling restores
  confirmation; completed replay never debits twice.
- `tests/Frontend/consumer-exchange-errors.mjs`: real shared confirmation error
  path displays the contact-support message in all four consumer languages.
- `tests/Browser/platform-user-restrictions.mjs`: offline built UI, lazy reads,
  populated controls, explicit confirmation, unsaved changes, stale-save feedback
  and permission visibility.

## 2026-10-10 New partner default

The existing `partners.manage` configuration workflow now sets
`card_transfer_blocked=true` when a user becomes an enabled partner (including
re-enabling a disabled partner). This automatic partner default is part of the
partner operation; manual restriction edits retain users.read +
users.restrictions.manage, confirmation and revision checks.
Tenant then User locks serialize the change with financial operation acceptance.
Only the card-funding flag changes; increment the restriction revision and append
PARTNER_CARD_FUNDING_RESTRICTED before/after evidence atomically with partner
configuration. Editing an already-enabled partner preserves a later manual override;
disabling a partner does not clear restrictions. No historical partner backfill,
financial writes, migration or frontend rebuild. Deploy the PHP backend.
