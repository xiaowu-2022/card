# Money and Ledger Rules

All amounts are decimal strings in APIs/TypeScript and `NUMERIC(20,8)` in PostgreSQL. PHP uses a decimal implementation; float, double, and real are forbidden. `Money` contains amount plus asset and rejects cross-asset arithmetic.

The future Ledger schema is `wallets`, `ledger_accounts`, `ledger_entries`, and `ledger_postings`. Each entry has one asset and postings sum to zero. Positive deltas increase an account; negative deltas decrease it. User accounts cannot be negative. Planned accounts include USER_AVAILABLE, USER_SECURITY_DEPOSIT, USER_WITHDRAWAL_HOLD, USER_CARD_ISSUE_HOLD, USER_CARD_FUNDING_HOLD, TENANT_TOPUP_CLEARING, TENANT_WITHDRAWAL_CLEARING, TENANT_CARD_FUNDING_CLEARING, and TENANT_FEE_REVENUE.

`ledger_accounts.balance` is a cache; postings are authoritative. Reconciliation compares the cache with `SUM(postings)` and alerts on differences—it never silently repairs them. Historical entries/postings are never updated or deleted; a new reversal/refund business flow corrects errors. No generic balance setter exists and no admin, including Platform Owner, can manually change Wallet, Deposit, Commission, or Ledger.

Every money-changing request uses a request id and a unique business-order constraint. The same id and payload returns the original result; a different payload yields `IDEMPOTENCY_CONFLICT`. External workflows persist order/hold/provider operation and commit, call the provider without DB locks, then settle/release in a new transaction. UNKNOWN remains held/reconcilable rather than being treated as failure.

Security Deposit is the USER_SECURITY_DEPOSIT ledger balance, never a mutable `security_deposits` row or `deposit_satisfied` flag. Qualification is `actual >= tenant requirement`; changing a requirement changes eligibility only.

Refund is a future dedicated saga: preview permanent card cancellation; obtain explicit second confirmation; block pending/processing/unknown issue or load operations; block known positive card balances in V1; freeze new issue/load; cancel every non-terminal card; refund only after all are confirmed CANCELLED. If balance lookup is unsupported, disclose that and obtain risk confirmation. Any cancellation failure blocks refund. Admins cannot force refund or force a card to CANCELLED.
