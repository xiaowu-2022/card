# Promotion, company cost book and guarantee lifecycle

2026-09-16 paid-promotion revision: see [PAID_PROMOTION.md](PAID_PROMOTION.md).
Paid annual qualification replaces manual assignment. Ordinary members retain
only a 20 USDT direct activation reward; annual percentage differentials and
user-requested, Platform-reviewed fee rebates are separate business events.
The old fixed-only/manual-assignment clauses below are historical where conflicting;
Ledger, invitation, deposit refund and commission-transfer protections still apply.


Status: the user explicitly authorized the separate Promotion / Commission /
Security Deposit lifecycle stage on 2026-09-11 after the architecture conflict was
reported. AGENTS.md contains the narrow authorized extension. The implemented
contracts are specified below. The user subsequently selected a consolidated company fund/cost book,
with no insufficient-budget gate on commissions. The earlier Card-only exclusions are historical for this
authorized stage, not permission to weaken any financial safety constraint.

## Confirmed business rules

- Each Tenant manages its own promotion levels and fixed integer-USDT reward
  amounts. Rewards are not percentages. Multi-level allocation uses level
  differences, not a full reward paid independently to every ancestor.
- The Tenant/company bears the commission cost. Neither the depositor's guarantee
  principal nor another customer's money may be used as the expense source.
- The only earning trigger is a successfully committed positive transfer from the
  customer's available Wallet balance to that customer's Security Deposit.
- Ordinary Wallet top-ups, card reloads, registration, refund requests, refund
  completion and withdrawal of a refund request do not themselves earn commission.
- The initial guarantee payment must first be credited to the Wallet through the
  verified top-up flow, then automatically allocated to Security Deposit. It must
  not skip Wallet credit or treat an external payment notification as deposit
  funding. The server calculates the remaining guarantee requirement.
- After an actual guarantee refund, a new successful Wallet-to-Deposit funding
  event can earn commission again. There is no once-per-user lifetime reward cap.
- Cancelling a pending guarantee-refund request is not a new funding event and
  earns nothing, even if implementation later includes releasing a refund hold.
- A guarantee refund does not stop commission earnings or claw back balances.
  The user-confirmed 2026-09-14 revision blocks new commission-to-Wallet transfers
  during and after the recipient's own refund, while existing Wallet balance remains
  withdrawable. This supersedes the old no-transfer-restriction interpretation;
  no freeze account or reward reversal. See COMMISSION_REFUND_RESTRICTION.md.
- Exactly one commission allocation may be created for a given committed funding
  event and beneficiary. Retries, duplicated notifications or page refreshes must
  not issue a second reward. A genuine later funding event has its own identity.

## Earlier requirements still applicable

- Registration requires a valid Tenant-scoped invitation code. A code supplied by
  an invitation link is prefilled and not editable; the server must bind and
  validate it, rather than trusting a readonly input.
- Users may assign a promotion level only to their direct invitees and only below
  their own level. Cross-Tenant assignment, relationship cycles and client-selected
  ownership are forbidden.
- Promotion shows the user's invitation link/code, full-team invitation and
  activation counts, funded guarantee amounts and commissions. Include daily
  movements, defaulting to today with an earlier-date selector.
- Commission is displayed only in Promotion, not in the home balance. The user
  must manually transfer eligible commission into their Wallet balance before it
  can use the existing withdrawal flow. No direct commission withdrawal endpoint.

## Calculation contract

Traverse the invitation ancestry from nearest inviter upward. Maintain the highest
fixed reward already allocated below the current ancestor, starting at zero. An
ancestor earns the positive difference between their fixed reward and that running
maximum; an equal or lower reward earns zero and never resets the maximum.
For ancestor rewards 5 / 10 / 15 U the allocation is 5 / 5 / 5 U, totaling 15 U.
These are illustrative values, not Tenant defaults. Snapshot the applicable
relationship and rule versions against the funding event; later edits must not
rewrite already earned commissions. No equal-level bonus or percentage reward.

## Authorized architecture extension

Previous rules: the Card-only authorization excluded commissions and guarantee
refunds; SECURITY_DEPOSIT_RULES.md permitted explicit user funding
only and deferred refunds until card cancellation, unresolved-provider-operation and
provider-balance checks existed. This stage introduces the required commission
accounts and separate guarantee-refund workflow without weakening those checks.

Required extension: authorize referral relationships and Tenant reward rules,
company-funded commission accounting and manual commission-to-Wallet transfer,
initial automatic guarantee allocation, and a distinct guarantee refund / request
cancellation lifecycle. Card balance return must remain separate from guarantee
refund. A functioning Card cancellation endpoint alone does not constitute that
refund workflow.

