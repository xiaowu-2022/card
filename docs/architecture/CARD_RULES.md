# Card Rules

`provider_cardholders` links one Tenant User to one Provider Cardholder and stores safe lifecycle fields only. Its ownership (`tenant_id`, `user_id`, `provider`) is immutable. Internal READY means provider readiness for card issue, not KYC approval, Wallet readiness, or account lifecycle status.

`card_issue_orders` is the idempotent business aggregate. The browser supplies only a UUID request ID, Card Product ID, and decimal-string initial amount. The server derives Tenant, User, Wallet, Cardholder, Provider, USD currency, opaque Provider Product reference, opening fee, and provider request ID. Pricing and product identity are immutable snapshots. The same request and facts return the original order; changed facts conflict.

New issue eligibility requires active Tenant/User/Wallet, approved KYC, a satisfied USDT Security Deposit, active platform product plus Tenant config, a READY Cardholder, an initial amount at or above the Provider minimum, enough available balance for fee plus funding, and available card capacity below the configured limit. Every non-failed Issue Order reserves one capacity slot so PROCESSING/UNKNOWN operations cannot later exceed the limit. Checks and holds are authoritative backend work under the existing global lock order.

PROCESSING and UNKNOWN keep both holds. SUCCEEDED requires one `user_cards` row and both settlement paths; FAILED requires both release paths. A deferred PostgreSQL constraint validates these terminal combinations. Repeated HTTP, query, or result processing cannot create duplicate Orders, Cards, or Ledger events.

`user_cards` contains Provider identifiers, USD currency, safe masked PAN/last4, optional expiry, safe Provider status, and optional timestamped Provider balance. It never contains full PAN, CVV, identity documents, Provider payloads, or a local authoritative Card balance. Card identity and Card Product reference are immutable. A claimed success with a mismatched currency/type or, when returned, an initial Provider balance different from the requested 1:1 amount remains UNKNOWN with both holds intact.

Phase 10 supports initial funding through `openCard` only. It does not support an existing-card reload, freeze, unfreeze, cancel, reveal, Card transaction history, card amount return, Security Deposit refund, Provider fee calculation, local FX, or manual operational override.
