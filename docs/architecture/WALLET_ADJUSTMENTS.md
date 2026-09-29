# Platform wallet adjustments

Approved 2026-09-29: customer-list wallet adjustment permits adding/subtracting available funds. Platform users.read + wallet.read + wallet.adjust are required; Owner/Admin receive the dedicated permission incrementally. Tenant admins remain read-only. No repeat password.

The selected customer is scoped by company/user; only existing active USDT/USDC/ETH/BTC wallets of active accounts/companies may be adjusted. Exact positive decimal amount, direction, reason, UUID and UI confirmation are required. Tenant/user/wallet/account locks serialize changes; insufficient balance rolls back. Identical UUID retries return the immutable receipt; changed intent conflicts.

LedgerWriter posts equal opposite deltas to USER_AVAILABLE and dedicated negative-permitted TENANT_ADJUSTMENT_CLEARING. WALLET_ADJUSTMENT is shown as 后台调整 / Admin adjustment. The append-only history preserves actor, reason, currency, before/after balances and entry reference. Deferred database evidence guards require exactly the authorized two postings. No existing Ledger rows are changed; no deposit qualification, activation, rewards, fees, external transfers or card-provider operations are triggered.

Deployment: php artisan migrate --force and normal cache refresh; publish admin build and public/h5 together. Keep all existing storage/configuration/database data. Tests run only in isolated card_ui_test; no real balances are adjusted to validate this feature.
