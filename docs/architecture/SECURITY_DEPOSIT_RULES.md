# Security Deposit Rules

2026-09-11 authorized extension: [Promotion and guarantee lifecycle](PROMOTION_REQUIREMENTS.md)
adds first-time post-credit automatic funding and a separate user-requested refund
after authoritative card closure/zero checks. Historical explicit-only/no-refund
scope below is superseded only by that contract. A real positive funding event
atomically earns company-funded differential commission; refund/cancellation does not.

`USER_SECURITY_DEPOSIT` in the immutable Ledger is the only Security Deposit balance source of truth. There is no separate balance or funding-order table.

## Consumer activation entry (2026-09-13)

The activation link goes directly to `/security-deposit`. Its GET preview is read-only
and is available to an eligible KYC-approved user before a Wallet exists. Consumers
do not need to separately activate an empty Wallet. An existing sufficiently funded
Wallet retains the explicit exact-remaining Deposit confirmation.
The consumer dashboard presents both a missing Wallet and an unmet Deposit requirement
as "Pending activation" with the same Deposit link. This is derived presentation, not
a new persisted User/Wallet status or permission to suspend/delete/reset financial state.

When balance is insufficient, the page offers an editable TRC20 top-up amount,
defaulting to the server's current remaining Deposit requirement (rounded upward to
two decimal places for the top-up input only). Users can increase this amount, but
the dedicated `/security-deposit/top-ups` POST rechecks the current server minimum.
It composes the existing top-up creation flow: allocation advisory lock, Tenant and
User locks, validation, optional zero-Wallet provisioning, then order creation in
one transaction. Failed creation rolls back provisioning. GET never creates a Wallet,
and creating payment instructions never funds a Deposit or generates commission.

The full exactly verified payment, including its identification suffix, credits the
Wallet through the existing settlement event. Existing first-time automatic allocation
then transfers only the exact server-calculated remaining Deposit; surplus remains
available. Re-funding after a refund still requires user confirmation. No new balance,
financial event type, client-selected Deposit amount, FX or asset migration is added.
The payment-return `deposit` flag is presentation-only navigation, never funding authority.
Missing TRC20 configuration or a non-USDT company/Wallet remains unavailable.

Acceptance evidence: `DepositActivationEntryTest` covers a read-only pre-Wallet
preview, exact/upward-rounded company minimum, rejected smaller amounts without
provisioning, idempotent replay after settings change, company/user gates and
ownership, and a 150.01 USDT verified payment allocating 100 USDT once while leaving
50.01 USDT available. Related Deposit/TRC20/Wallet/Promotion/User UI regression:
125 tests, 661 assertions (isolated `card_ui_test`, 2026-09-13). This is not live
chain acceptance. The subsequently approved [single-currency rule](SINGLE_CURRENCY.md)
adds a guarded migration of unused USD wallets to canonical USDT. It leaves
history-bearing wallets unchanged and retains exact money/provider checks.

Funding is a synchronous internal transfer from `USER_AVAILABLE` to `USER_SECURITY_DEPOSIT`. The server recalculates `max(required - current, 0)` while holding the business lock and transfers exactly that amount through `LedgerWriter`. V1 has no partial funding and no FX. A client sends only a request UUID and an `expected_remaining` confirmation snapshot; neither is authority to select an amount, asset, Wallet, User, or Tenant. If the current remaining amount differs, the action fails without moving money.

The event type is `SECURITY_DEPOSIT_FUND` and the Tenant-scoped event key is `security_deposit:{wallet_uuid}:{request_uuid}:fund`. Retrying a successful request returns its original sealed Ledger Entry. Different requests for the same Wallet are serialized by a transaction-scoped business advisory lock, then LedgerWriter applies its existing event and Account locks.

Eligibility requires an ACTIVE Tenant, User, and Wallet, APPROVED KYC, matching Tenant/default/deposit/Wallet/Account assets, a positive remaining requirement, and enough Available balance. Reducing the requirement never moves or automatically refunds excess deposit. Increasing it creates a new remaining amount that the User may fund explicitly.

Tenant and Platform administrators have read-only visibility and cannot fund, adjust, release, or refund a deposit. The authorized user refund action requires Card cancellation, absence of pending Provider operations, and fresh Provider zero-balance checks. Principal remains in USER_SECURITY_DEPOSIT during checking; no second balance table or manual release is introduced.
