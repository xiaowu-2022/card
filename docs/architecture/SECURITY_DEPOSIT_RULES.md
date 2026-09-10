# Security Deposit Rules

`USER_SECURITY_DEPOSIT` in the immutable Ledger is the only Security Deposit balance source of truth. There is no separate balance or funding-order table.

Funding is a synchronous internal transfer from `USER_AVAILABLE` to `USER_SECURITY_DEPOSIT`. The server recalculates `max(required - current, 0)` while holding the business lock and transfers exactly that amount through `LedgerWriter`. V1 has no partial funding and no FX. A client sends only a request UUID and an `expected_remaining` confirmation snapshot; neither is authority to select an amount, asset, Wallet, User, or Tenant. If the current remaining amount differs, the action fails without moving money.

The event type is `SECURITY_DEPOSIT_FUND` and the Tenant-scoped event key is `security_deposit:{wallet_uuid}:{request_uuid}:fund`. Retrying a successful request returns its original sealed Ledger Entry. Different requests for the same Wallet are serialized by a transaction-scoped business advisory lock, then LedgerWriter applies its existing event and Account locks.

Eligibility requires an ACTIVE Tenant, User, and Wallet, APPROVED KYC, matching Tenant/default/deposit/Wallet/Account assets, a positive remaining requirement, and enough Available balance. Reducing the requirement never moves or automatically refunds excess deposit. Increasing it creates a new remaining amount that the User may fund explicitly.

Tenant and Platform administrators have read-only visibility and cannot fund, adjust, release, or refund a deposit. Refund is deferred until Card cancellation, pending Provider operation, and Provider card-balance checks exist.