Affected modules: User registration, Tenant administration/settings, new Promotion
and Commission use cases, Security Deposit, Payment/Wallet application orchestration,
Ledger account definitions/migrations, read-only Card eligibility checks, reporting
and consumer UI. Ledger must remain unaware of business domains and all financial
posting must still go through LedgerWriter. Provider adapters must not post money.

The company fund book is a consolidated read-only accounting view over immutable
Ledger events, not a second balance store or a customer-money spending pool.
Define TENANT_COMMISSION_CLEARING as the dedicated company-funded expense / payable
counter-account; its negative balance records accumulated reward obligations and is
explicitly permitted. No company-budget sufficiency gate or pending-budget state.
USER_COMMISSION is nonnegative, User/Tenant/USDT-scoped and independent of Wallet
activation, so a promoter can earn without bypassing KYC/Wallet activation gates.
Its Wallet id is null and its User FK is composite Tenant-scoped. A manual transfer
requires the user's active verified Wallet, then moves USER_COMMISSION to
USER_AVAILABLE through LedgerWriter. Other existing account ownership and negative
balance constraints remain unchanged. Do not interpret customer top-up principal as
company revenue or a commission-to-Wallet transfer as a second company expense.

Financial schema: promotion_funding_events snapshots each qualifying sealed
SECURITY_DEPOSIT_FUND event and its source user/amount once; commission_awards holds
immutable per-beneficiary level/revision/amount and settlement Entry references;
commission_transfers holds immutable idempotent USER_COMMISSION-to-USER_AVAILABLE
receipts. No mutable commission balance column. Reporting distinguishes cash flow,
customer liabilities, fee revenue and accrued commission expense.

Initial-root invitation provisioning, daily reporting timezone/count semantics,
refund eligibility and outstanding-order handling are defined below. Repeated successful
refund-and-fund cycles can generate repeated company expenses under the confirmed
rule; do not silently add a cooling period, lifetime cap, commission freeze or
automatic re-funding loop as an anti-abuse shortcut.

## Non-financial foundation schema

`promotion_levels`: Tenant-owned rank, display name and integer-USDT reward
configuration stored as NUMERIC(20,8), with monotonically increasing revision.
Rank and Tenant ownership cannot change. Configuration never moves money.

`promotion_members`: one entry per Tenant/User, immutable server-allocated
six-digit invitation code and optional immutable direct inviter, plus the assigned level.
Composite foreign keys enforce the same Tenant for User, inviter and level;
cycles and reparenting are forbidden. Existing users can become independent roots;
do not invent historical referral links. New registrations will bind a validated
inviter to the owned OTP challenge before creating User and referral membership
atomically. Unranked users have reward zero, not an invented default tariff.

`promotion_company_invitations` contains one immutable six-digit company code
per Tenant. The company admin can share it to enroll
independent roots without a fictitious upstream beneficiary. Member and company
codes share a global transactional counter starting at 523612; lookups remain
Tenant-scoped. The user authorized a one-time migration of old codes with legacy
link aliases; see `PROMOTION_INVITATION_CODES.md`. Registration links
bind the first valid code to the initiating session, and both email and SMS
challenges persist the immutable trusted member/company identity. Public completion
uses CompleteInvitedRegistrationAction to create the User and PromotionMember in
the same outer transaction around the existing verified challenge contract.
Trusted internal RegisterUserAction callers keep the prior contract; public HTTP
cannot bypass invitation validation. Historical unbound challenges must be replaced.

## Reporting and tenant administration

GET `/promotion` includes the user's own commission account separately from the
entire descendant tree. Invitation counts are all descendants; activation counts
are unique descendants with a first recorded qualifying funding event. A later
genuine re-funding increments deposit volume and commissions, not activation count.
Team commission is awards to the current user and their descendants. No historical
commissions or referrals are fabricated or backfilled. Day boundaries use the
Tenant timezone, stored timestamps remain UTC, and the default day is today.
Daily details include invitations, first activations, deposit funding and awards,
with stable ordering and pagination. Direct invitees are independently paginated.

GET/POST `/admin/promotion` requires exact resolved-Tenant active admin membership
and `tenant_settings.manage`. Levels have optimistic revisions, immutable rank and
non-decreasing fixed integer rewards by rank; no tariff is enabled by default.
Company admins can assign member levels; end users can change only direct
invitees to a level strictly below their own. Unranked means zero reward.

