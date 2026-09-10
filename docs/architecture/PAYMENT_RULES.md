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

## Phase 5.1 integrity locks

`CREDITED` is an immutable financial fact: paid time, credited time, and the unique Ledger link are permanent. A verified refund, chargeback, reversal, or dispute after credit leaves the Order and Ledger unchanged and marks only the immutable Provider Event `REQUIRES_REVIEW`. Before credit, a full refund may transition `PAID -> REFUNDED` only while both credit time and Ledger link are null. Refund and credit lock the same Order, so the only race outcomes are REFUNDED without an Entry or CREDITED with exactly one Entry plus a review Event.

Provider and Order transitions use the shared Payment state policy. Pending/processing/unknown evidence never downgrades SUCCEEDED, opposing terminal evidence never uses last-write-wins, and a verified success racing local expiry takes precedence so external money is not lost. Provider request and non-null transaction references are unique within Provider scope. Resolution checks both independently, fails closed on ambiguity, and requires both to identify the same transaction when present.

Signature verification uses exact received bytes before normalized facts are trusted. The immutable raw-body SHA-256 digest is replay/conflict evidence, not authentication. Webhook body and normalized field lengths are bounded. Overprecision, scientific notation, missing amount/asset, unknown asset, or mismatched references cannot produce credit.

Adapters classify a definitive Provider rejection as FAILED and ambiguous transport failures—including connection loss after sending—as UNKNOWN. Application code never converts an arbitrary exception or timeout into definitive failure.

Internal request id and Provider request id are separate idempotency layers even when derived from stable UUIDs. A committed uninitiated transaction is recoverable: initiation claims and commits a short lease before external I/O, records `initiation_attempted_at`, and always reuses the original Provider request id. Every adapter must make repeated initiation with that id resolve the same external resource, covering both pre-call crashes and responses lost after acceptance.

Recovery scans bounded batches, includes stale uninitiated transactions, and is guarded by scheduler overlap protection plus a PostgreSQL advisory lock. It holds no database lock across Provider I/O. Jobs carry explicit Tenant/resource ids, bind the Tenant only for execution, and clear `TenantContext` in `finally`.

`payments:reconcile` is read-only and performs no Provider I/O. Every CREDITED Order must link its same-Tenant/same-asset sealed `WALLET_TOPUP_CREDIT` Entry with deterministic key and Order reference. The Entry must contain exactly `TENANT_TOPUP_CLEARING -amount` and the Order Wallet's `USER_AVAILABLE +amount`; wrong, duplicate, unlinked, and orphan top-up Entries fail reconciliation without repair.

## V1 crypto rail direction

Withdrawal and Payment remain separate boundaries. Phase 7 withdrawal is a manual external USDT-TRC20 send followed by exact read-only blockchain verification; its gateway cannot mutate Ledger. A future USDT-TRC20 deposit flow will monitor a server-assigned address, persist confirmed transfer truth as PAID, and then reuse the existing `CreditWalletTopupAction` for CREDITED. Browser claims never establish a deposit. V1 has no bank/fiat rail, FX, or multi-chain routing.
