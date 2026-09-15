# Timed security-deposit refunds

Approved by the user on 2026-09-14, following SaaS-only company configuration.
This replaces manual customer checking and the cancelled-card/zero-card-balance
prerequisite for **new** timed refund requests. It does not create an administrator
refund or a generic balance adjustment.

## Request and card restrictions

A password-confirmed user request with a stable UUID snapshots the complete current
positive deposit amount, company's configured integer waiting days and absolute
deadline. Null configuration rejects the request; zero days is an explicit setting.
The additive `2026_09_14_000200` migration makes the days/deadline immutable and
requires the deadline to equal creation time plus the configured number of 24-hour
days. No retrospective deadlines, default values or historical money changes.

The committed CHECKING request immediately blocks new card applications, holder
materials and all user card-management actions except transaction reads. The worker
freezes each normal card through CardProviderInterface, outside the financial
transaction. Stable UUIDv5 request IDs derive from refund/card/operation identifiers.
UNKNOWN is queried with that same intent, never resent under another ID. Cards
already frozen or cancelled need no new freeze. New cards arriving from a previously
pending issue are included in subsequent checks. No raw provider error reaches users.

Progress is freezing, waiting, blocked or restoring while CHECKING; completed and
cancelled accompany the existing terminal states. The UI displays a server-time-based
countdown and periodically refreshes read-only preview data. There is no customer
"check and complete" action or endpoint. Card/provider uncertainty retains principal
and restrictions even after the timer expires; elapsed time alone is not proof of
freezing. This is not a separate provider approval of the deposit refund.

## Settlement and cancellation

At the deadline, refresh all owned cards, then lock Tenant, User, refund and the exact
ordered card set. Check matching refresh generations, frozen/cancelled status and no
unresolved issue/management orders. Card balances need not be zero. The deposit must
still equal the immutable request amount. LedgerWriter alone posts the exact two-sided
deposit-to-available event `deposit_refund:{refund_uuid}` inside the outer transaction;
the request completes and SYSTEM audit is appended atomically. Existing immutable
accounting guards remain. New database guards additionally reject early settlement,
cancellation intent, changed card evidence and unresolved operations.

Cancellation first persists immutable cancellation intent under the same owner lock.
The worker reconciles outstanding request-generated freeze operations and restores
only cards successfully frozen by this request, using one stable UNFREEZE intent.
Unknown restoration retains restrictions. Pre-existing frozen cards are not unfrozen.
Only after restoration confirmation is the request CANCELLED. A completed refund
cannot be cancelled; its cards stay frozen and transaction-read-only. No automatic
re-funding, commission freeze/clawback, additional reward or provider card-balance
movement occurs. Future new-card applications still need normal deposit eligibility.

The subsequent 2026-09-14 commission eligibility revision blocks new commission
transfers during CHECKING and after COMPLETED refunds. Earnings continue and existing
Wallet balance remains withdrawable; balances are not frozen, removed or clawed back.
See COMMISSION_REFUND_RESTRICTION.md. This restriction is separate from card freezing.

## Runtime and legacy safety

`ProcessSecurityDepositRefund` carries explicit trusted tenant/resource IDs and is
dispatched after request/cancellation commit. `deposits:process-refunds` iterates
active companies deliberately, selecting at most 100 pending timed requests each,
oldest update first. It accepts an optional trusted `--tenant` scope and is scheduled
every minute. `--watch` runs the dedicated worker every 30 seconds. The isolated local
`card-mock-refunds` compose service uses only local/card_mock/explicit mock settings;
it never processes unrelated jobs. Production needs supervised queues/scheduler and
real provider credentials; local success is not live provider acceptance.

Legacy requests (`refund_wait_days IS NULL`) are never automatically processed by
this worker or the old promotion recovery command. They display a safe cancel/reapply
message; cancellation changes only the request. Historical requests, cards, Ledger
entries and company waiting-day settings are not migrated or invented.

Tests run only on isolated `card_ui_test` with offline provider fakes and LedgerWriter
fixtures. Never submit a user refund, freeze a live card, change company policy or
replay historical jobs merely to verify the UI.

Acceptance (2026-09-14): 188 related backend tests passed (1,637 assertions), plus
129 card/local-simulator/architecture checks passed (1,131 assertions; overlapping
card tests, not an additive count). Frontend catalog/regression tests: 59 passed.
Typecheck, focused lint and production asset build passed. The additive migration
and dedicated worker were enabled only on verified local/mock/card_mock. A read-only
browser check showed the new request UI, configured 30-day period and History link,
with no manual check button. No customer request or financial operation was submitted.