GET `/admin/company-funds` has the same permission gate and reports both lifetime
totals and a selected day's completed movements. It aggregates immutable sealed
USDT events: external customer top-ups and withdrawals, guarantee transfers,
card funding/returns, fee income, commission cost and commission-to-Wallet receipt.
It is a company-wide cost book, not a writable budget, custody balance or profit
claim. Commission cost counts COMMISSION_EARN once; COMMISSION_TRANSFER is not a
second expense. Only designated company clearing accounts may go negative.

## Guarantee lifecycle contract

`initial_deposit_intents` is a durable Tenant/User intent linked to the first
verified credited USDT top-up seen by this version. Retry under Tenant/User locks,
after the top-up transaction commits. If any genuine deposit-funding history exists,
finish without another allocation; otherwise fund the full server-calculated
remaining requirement once eligibility and available funds permit it. Do not
retroactively allocate old balances or automatically re-fund after a refund. Failure
does not undo an externally paid Wallet credit. Scheduler retries unfinished intents.

`security_deposit_refund_requests` has CHECKING, COMPLETED and CANCELLED states.
The user requests their entire current positive guarantee balance with a stable
request UUID, password and confirmation. No administrator refund path. While
CHECKING, reject new guarantee funding and all card actions except transaction
reads. The approved 2026-09-14 timed workflow supersedes the earlier manual check,
cancelled-card and zero-balance requirements for new requests. Snapshot SaaS-only
company waiting days, freeze cards automatically, and refund after the deadline
only with authoritative frozen/cancelled status and resolved issue/management
operations. Re-lock Tenant/User/request and compare the exact card set and refresh
generations before transferring USER_SECURITY_DEPOSIT to USER_AVAILABLE. Unknown
or unavailable results preserve principal and restrictions. Cancellation restores
only cards frozen by that request before unlocking; pre-existing frozen cards stay
frozen. Refunded cards remain transaction-read-only. Legacy requests are not replayed
or assigned deadlines; the user may cancel and reapply. No separate deposit hold,
commission changes or automatic re-funding. See TIMED_DEPOSIT_REFUNDS.md.

## Runtime and verification

Apply the additive `000600` through `000900` migrations; never refresh a live
database or replay historical funding. Financial migrations are forward-only.
The standard supervised queue worker (`php artisan queue:work --tries=3`) handles
post-credit initial allocation. The Laravel scheduler runs `promotion:recover`
every minute, retrying durable allocation with
explicit Tenant/resource identifiers. Each sweep is bounded and rotates pending
items by last attempt so unavailable accounts do not starve later requests.
Neither a stopped worker nor a failed provider lookup undoes a verified Wallet
credit. In deployments without a scheduler, operators may run the same scoped
command `php artisan promotion:recover --tenant=<trusted-tenant-uuid>`; it is an
application retry, not a manual monetary adjustment.

Timed refunds use a separate `deposits:process-refunds` command/job, scheduled every
minute and restricted to explicit new timed requests. It never scans legacy refunds
or replays unrelated allocation jobs. See TIMED_DEPOSIT_REFUNDS.md.

Financial tests use isolated PostgreSQL `card_ui_test`, LedgerWriter test funding,
and fake external transports. Browser verification must intercept financial writes;
never issue, fund, cancel or refund a live card just to test the UI. Live PhotonPay
credentials, authoritative zero-balance checks and supervised workers remain
deployment prerequisites, not simulated successes.
# Consumer navigation (2026-09-11)

`/promotion` retains personal commission, explicit transfer confirmation and the
invitation link, with four real navigation links: `/promotion/team`, `/promotion/daily`,
`/promotion/direct`, `/promotion/commissions`. Daily and direct pagination stays
within the selected child route; member-level changes retain the original POST
authorization and strict below-own-rank check. Commission history is a read-only,
tenant/user-scoped union of immutable awards (positive) and completed commission
transfers (negative), not team earnings. It defaults to all dates and supports
company-timezone date filtering and 30-row pagination. No Ledger writes, new
financial states, award recalculation or historical mutation are introduced.

### Daily activity invitation and commission provenance (2026-09-15)

Daily activity identifies the source depositor and their immutable direct inviter
by public account ID, distinguishing the viewer's own invitees from team invitees.
Commission rows show only awards earned by the current viewer, together with the
source funding amount; the Chinese label is “佣金”. Recipient copy is omitted.
The daily commission total uses the same current-user scope and is labelled
“My commission”; the separate team overview retains its team aggregate. Source funding joins match both tenant
and funding-event ID. Relationship lookups remain tenant scoped and batched for
the current page. Only allowlisted account IDs and decimal amounts reach the UI;
no contact or identity data is exposed. Reporting does not change commissions,
Ledger history, invitation relationships, or pagination semantics.
