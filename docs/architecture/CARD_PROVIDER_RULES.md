# Card Provider Rules

Local development uses `MockCardProvider`; production uses `CardProviderInterface -> PhotonPayCardProvider`. Both implement the same Cardholder/product/issue contract. Unsupported future operations remain explicit unavailable methods; their presence on the contract is not authorization to expose reload, reveal, freeze, cancel, or transaction features. Provider JSON is mapped into internal DTOs and never leaks into business code.

Adapters own HTTP, authentication, and response mapping only. They never update Wallet, Ledger, Security Deposit, or Commission. No provider HTTP call occurs inside a long database transaction. Persist local intent/hold/operation, commit, call externally, then persist result and settle/release in a fresh transaction.

Timeout or an indeterminate response is UNKNOWN, never FAILED. UNKNOWN resolves through queryOperation, trusted webhooks, and reconciliation; do not blindly retry creation or refund.

Full PAN and CVV are never persisted in ordinary tables or logs. Phase 10 stores only provider identifiers, masked PAN, last4, optional expiry/status, and an optional Provider-balance cache. Reveal is not implemented. Mock data is explicitly TEST/MOCK and is not a plausible production credential.

Phase 10 Cardholder calls reuse approved private KYC data without changing KYC state. Cardholder file uploads use the exact `issuing_cardholder_identity_certificate` business key. `openCard` is fixed to regular/recharge virtual USD, includes the linked READY Cardholder, validates the opaque Provider Product through `getCardBin`, uses the Order UUID as the stable Provider request ID, and maps initial balance to `arrivalAmount`. See `PHOTONPAY_INTEGRATION_RULES.md`.

Provider secrets use a secret manager or encrypted secret storage. Admin UI masks existing values and allows replace/test/disable, not reveal. Future tables are `card_provider_connections`, `provider_card_products`, `card_provider_operations`, and `card_provider_events`.

A local card pool is permitted only as disposable Mock/development infrastructure. Production Card architecture does not contain `card_inventory` or `card_inventory_import_batches`, and local inventory must never become a fallback issuance path.

Card Product uses one Domain for PLATFORM- and TENANT-owned products. Platform products can be global or selected-tenant; a tenant can use one, copy it into an independent tenant product, or create its own. `card_products` identity/capability is separate from `tenant_card_product_configs` selling name, enabled state, prices, limits, and sort order. After a real card exists, Provider, Provider Product, Currency, Brand, and Card Type are immutable; create a new product to change them. Price changes apply only to future orders, which keep pricing snapshots. Used products are archived, not deleted. Platform risk suspension blocks new business without altering cards, balances, or history.
