# Same-company wallet transfers (approved 2026-09-11)

The user explicitly authorizes internal balance transfers by public account ID.
Only the resolved company, authenticated sender, a distinct recipient in that
company, and existing ACTIVE verified wallets in its default asset are allowed.
The recipient is resolved by tenant_id + immutable 12-digit account_id; unknown,
foreign-company and unavailable recipients do not reveal personal/contact data.
The client supplies request UUID, recipient account ID, a positive plain decimal
string of at most two places, current password and explicit confirmation. It
cannot choose asset, fee, sender, wallet or tenant. No fee/FX is introduced.

This is an atomic internal operation, not an external-provider settlement. The
general external intent/hold/call/settle pattern does not require an artificial
hold or UNKNOWN provider state here. One PostgreSQL transaction locks Tenant,
both Users sorted by UUID, both Wallets sorted by UUID, then invokes LedgerWriter
(event lock, accounts sorted by UUID). Same-company Tenant serialization protects
intent lookup; every committed (tenant, sender, request UUID) is unique and its
recipient/amount/asset immutable. Identical retries return the original receipt;
different details conflict. Rollback leaves neither receipt nor money movement.

`wallet_transfers` is an immutable completed receipt. Composite ownership FKs
bind both wallets/users/asset and the sealed entry. Deferred evidence triggers
validate in both directions: every WALLET_TRANSFER entry has exactly one receipt
and exactly two USER_AVAILABLE postings, -amount to sender and +amount to the
recipient. Event key is wallet_transfer:{receipt UUID}; reference type and ID must
match. No changes to LedgerWriter, balance setters or editable transfer status.

Guarantee principal, holds and commission accounts are never touched. Receiving
a transfer is not external top-up income or a commission trigger, and does not
automatically fund a deposit. Company cash-flow/revenue reports exclude internal
transfers; sender/recipient wallet activity shows each direction separately.
This stage does not authorize transfer reversals, cancellation, admin adjustments,
cross-company transfers, currency conversion or automatic wallet activation.

HTTP is active end-user-only with CSRF, password check and throttling. Receipts
are scoped by tenant + receipt ID + sender-or-recipient ownership. Ordinary output
is an allowlisted DTO without peer contact/KYC/balance details. UI reviews target
ID and exact amount before confirmation. Never run live financial writes for QA.
