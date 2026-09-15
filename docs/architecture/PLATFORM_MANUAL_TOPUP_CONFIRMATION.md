# SaaS manual top-up receipt confirmation

Approved 2026-09-13 after explicit architecture-conflict confirmation. This narrowly
replaces the requirement that all TRC20 credits have chain confirmation. It is not
a generic balance adjustment, fabricated chain proof or permission for Company Admin.

## Authority and lifecycle

Only an ACTIVE Admin with an ACTIVE Platform membership and `wallet_topups.confirm`
may call the exact persisted company/order route. New permission defaults only to
PLATFORM_OWNER and PLATFORM_ADMIN. The Application Action rechecks authorization,
even outside HTTP. POST requires request UUID and explicit receipt acknowledgement;
amount/asset/tenant/user/wallet/status overrides are prohibited. UI shows immutable
order reference and full amount with a warning that this bypasses online checks,
credits immediately, and cannot be undone here. User must acknowledge then submit.
No transaction hash is required. Automatic scanning and the separate exact chain
recheck route remain available, but the normal SaaS button is manual confirmation.

Only existing TRC20_SHARED/USDT PENDING, PROCESSING or UNKNOWN orders are eligible.
Expired, failed, cancelled, refunded and review orders cannot be revived; a PAID
order belongs to existing settlement recovery. Already CREDITED is an idempotent
no-op, never a second credit. Nominal expiry alone does not release a PENDING slot.

One transaction locks Order -> actor/request advisory -> existing credit action's
business advisory -> Ledger event/accounts -> derived rows. It sets immutable
manual_confirmed_at/by/request_id, transitions PAID, invokes CreditWalletTopupAction
and appends PLATFORM_TOPUP_MANUALLY_CONFIRMED audit, atomically. No external call.
The same actor/request UUID cannot confirm a different order; order locking also
serializes different requests and races with scanner settlement. All successful
paths share wallet_topup:{order_uuid}:credit. Rollback leaves no receipt or money.
Provider status/hash/confirmation timestamps are not fabricated or overwritten.

Full fixed order amount, including the identifying decimal, uses the existing
TENANT_TOPUP_CLEARING -> USER_AVAILABLE posting. Existing durable initial-deposit
allocation behavior remains unchanged and runs after commit; confirmation is not
a separate admin deposit adjustment. Later User/Company suspension does not erase
an existing receipt or block settlement of its already-created Order.

## Persistence and late payment safety

Additive migration only: existing Orders retain null manual provenance, unchanged
Ledger and chain evidence. Check constraints require complete manual provenance
only on PAID/CREDITED TRC20/USDT orders, with no fabricated blockchain confirmation.
PostgreSQL and model guards make manual provenance immutable. Manual confirmations
cannot be attached on INSERT or retrofitted to terminal or review orders. Migration
is forward-only; no history deletion or automatic reversal.

Because no transaction identity is proven, manually confirmed exact amounts remain
reserved in both allocator and unique database index, even after CREDITED. This
prevents a late receipt from being assigned to a newly issued same-amount order.
No automatic slot release is introduced; exhaustion remains explicit. Any future
verified-proof/release workflow needs a separate design. Existing chain-matched
manual receipts stay linked to their original order, with no duplicate credit.

Admin DTOs expose manual-confirmation indicator/time (not raw model or request UUID).
Ledger reconciliation remains read-only and validates exact full-amount accounting.
Tests are isolated/offline; implementing this feature does not authorize confirming
any existing order or running historical jobs in a live database.

## Acceptance

2026-09-13: 82 related backend tests passed (404 assertions), covering exact credit,
duplicate requests, cross-order UUID conflicts, company isolation, explicit consent,
permission checks, failed/expired/review blocking, rollback, immutable provenance,
late transfers, permanent manual amount reservations and interaction with automatic
credit. 39 frontend tests, typecheck, lint and build passed. Migration applied only
to `card_mock` on port 8001. No existing order was confirmed by the agent.
