# Money Rules

All amounts are decimal strings in APIs and TypeScript, Brick Math values in PHP, and `NUMERIC(20,8)` in PostgreSQL. Float, double, real, epsilon comparisons, and JavaScript numeric money are forbidden. `Money` normalizes exactly eight decimal places, enforces the 12-integer-digit database bound, and rejects cross-asset arithmetic.

Wallets own a User's asset container and lifecycle but have no balance column. Money belongs to Ledger Accounts. `ledger_accounts.balance` is an atomically maintained cache; immutable `SUM(ledger_postings.delta)` is authoritative. Zero balance is represented by no Postings and does not require a synthetic Entry.

Every committed event passes an explicit Tenant-scoped immutable plan to `LedgerWriter`. The writer bounds the plan at 100 instructions, canonicalizes duplicate account instructions, sorts Account UUIDs, requires at least two distinct non-zero Postings, requires one asset and an exact zero sum, locks accounts in UUID order, verifies Account status/scope/policy, inserts history, writes cached balances, and seals the Entry in one database transaction. Business code, Controllers, Admins, and Users cannot submit arbitrary Account IDs or deltas and cannot directly write Postings or balances.

Positive delta increases any Account and negative delta decreases it. USER_AVAILABLE, USER_SECURITY_DEPOSIT, USER_WITHDRAWAL_HOLD, USER_CARD_ISSUE_HOLD, USER_CARD_FUNDING_HOLD, and TENANT_FEE_REVENUE may never be negative. Only TENANT_TOPUP_CLEARING, TENANT_WITHDRAWAL_CLEARING, and TENANT_CARD_FUNDING_CLEARING permit negative values. This policy comes from the fixed Account Type, never an Admin-configurable column. Manual-adjustment/correction accounts do not exist.

Every event key is unique per Tenant. A length-prefixed canonical event hash covers event type, asset, reference type/id, reversal reference, sorted Account UUIDs, and exact normalized deltas. The same key and plan returns the first Entry; changed financial content yields `LEDGER_IDEMPOTENCY_CONFLICT`. A transaction advisory lock serializes the Tenant/Event Key and the unique index remains the final defense.

Entries and Postings contain only completed financial facts and have no pending/failed lifecycle. A completed Entry is sealed and rejects all later Posting inserts. Eloquent and PostgreSQL triggers reject update/delete. A correction or reversal is a new business-authorized Entry referencing the old Entry; there is no generic reversal endpoint. PostgreSQL composite foreign keys enforce Tenant/Asset consistency, while deferred constraints reject unsealed/incomplete/unbalanced Entries and cached balances that differ from sealed Posting truth.

`ledger:reconcile` compares cached balances with Posting sums for all Accounts or a selected Tenant/Account. It reports safe identifiers, returns non-zero on mismatch, and never repairs data. Redis is never authoritative for money.

No external I/O occurs inside a Ledger transaction. Future provider workflows must persist intent/hold and commit, perform I/O, then settle/release in a new transaction. UNKNOWN remains reconcilable and is never treated as FAILED.

Security Deposit is the USER_SECURITY_DEPOSIT balance, never a separate mutable balance row or persisted qualification flag. Qualification is calculated from the current Ledger balance and current Tenant requirement. Phase 4 displays this state but implements no deposit payment/refund.

V1 has no FX or multi-asset qualification: the Security Deposit asset must equal the Tenant default asset. An unexpected Wallet/requirement mismatch fails closed without cross-asset arithmetic. Changing the required amount changes only real-time qualification and never moves money. Once financial Accounts exist, the default/deposit asset cannot be changed through ordinary settings.
