# Card Product Rules

Phase 9 defines the minimum catalog for the future PhotonPay card flow. V1 supports only the PhotonPay Mille Card regular-card family: shared cards are deferred. Platform owns each provider-backed product and its opaque CardBin/provider reference, USD card currency, REGULAR type, minimum initial load, minimum reload, and lifecycle status. Business code must not infer behavior from the CardBin.

Tenant does not create arbitrary provider products. `tenant_card_product_configs` only controls the customer display name, future opening fee in USDT, maximum cards per user, availability, and sort order for the resolved Tenant. Tenant code cannot change provider identity, currency, type, or provider minimums, and must never read or mutate another Tenant configuration.

The Demo business conversion is exactly `1 USDT = 1 USD` with no FX engine. Opening fee is the only platform charge and configuration moves no money. Minimum initial load and reload are provider product rules, both `20.00000000` in the Demo seed. There is no platform card-load fee, local card transaction-fee engine, pricing versioning, or per-user override.

Phase 10 consumes this catalog without widening it. The initial issue amount must be at least the snapshotted Provider minimum; `arrivalAmount` is the requested initial USD Card balance under the locked Demo `1 USDT = 1 USD` rule. Wallet debits are Ledger-authoritative. PhotonPay card balance is Provider-authoritative; the local value is only a timestamped cache. Once a real card references a product, provider reference, currency, and card type cannot be changed silently; changing identity requires a new product. Existing-card load fees, Tenant-created Provider products, local FX, and per-user overrides remain deferred.
