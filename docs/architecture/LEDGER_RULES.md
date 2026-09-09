# Ledger Rules

## Wallet and accounts

A Wallet is one User+Tenant+Asset lifecycle container and never stores balance. Activation is a separate Application use case after KYC approval; KYC never creates it. Activation provisions five zero-balance User Accounts and ensures four Tenant System Accounts for the default asset. It creates no Ledger Entry.

User Accounts are USER_AVAILABLE, USER_SECURITY_DEPOSIT, USER_WITHDRAWAL_HOLD, USER_CARD_ISSUE_HOLD, and USER_CARD_FUNDING_HOLD. Tenant Accounts are TENANT_TOPUP_CLEARING, TENANT_WITHDRAWAL_CLEARING, TENANT_CARD_FUNDING_CLEARING, and TENANT_FEE_REVENUE. Manual/correction/Admin adjustment Accounts are prohibited.

## Posting invariant

Positive delta increases an Account; negative delta decreases it. An Entry contains one asset, at least two distinct non-zero Postings, and sums exactly to zero at eight-decimal precision. Composite foreign keys bind every Posting to an Entry and Account of the same Tenant and Asset. User Accounts and fee revenue cannot become negative; defined Tenant clearing Accounts may.

`ledger_accounts.balance` is a cache updated by `LedgerWriter` in the same transaction as history. `SUM(ledger_postings.delta)` is truth. No database trigger also writes the cache.

## Write path and concurrency

`LedgerWriter` is the only production Posting path. It receives explicit Tenant and immutable business-built instructions; it never reads auth, Host, or Session and knows no KYC/Card/Payment rules. It canonicalizes instructions, takes a Tenant/Event advisory transaction lock, checks idempotency, locks all Account rows in sorted UUID order, validates resulting balances and bounds, inserts the Entry/Postings, updates cached balances, and commits. External network/storage/message I/O is forbidden inside this transaction.

Event Keys are private business type+UUID+stage values, never PII or amounts. The canonical event hash uses length-prefixed fields, normalized exact decimal strings, and sorted Account UUIDs. Same key/same hash returns the existing Entry. Same key/different hash is a conflict and never overwrites or reposts.

## History and reversal

Entry and Posting rows are completed immutable financial history. Eloquent guards and PostgreSQL update/delete rejection triggers are defense in depth. Reversal always creates a new business-specific Entry with `reversal_of_entry_id`; no Admin or generic reversal endpoint exists.

## Reconciliation and presentation

Reconciliation compares the cached Account value to the authoritative Posting sum, reports safe identifiers, exits non-zero on mismatch, and never auto-fixes. End Users see business-friendly activity rather than Account/Postings/clearing terminology. Tenant Admin views are permission-gated and read-only. Amounts leave PHP only as `{ amount: string, asset: string }`.
