# Public User account IDs

The requested consumer Account ID is a persisted 12-character string:
`YYYYMMDD` followed by four randomly selected digits (`0000..9999`), for
example `202609034426`. Its date is the User's creation timestamp in their
Tenant's timezone at allocation time. Later timezone, language or profile
changes do not regenerate it. Legacy null creation timestamps use the migration
timestamp; historical timestamps are otherwise preserved.

`users.id` remains the UUID primary key. Sessions, relations, financial orders,
Ledger entries and existing URLs continue to use UUIDs. `users.account_id` is
an additional public identifier, not a password, authentication credential,
authorization boundary, or globally searchable user directory.

## Allocation and persistence

Migration `2026_09_11_000200_add_public_account_ids_to_users` adds the column
without rewriting historical migrations or adding tables/states. It backfills
existing users by trusted Tenant and User IDs; it does not change their UUIDs,
timestamps, contacts, KYC, cards or money. Execution is transactional, locking
Tenants before User schema/backfill locks. Use a maintenance migration window;
do not roll back after users share their assigned IDs, since rollback removes
the IDs.

A PostgreSQL BEFORE INSERT trigger assigns the ID for all insertion paths.
The allocator locks the exact Tenant before reading its timezone and available
suffixes. Normal registration already holds this lock. The volatile allocator
sees committed inserts after waiting; `UNIQUE (tenant_id, account_id)` is the
final concurrency defense. Random selection uses remaining suffixes, not an
unbounded retry loop. IDs remain strings in PHP/JSON/TypeScript, including
leading-zero suffixes. NOT NULL, 12-digit shape, server-only insertion and
update immutability are enforced in the database. Registration rejects
client-provided IDs.

Uniqueness is company-scoped, consistent with same-company recipient scope:
the same displayed ID may exist in another Tenant. Future lookups must resolve
Host -> TenantContext first and query the pair. Capacity is 10,000 IDs per
company per creation date. Exhaustion fails explicitly; it never reuses an ID,
changes the date, adds digits, consumes a registration challenge, or misreports
the error as a duplicate email. The short ID space is enumerable, not a secret;
future recipient verification needs rate limiting and minimal disclosure.

The allowlisted `auth.user.accountId` prop supplies the display/copy control.
It never falls back to a UUID or generates a value in the browser. This change
does not authorize or implement internal transfers.
