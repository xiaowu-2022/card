# Sequential promotion invitation codes

User approval: 2026-09-11, explicitly superseding the old random immutable
24-hex-code format and authorizing a one-time migration of existing codes.
Admin invitation/authentication tokens are unrelated and remain unchanged.

Company and individual promotion codes share a global namespace starting at
523612. `promotion_invitation_counter` has exactly one row (id 1) and a next_value
in 523612..1000000. Before-insert triggers on both invitation tables allocate by
an atomic row update; callers must omit invitation_code. Rollback rolls back the
allocation. Existing-membership lookups consume nothing. No user/admin edit/reset
endpoint exists. 1000000 is an exhausted sentinel, never a seven-digit code;
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
owner's current code, retaining server-side invitation locking and active inviter
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
