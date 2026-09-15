# SaaS-controlled card opening fees

Approved 2026-09-14: SaaS configures each product's opening fee in USDT; company
administrators cannot change it. This supersedes the tenant-only opening-fee rule
in Phase 9. Other tenant display/availability/limits settings remain unchanged.

`card_products.opening_fee` is nullable NUMERIC(20,8), nonnegative. Platform product
create/update requires an explicit decimal string, guarded by card_product.manage
and existing active Platform membership. Product writes and audit only; no funds
move during configuration. Null means unconfigured, never a zero-fee fallback.
New products require a value, including explicit zero if free issuance is intended.

The forward migration preserves a unanimous existing company price. Conflicting
or absent company prices leave the platform price null until SaaS configures it.
Historical tenant fee columns are retained as non-authoritative legacy values;
company API and action both prohibit opening_fee input. Company UI displays the
platform fee read-only. Existing company rows are not repriced or rewritten.

The company offering page displays the fee without an edit shortcut, as requested.
Fee editing remains in the global product catalog. Its optional `edit` query selects
only an existing product in the authorized catalog and never changes data. Saving
uses the existing global product update action; no company-specific fee is introduced.
Company views omit configuration-ownership notices while retaining server-side
read-only enforcement.

Consumer catalog quotes and new issue holds use the locked platform product fee.
Products with null prices are omitted from consumer catalogs and issuance fails
before holds. Previously persisted orders, including UNKNOWN reconciliation,
retain their original fee snapshot and deterministic hold/settlement/replay paths.
No Ledger change, historical rewrite, provider call or replay is caused by migration.
Revenue routing remains the existing Tenant fee revenue contract; this approval
changes pricing control only, not fee beneficiaries or settlement economics.
