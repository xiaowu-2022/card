# Card Rules

2026-09-11 authorized extension: [Card management](CARD_MANAGEMENT.md) supersedes historical Phase 10 exclusions only for the existing PhotonPay regular virtual USD product. It adds scoped management orders, a verified notification inbox, and safe transaction read models; all Ledger, tenant and sensitive-data safety rules remain in force.

The explicit post-Phase-10 read-only history extension is defined in
[CARD_TRANSACTION_READS.md](CARD_TRANSACTION_READS.md). It permits paginated real
transaction reads only within that query; separately approved management mutations
and sensitive reveal use CARD_MANAGEMENT.md, not the read endpoint.

`provider_cardholders` stores one independent material application per requested card; a User may submit different holders. Account-level KYC material is not reused. Tenant/User/provider/product/request identity is immutable; sensitive materials are encrypted and private. See `PER_CARD_MATERIALS.md`. Internal READY means provider readiness for card issue, not KYC approval, Wallet readiness, or account lifecycle status.

`card_issue_orders` is the idempotent business aggregate. The browser supplies a UUID request ID, Card Product ID, local Cardholder application ID, and decimal-string initial amount. That application must belong to the resolved Tenant/User/product, be READY and not already used by another order. The server derives Tenant, User, Wallet, Cardholder, Provider, USD currency, opaque Provider Product reference, opening fee, and provider request ID. Pricing and product identity are immutable snapshots. The same request and facts return the original order; changed facts conflict.

New issue eligibility requires active Tenant/User/Wallet, approved KYC, a satisfied USDT Security Deposit, active platform product plus Tenant config, a READY Cardholder, an initial amount at or above the Provider minimum, enough available balance for fee plus funding, and available card capacity below the configured limit. Every non-failed Issue Order reserves one capacity slot so PROCESSING/UNKNOWN operations cannot later exceed the limit. Checks and holds are authoritative backend work under the existing global lock order.

PROCESSING and UNKNOWN keep both holds. SUCCEEDED requires one `user_cards` row and both settlement paths; FAILED requires both release paths. A deferred PostgreSQL constraint validates these terminal combinations. Repeated HTTP, query, or result processing cannot create duplicate Orders, Cards, or Ledger events.

`user_cards` contains Provider identifiers, USD currency, safe masked PAN/last4, optional expiry, safe Provider status, and optional timestamped Provider balance. It never contains full PAN, CVV, identity documents, Provider payloads, or a local authoritative Card balance. Card identity and Card Product reference are immutable. A claimed success with a mismatched currency/type or, when returned, an initial Provider balance different from the requested 1:1 amount remains UNKNOWN with both holds intact.

Initial funding uses `openCard`. Existing-card recharge, freeze/unfreeze, cancellation,
sensitive reveal, transaction history and card amount return use the approved
CARD_MANAGEMENT.md contracts. Security Deposit refund is a separate Promotion/guarantee
flow. Local FX, a local Provider-fee engine, holder identity replacement and manual
financial overrides remain excluded. See CURRENT_CAPABILITIES.md for the effective scope.
