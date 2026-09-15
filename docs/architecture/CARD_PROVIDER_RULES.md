# Card Provider Rules

2026-09-11 authorized extension: [Card management](CARD_MANAGEMENT.md) supersedes historical Phase 10 exclusions only for the existing PhotonPay regular virtual USD product. It adds scoped management orders, a verified notification inbox, and safe transaction read models; all Ledger, tenant and sensitive-data safety rules remain in force.

The additive paginated transaction-read contract and lossless response mapping are
specified in [CARD_TRANSACTION_READS.md](CARD_TRANSACTION_READS.md). The legacy
array contract is unchanged; the read method itself never changes money. Management
capabilities use the separate approved CARD_MANAGEMENT.md contract.

Per the user-confirmed workflow revision, Cardholder READY means a successful add operation with the returned Provider ID, not a separate review approval. The same application then continues to explicit financial confirmation and issue with that exact ID. Never infer success from a timeout, missing ID or Provider error. See `PER_CARD_MATERIALS.md`.

Real Card runtimes use `CardProviderInterface -> PhotonPayCardProvider`, never a fallback. The user approved an isolated local debug exception on 2026-09-13: `APP_ENV=local`, explicit Mock driver, and a fixed isolated PostgreSQL database are all required; see [LOCAL_CARD_SIMULATION.md](LOCAL_CARD_SIMULATION.md). Staging/production and the ordinary application database cannot select Mock. The local simulator implements the full approved card-management contract; automated legacy tests retain their existing fake. Provider JSON is mapped into internal DTOs and never leaks into business code.

Adapters own HTTP, authentication, and response mapping only. They never update Wallet, Ledger, Security Deposit, or Commission. No provider HTTP call occurs inside a long database transaction. Persist local intent/hold/operation, commit, call externally, then persist result and settle/release in a fresh transaction.

Timeout or an indeterminate response is UNKNOWN, never FAILED. UNKNOWN resolves through queryOperation, trusted webhooks, and reconciliation; do not blindly retry creation or refund.

Full PAN and CVV are never persisted in ordinary tables or logs. Normal storage contains only provider identifiers, masked PAN, last4, optional expiry/status, and an optional Provider-balance cache. The approved sensitive reveal flow is short-lived, no-store and recent-password protected. Mock data is explicitly TEST/MOCK and is not a plausible production credential.

The approved per-card revision submits independent, encrypted private holder materials for each card without reading or changing account KYC identity data; different holders are allowed. See `PER_CARD_MATERIALS.md`. Cardholder file uploads use the exact `issuing_cardholder_identity_certificate` business key. `openCard` is fixed to regular/recharge virtual USD, includes the linked READY Cardholder, validates the opaque Provider Product through `getCardBin`, uses the Order UUID as the stable Provider request ID, and maps initial balance to `arrivalAmount`. See `PHOTONPAY_INTEGRATION_RULES.md`.

Provider secrets use a secret manager or encrypted secret storage. Admin UI masks existing values and allows replace/test/disable, not reveal. Future tables are `card_provider_connections`, `provider_card_products`, `card_provider_operations`, and `card_provider_events`.

A mock card pool is permitted only as disposable test infrastructure. The isolated local simulator generates new unmistakable TEST identities and never imports an inventory. Runtime Card architecture does not contain `card_inventory` or `card_inventory_import_batches`, and local inventory must never become a fallback issuance path.

Card Product provider identity is platform-owned under the effective Phase 9 contract. Company Admin may only configure display name, opening fee, enabled status, maximum-card count and sort order through ConfigureTenantCardProductAction; it has no product creation route. It cannot copy/create Provider products or change their type/currency/reference. Used product identity is immutable; new provider identity requires a new platform product. Price changes apply to future orders and preserve historical snapshots. Platform suspension blocks new business without rewriting cards or accounting.
