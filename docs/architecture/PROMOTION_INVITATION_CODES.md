## Company code management (2026-10-17)

Platform company configuration → Paid promotion settings exposes the company invitation
code, enabled status and a separately paginated change history. Active Platform
administrators with `tenant.manage` may submit an explicit confirmation and reason to
change the code or disable/re-enable it. Company suspension still allows disabling;
enabling requires an active company. Six-digit replacements must be globally unused
and at/after the allocation counter. Every old/new code remains permanently reserved.

`company_invitation_changes` is immutable append-only evidence and the effective
settings projection; original `promotion_company_invitations` rows remain immutable.
Changes lock the company before the allocation counter, enforce stale revisions and
request replay consistency, and append audit evidence atomically. GETs do not provision
company invitations. Registration challenge acceptance rereads effective settings under
the same company lock. Replaced codes, disabled codes and retired legacy aliases fail
closed; individual codes and existing memberships are unchanged. Challenges accepted
before a change retain their original binding and may finish registration normally.

Deploy migration `2026_10_17_100000_add_company_invitation_changes.php` with PHP and
administrative assets. No consumer rebuild, provider operations or financial changes.
Validate only against isolated fixtures, never a production database.

# Sequential promotion invitation codes

User approval: 2026-09-11, explicitly superseding the old random immutable
24-hex-code format and authorizing a one-time migration of existing codes.
Admin invitation/authentication tokens are unrelated and remain unchanged.

Company and individual promotion codes share a global namespace starting at
523612. `promotion_invitation_counter` has exactly one row (id 1) and a next_value
in 523612..1000000. Before-insert triggers on both invitation tables allocate by
an atomic row update; callers must omit invitation_code. Rollback rolls back the
allocation. Existing-membership lookups consume nothing. Platform now has the narrowly scoped edit operation described below; no reset
or consumer edit endpoint exists. 1000000 is an exhausted sentinel, never a seven-digit code;
allocation fails explicitly without creating a user or changing money.

Migration 001100 takes transaction-scoped table locks, assigns by creation time,
then kind (company before member), then UUID. Existing User/member/company IDs,
inviter IDs, levels, timestamps, OTP challenge bindings and commission/ledger
records are preserved. Companies without codes and existing users without a
membership receive one; these users become independent roots with their original
creation time, never invented upstream relations or retroactive commissions.
Only this locked transaction disables the two code immutability triggers; they
are restored before commit. The migration is forward-only and capacity-checked.

`promotion_invitation_aliases` maps (tenant_id, old_code) to exactly one original
member/company UUID using composite tenant FKs. Aliases are immutable; no API can
create them. Legacy URLs and already-open registration sessions resolve to the
owner's current code only until that member's first explicit code change, retaining server-side invitation locking and active inviter
checks. Old 24-hex values are accepted only if an alias exists in the resolved
tenant. New input is six numeric characters, and all links/display use the new
code. A globally unique code never authorizes access across company hosts.

Public registration still uses the locked verified challenge and creates its
membership in the same outer transaction. Counter locking follows Tenant/User
ownership locks. Allocation must not be followed by acquiring another Tenant's
lock. No financial domain or balances are modified by allocation or migration.
# Invalid browser selection recovery (2026-09-12)

Registration releases a saved browser invitation only when tenant-scoped enrollment
returns INVITATION_INVALID (for example, a suspended inviter). A new valid link is
then prefilled and locked; without one, render the required invitation field and a
localized validation hint instead of an unrecoverable error page. An existing valid
selection still wins over another URL. This never rewrites persisted challenge
inviters, member/company codes, invitation relationships or commission records.

## Platform changes (2026-10-06)

This approval supersedes code immutability only for the audited Platform operation.
GET/POST `/platform/tenants/{tenant}/users/{user}/invitation-code` require both
`users.read` and `users.invitation.manage`. Owner/Admin receive the new permission;
other roles do not. The user-list More actions opens the existing lazy editor,
retaining background filters, page, scroll, focus and dirty/busy guards. GET returns
an explicit account DTO, current code/revision, counter position, edit eligibility
and paginated immutable history; it never creates a missing membership.

POST accepts `new_code`, `old_code`, `revision`, `reason` (nonblank, max 500),
`request_id` (UUID), and `confirmed`. New codes must be six ASCII digits between
the locked global counter position and 999999 inclusive, globally unused. A valid
active Platform actor is reauthorized inside the transaction. Company and user
must be active. Locks follow Tenant -> User -> member -> global counter.
Successful retries with identical actor/normalized payload return their original
evidence, even if a later edit changed the code again; conflicting retries and
stale revisions return 409. Permissions and ownership are rechecked on retries.

`promotion_invitation_reservations` permanently owns each code for a tenant and
member/company UUID. Its global primary key prevents cross-company reuse. Revision
zero denotes initial allocation; manual reservations carry the target member's
new invitation revision. The forward-only migration locks both invitation tables
and the counter, registers existing codes without renumbering, and fails on any
duplicate. It does not create users, wallets, memberships or financial entries.

`promotion_invitation_changes` stores old/new codes, member/user/company, revision,
actor ID/name snapshot, request UUID, reason, transaction ID and timestamp. Reservation,
evidence, member update and audit commit atomically. Both tables reject UPDATE and
DELETE. Database guards require matching current membership, revision and same-
transaction change evidence; deferred validation rejects unapplied evidence. All
other membership/relationship protections remain in place.

The existing before-insert allocator remains authoritative for both company and
member codes, including ordinary and Platform account creation. It advances the
counter one step at a time, skipping permanent reservations and reserving the
selected code atomically. Manual selection does not advance the counter past a
far-future code. Rollback undoes reservations and allocation. Exhaustion never
wraps or reuses retired codes; an all-reserved tail fails without business writes.

Old numeric codes are never reallocated or treated as aliases. Existing 24-hex
member aliases resolve only while `invitation_revision=0`; company aliases remain
unchanged. New challenge creation revalidates the submitted code after locking
the company and before writing a challenge. Existing persisted challenges complete
against their member UUID, including after a code change. Invalid saved browser
selections can be replaced; App/H5 unlocks an already-open form on INVITATION_INVALID.
Reloaded invitation views/share links use the new code. No relationship, activation,
commission, wallet, identity or historical financial evidence changes.

Deploy migration `2026_10_06_220000_add_invitation_code_changes` before updated
backend and admin assets, in a maintenance window to keep old registration code
from racing changes during rollout. Rebuild the uni-app H5/App resources for the
open-form recovery change. Do not exercise changes against real customers as a
deployment test. Validate in isolated card_ui_test and offline browser fixtures.

### 2026-10-11 reservation insertion ordering

Migration `2026_10_11_210000_reserve_invitation_codes_after_insert` keeps allocation
in the existing BEFORE INSERT trigger and inserts permanent reservations in an
AFTER INSERT trigger on both member and company invitation tables. The referenced
identity therefore exists even with immediate foreign key checks. The counter lock,
reserved-code skipping and reservation remain in the same transaction; any failure
rolls back all three. Existing codes, reservations and financial history are unchanged.
Deploy with `php artisan migrate --force`; no production registration test or
provider request is needed. Rollback requires a forward migration.
