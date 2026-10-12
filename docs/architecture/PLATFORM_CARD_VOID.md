# Platform card voiding

Approved on 2026-10-12: Platform card management provides single-card and explicitly selected batch voiding. Refund all remaining provider and local overflow balances to each cardholder's own active USDT wallet, then cancel the card. This is a financial operation, not deletion of card history.

## Scope and confirmation

The server requires active Platform membership and `cards.read`, `card_product.manage`, `wallet.read`, `ledger.read`. The server supplies `canVoidCards` using the same policy. Creation accepts 1–500 distinct card UUIDs, an optional exact company scope, a UUID request identity and explicit confirmation. Request identity binds the normalized selection/company permanently. No implicit all-company/all-filter execution is supported. Company, user, product and provider card bindings are checked at execution. Company cleanup fences and Tenant → User → Card locks protect reservations and local financial transitions. Foreign-company selections fail closed.

`card_void_batches` and `card_void_items` retain immutable ownership and audit identities. One active item per card is enforced in PostgreSQL. Reads are actor-owned and read-only, never contacting providers or advancing financial work. New conflicting card operations and security deposit refund requests are blocked while an item is pending, running or unknown. Accepted management operations retain their existing replay/recovery semantics. Existing unsettled operations or deposit-refund workflows must finish before voiding.

## Execution

The browser sends one explicit advance POST at a time; a session advisory lock and expiring item lease prevent concurrent execution. Closing the drawer pauses subsequent steps. Reopening task history reads results only; Continue explicitly resumes. Accepted in-flight steps may still finish after closing. No unattended scheduler was added.

Each item proceeds through CHECK → FREEZE → RETURN → OVERFLOW → CANCEL → VERIFY → DONE:

- Refresh provider evidence; normal cards are frozen first, already frozen cards skip freezing. Expired cards are supported only with a verified zero provider balance. Other unsupported states fail visibly.
- Snapshot the frozen provider balance and submit one idempotent RETURN intent using the existing verified card management and USDT ledger path. Credit the verified actual arrival, with the provider's reported fee, rather than the cached balance or guessed fee. PhotonPay's verified API accepts cent precision; a balance with additional significant decimals stops for review without truncation.
- Require a freshly confirmed zero provider balance. Return the entire native-precision local overflow balance through a sealed `CARD_OVERFLOW_RETURN` Ledger entry: debit the scoped card overflow account and credit its user's USDT available account. Immutable `card_overflow_movements` RETURN evidence associates the batch item and administrator; deferred accounting triggers still validate ownership, amounts and postings. No new wallet is provisioned.
- Submit CANCEL only after both balances are verified zero. Confirm provider cancellation and zero balances with no unresolved management intent. Archive the cancelled empty card to free its active slot, preserving the original card, orders, transactions, ledger and audit history.

A timeout stays UNKNOWN and retains the card reservation. Recovery queries the existing deterministic provider intent (UUID5 from item/kind/attempt); it never blindly repeats an upstream refund or cancellation. Definitive FAILED steps alone may be retried through explicit confirmation, with a new attempt identity. Already successful refunds remain immutable and included in result totals. Other cards in a partial batch continue independently. Tasks show confirmed amount returned, provider fee and per-card progress/results. They do not declare success while any balance or cancellation result is unknown.

Wallet activities and card transaction reads expose an ordinary balance-return label for local overflow returns, never internal administrator identity. Platform user card reconciliation deducts these confirmed returns from fee-inclusive estimated consumption. Existing archived-card history remains readable. Cleanup's existing non-allowlisted FK dependency detector blocks deletion of cards referenced by void evidence; the cleanup deletion manifest is not expanded.

## Deployment and validation

Deploy `2026_10_12_180000_add_platform_card_void_batches.php`, matching PHP and admin assets together. The migration retains all financial history and adds no provider calls. It deliberately has no destructive rollback. The consumer wallet event label is also updated; publish matching consumer assets through the normal release process when applicable.

Verify using `card_ui_test` and offline Chrome/WebKit fixtures only. Never execute live customer refunds, cancellations or cleanup as a migration/deployment test.
