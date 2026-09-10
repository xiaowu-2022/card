# Payment Rules

Payment Provider and Card Provider are separate domains and contracts. The former accepts external wallet funding; the latter will manage virtual cards. Neither adapter may mutate Wallet or Ledger.

## Truth and state

Browser redirects, return URLs, query strings, JavaScript, and client-submitted status are never payment truth. `PAID` means a verified Provider webhook or trusted Provider query confirmed receipt of the exact snapshotted amount and asset. `CREDITED` is a later internal state that exists only after `LedgerWriter` commits `TENANT_TOPUP_CLEARING -amount` and `USER_AVAILABLE +amount`. A Provider timeout is `UNKNOWN`, never `FAILED`.

Orders permit the core transitions `PENDING -> PROCESSING|PAID|FAILED|CANCELLED|EXPIRED`, `PROCESSING -> PAID|FAILED|CANCELLED|EXPIRED`, and `PAID -> CREDITED`. Stale pending events cannot reverse PAID, and CREDITED is terminal in Phase 5. Conflicting terminal events require trusted Provider query/reconciliation; last webhook does not win.

## Idempotency and isolation

Creation requires a client UUID, unique as `(tenant_id, request_id)`, with a canonical request hash over operation, Tenant, User, Wallet, amount, and asset. The same request and payload returns the existing Order; changed content is `IDEMPOTENCY_CONFLICT`. The stable Order UUID is the Provider request id. UNKNOWN reconciliation never generates a new semantic request.

Webhook signatures are required before normalized fields are trusted. Webhook Tenant scope comes only from `provider_request_id` or `provider_transaction_id` mapped to a persisted Provider Transaction. Body, query, Host, header, and client `tenant_id` are ignored. Unknown mappings create no Tenant or Order. Raw payloads, signatures, checkout URLs, and tokens are not logged, audited, queued, or persisted; only a SHA-256 digest and bounded normalized fields are retained.

Provider Event identity is unique by Provider and event key. Duplicate delivery is idempotent. The same event key with a different digest becomes `REQUIRES_REVIEW`. Exact amount and asset equality are mandatory; partial payment, overpayment, underpayment, and FX are not implemented.

## Transactions and recovery

Initiation uses transaction one to persist Order and Provider Transaction, commits, performs Provider I/O, then uses transaction two to persist the normalized result. Provider I/O never occurs in a Ledger or long database transaction.

A verified event is persisted before asynchronous processing. Event processing locks its persisted state and mapped transaction/order, then commits PAID before settlement dispatch. Settlement locks the Order, takes its business advisory lock, and calls `LedgerWriter` inside the same outer transaction. `wallet_topup:{order_uuid}:credit` is deterministic, so retries produce one financial effect.

Queue delivery is at-least-once. Financial effect is exactly-once through database row/advisory locking plus Ledger idempotency. `payments:recover` scans bounded batches of pending/failed Provider Events, PAID Orders, and stale UNKNOWN Provider Transactions. This closes commit-to-dispatch gaps without a general Outbox in Phase 5. Jobs carry only explicit trusted Tenant and resource UUIDs.

Starting new funding requires ACTIVE Tenant, ACTIVE User, APPROVED KYC, ACTIVE Wallet, enabled Tenant setting, and an available Payment Provider. Completing already-accepted settlement does not re-check User or Tenant ACTIVE state: externally paid money must still be credited after later suspension. An unwritable/missing settlement Account remains operator-visible PAID for recovery; it is never silently refunded or falsely credited.

Refund or chargeback received after CREDITED is persisted and marked `REQUIRES_REVIEW`; Phase 5 never performs an arbitrary debit. No manual mark-paid, mark-credited, balance adjustment, or force-success production endpoint exists.
