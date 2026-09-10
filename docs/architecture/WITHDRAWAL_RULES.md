# Withdrawal Rules

Phase 7 implements one fixed rail: USDT on TRON (TRC20). The User never selects Tenant, User, Wallet, asset, network, fee, or a hold amount. V1 has no withdrawal fee, FX, bank/fiat rail, multi-chain routing, batch payout, private-key custody, automated sending, manual success control, or Security Deposit refund.

## Eligibility and destinations

Creation requires an ACTIVE Tenant, enabled Tenant withdrawal setting, ACTIVE User, APPROVED KYC, ACTIVE USDT Wallet, ACTIVE destination owned by the same Tenant/User, and enough USER_AVAILABLE funds. Web scope always comes from Host -> Tenant Domain -> Tenant Context. Client `tenant_id`, `user_id`, `wallet_id`, `asset`, and `network` are prohibited.

TRC20 destinations are normalized and format-checked before persistence. The full address is AES-256-GCM encrypted with a dedicated stable key; a dedicated keyed HMAC prevents duplicates without plaintext lookup. Ordinary models, User/Admin lists, Inertia props, audit, and logs expose only the stored mask. Destination address identity cannot be edited. Admin reveal requires `withdrawals.review`, the exact Tenant scope, recent password authentication, throttling, and a sanitized audit event that excludes the address.

## Order and Ledger lifecycle

`PENDING -> APPROVED -> VERIFYING -> SUCCEEDED` is the successful path. A User may cancel only PENDING; an authorized Tenant Admin may reject PENDING or APPROVED only before a transaction hash is submitted. APPROVED keeps the full hold. REJECTED/CANCELLED release it exactly once. SUCCEEDED is permanent.

Creation is an idempotent, locked database transaction. `(tenant_id, request_id)` identifies the intent and a canonical hash binds User, destination, amount, USDT, and TRON. The same payload returns the original Order; changed content fails with `IDEMPOTENCY_CONFLICT`. The Order and exact Ledger event commit atomically:

```text
WITHDRAWAL_HOLD:    USER_AVAILABLE -amount, USER_WITHDRAWAL_HOLD +amount
WITHDRAWAL_RELEASE: USER_WITHDRAWAL_HOLD -amount, USER_AVAILABLE +amount
WITHDRAWAL_SETTLE:  USER_WITHDRAWAL_HOLD -amount, TENANT_WITHDRAWAL_CLEARING +amount
```

All events use decimal `NUMERIC(20,8)` values and deterministic `withdrawal:{order_uuid}:{hold|release|settle}` keys through `LedgerWriter`. There is no generic balance/hold/release interface and no Admin financial override.

## Manual sending and verification

An authorized Admin sends the transfer outside this application, then submits its TRON transaction hash. `BlockchainGatewayInterface` verifies a successful USDT TRC20 transfer to the decrypted expected destination for the exact held amount and minimum confirmation count. Gateway I/O occurs between two short database transactions and never inside the Ledger transaction.

Pending confirmation remains VERIFYING with its hold intact. Failed, wrong destination, wrong amount, and wrong token return the Order to APPROVED without settlement so an Admin can send correctly and submit a new hash. Every attempted `(network, tx_hash)` remains uniquely registered; a failed hash cannot be reassigned, and one hash can never settle two Orders. Duplicate verification of the same valid hash is idempotent. Timeout/unavailable is not success or failure and never releases or settles funds.

The Mock gateway is available only in local/testing. Production binds an unavailable adapter until a real read-only blockchain verification adapter is installed. There is no production route to mark success, modify balance, or choose a hold amount.

## Future deposit direction

Future V1 Top-up uses a monitored USDT-TRC20 deposit address: confirmed blockchain transfer -> PAID -> the existing exact `CreditWalletTopupAction` -> CREDITED. Existing checkout Provider settlement remains supported until that separately scoped migration. Withdrawal remains manual send plus independent blockchain verification.
