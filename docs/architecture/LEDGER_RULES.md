# Ledger Rules

## Wallet and accounts

A Wallet is one User+Tenant+Asset lifecycle container and never stores balance. Activation is a separate Application use case after KYC approval; KYC never creates it. Activation provisions five zero-balance User Accounts and ensures four Tenant System Accounts for the default asset. It creates no Ledger Entry.

User Accounts are USER_AVAILABLE, USER_SECURITY_DEPOSIT, USER_WITHDRAWAL_HOLD, USER_CARD_ISSUE_HOLD, and USER_CARD_FUNDING_HOLD. Tenant Accounts are TENANT_TOPUP_CLEARING, TENANT_WITHDRAWAL_CLEARING, TENANT_CARD_FUNDING_CLEARING, and TENANT_FEE_REVENUE. Manual/correction/Admin adjustment Accounts are prohibited.

## Posting invariant

Positive delta increases an Account; negative delta decreases it. An Entry contains one asset, at least two distinct non-zero Postings, and sums exactly to zero at eight-decimal precision. Composite foreign keys bind every Posting to an Entry and Account of the same Tenant and Asset. User Accounts and fee revenue cannot become negative; defined Tenant clearing Accounts may.

`ledger_accounts.balance` is a cache updated by `LedgerWriter` in the same transaction as history. `SUM(ledger_postings.delta)` is truth. No database trigger also writes the cache.

Every completed Entry has a non-null `sealed_at`. The only permitted Entry update is its initial `NULL -> timestamp` sealing transition after Postings and cached balances are written. A deferred database invariant rejects unsealed, one-Posting, or unbalanced Entries at commit. A Posting insert takes a lock on its parent and succeeds only while that Entry is unsealed; after commit, even an additional balanced Posting pair is rejected permanently. Entry/Postings update and delete remain forbidden.

Deferred database validation also requires each affected Account cache to equal the sum of Postings belonging to sealed Entries. This is validation only: `LedgerWriter`, not a trigger, calculates and writes balances. Reconciliation remains mandatory for corruption, restore, and maintenance verification and never repairs data.

## Write path and concurrency

`LedgerWriter` is the only production Posting path. It receives explicit Tenant and immutable business-built instructions; it never reads auth, Host, or Session and knows no KYC/Card/Payment rules. It canonicalizes at most 100 instructions, takes a Tenant/Event advisory transaction lock, checks idempotency, locks all Account rows in sorted UUID order, validates resulting balances and bounds, inserts the Entry/Postings, updates cached balances, seals the Entry, and completes its transaction. External network/storage/message I/O is forbidden inside this transaction.

`LedgerWriter` is safe when called inside an outer business transaction: Laravel's nested transaction/savepoint participates in the outer boundary, so an outer rollback removes Entry, Postings, and balance changes and locks remain held until the outer commit. Global financial lock order is fixed: (1) business aggregate/order row, (2) business-specific advisory lock, (3) Ledger event transaction-scoped advisory lock, (4) Ledger Accounts sorted by UUID, (5) other derived rows. Business/Application code must never lock a Ledger Account before a business row or before calling the writer. Account locks belong exclusively to `LedgerWriter`.

Event Keys are private business type+UUID+stage values, never PII or amounts. The canonical event hash uses length-prefixed fields, normalized exact decimal strings, and sorted Account UUIDs. Same key/same hash returns the existing Entry. Same key/different hash is a conflict and never overwrites or reposts.

The event lock uses deterministic SHA-256 bytes mapped explicitly to a PostgreSQL signed bigint and `pg_advisory_xact_lock`; it is Tenant-scoped, process-stable, and never survives transaction end. Duplicate Account instructions are consolidated before hashing, and `1`, `1.0`, and `1.00000000` have one canonical value. Null reference fields are distinct from invalid empty strings. Retried calls always re-lock and re-read Accounts. Unknown client outcomes are retried with the same Event Key.

## History and reversal

Entry and Posting rows are completed immutable financial history. Eloquent guards and PostgreSQL update/delete rejection triggers are defense in depth. Reversal always creates a new business-specific Entry with `reversal_of_entry_id`; no Admin or generic reversal endpoint exists.

Account Tenant, Wallet, User, Type, and Asset and Wallet Tenant, User, and Asset are immutable after creation. Account status remains a future lifecycle capability, but no generic status mutation API exists. A reversal cannot reference itself; multiple future business-authorized reversal links remain possible.

## Reconciliation and presentation

Reconciliation compares the cached Account value to the authoritative Posting sum, reports safe identifiers, exits non-zero on mismatch, and never auto-fixes. End Users see business-friendly activity rather than Account/Postings/clearing terminology. Tenant Admin views are permission-gated and read-only. Amounts leave PHP only as `{ amount: string, asset: string }`.

Production Ledger table write privileges belong only to the Application role and controlled migrations. Analytics users, BI tools, support scripts, and ad-hoc operational users must be read-only. Direct SQL is an operationally privileged boundary, not a supported Posting path.
