# Read-only Card transaction extension

2026-09-11 authorized extension: [Card management](CARD_MANAGEMENT.md) supersedes historical Phase 10 exclusions only for the existing PhotonPay regular virtual USD product. It adds scoped management orders, a verified notification inbox, and safe transaction read models; all Ledger, tenant and sensitive-data safety rules remain in force.

This document governs the **read-only** all-owned-card history below the cards.
It does not perform sensitive reveal or management mutations; those have separate
authorization and flows in CARD_MANAGEMENT.md. It does not pause those flows.
Holder identity replacement remains excluded. Security Deposit refunds are governed
separately by PROMOTION_REQUIREMENTS.md, never by displaying a provider transaction.

## Provider contract

`CardProviderInterface.getTransactionPage(cardId, page, pageSize)` is an additive
paginated contract, returning `ProviderTransactionPageDTO` and safe
`ProviderCardTransactionDTO` rows. It does not alter the legacy array-returning
`getTransactions` contract or pretend a single page is all history. All adapters
implement the new method. Runtime remains PhotonPay-only; Mock is test-only.

Source verified: PhotonPay published OpenAPI 2026-08-06,
https://api-doc.photonpay.com/data/2026-08-06_zh.json,
`GET /vcc/openApi/v4/pagingVccTradeOrder`, `vccTradeOrderResp`.
Requests always include the server-mapped cardId plus recharge/virtual_card filters,
configured member/matrix scope when present, and explicit page/pageSize. Never query
the merchant-wide transaction feed without a card filter. Response rows must match
the exact card, USD card currency, regular type and virtual form factor. Pagination
metadata is checked; missing or inconsistent data fails the page, not an empty success.

JSON syntax is validated before numeric tokens outside strings are losslessly
decoded as decimal strings. Amounts use BigDecimal, NUMERIC(20,8) bounds and no
rounding. Show transactionAmount in transactionCurrency, not a fabricated signed
balance delta, arrival amount, net amount or fee calculation. Provider types/states
map to an allowlisted presentation vocabulary; raw errors never reach the user.
Allowlisted output excludes PAN/CVV, provider references, account IDs, full response
payloads and request IDs. Merchant text is length-bounded and escaped by React.

The documented transaction timestamps lack a timezone. Preserve that wall time
internally as YYYY-MM-DD HH:mm:ss (txnDate, falling back to createdAt); never invent
UTC. The 2026-09-15 user-approved system-record projection supersedes displaying
that field: client DTOs expose only `displayAt` with an offset and `timeKind`.
An exact tenant/user/card/transaction match to a SUCCEEDED management order with
its sealed settlement entry uses that entry's posted_at (`completed`). Otherwise
use card_transactions.created_at (`recorded`), not an inferred purchase time.
The consumer UI displays the date/time alone, without a Completion time / Recorded
time prefix (user requested 2026-09-16), and formats the company timezone. The DTO
retains timeKind; administrator time-source labels are unchanged.

## Ownership and user surface

`GET /cards/{local-card-uuid}/transactions?page=N` resolves Host -> TenantContext
and queries persisted records by tenant_id + authenticated user_id + card id. It
makes no external call and writes nothing, even when integration credentials are
unavailable. Local pagination uses 20 rows plus a lookahead, ordered by display time
and row id. All output remains explicit, private/no-store and reference-free.

The user approved a separate `POST /cards/{card}/transactions/sync` on 2026-09-15.
It uses the same ownership, user/Tenant lifecycle and 120/minute scoped limiter plus
normal CSRF protection. It reads one external page, validates it through the existing
adapter, then invokes RecordCardTransactionsAction in a short local transaction.
Its response reads back exactly that page's persisted rows; hasMore describes the
external page, not the size/completeness of the local history. GET hasMore describes
only locally stored rows. No client tenant/user/provider selectors are accepted.

First insertion owns created_at permanently. Unique card/transaction identity,
ordered row locks and conflict-safe insert prevent duplicate first observations.
Updates cannot overwrite records changed since their request began. Timestamp
precision is increased to microseconds by a new migration; historical values are
not reinterpreted. Verified notification refresh uses the same recorder. No
transaction fields are inferred from amount, proximity of time or merchant text.

The UI first reads stored data and then syncs requested pages, at most three cards
at once. Local and external page counters are separate; retry preserves the failed
source's page. Previously loaded records remain visible during failures, with a
neutral update/retry message. Only loaded rows are merged and deduplicated by their
card-scoped hashed identity. No full-history scan, timer polling or false claim of
complete global history is introduced. Old transactions first collected now are
assigned their immutable first-recorded time, never backdated. Consumer copy does not expose issuing
or integration infrastructure; critical fee/cancellation/refund consequences remain.

## Side effects and non-goals

The approved sync writes only the existing safe transaction read model. No new
tables, persisted Card states, jobs, background polling, provider writes, local
balance updates, Ledger entries, Wallet credits or refunds are introduced. A transaction
named refund/transfer out is displayed only; it never changes internal accounting.
Legacy Mock/Test/Demo card references cannot be sent to PhotonPay and are shown as
unavailable. Missing production credentials remain a real integration blocker.

2026-09-14 live sandbox verification: trade rows may omit `cardCurrency`. The adapter
then queries the exact card's detail and supplies its verified denomination to the
normalizer. This is not a default currency or client assertion. Existing row card ID,
recharge/virtual form, pagination and exact decimal checks remain, and a conflicting
explicit row currency is rejected. The same evidence rule applies to management
recovery and cancellation-return verification.

## SaaS card transaction visibility (2026-09-15)

The user authorized viewing card transactions from SaaS card operations. Each row
on `/platform/cards` has View transactions, opening a paginated dialog without
resetting company/search filters or the cards tab. It shows type, merchant, exact
transaction amount/currency, state and completion/recorded time in the persisted
company timezone. Cancelled and historical cards remain readable. Empty results
mean no saved records, not proof that no external transactions exist.

`GET /platform/tenants/{tenant}/cards/{card}/transactions?page=N` requires an active
Platform administrator and membership with cards.read. Both route IDs are UUIDs;
the query resolves the card with tenant_id + card ID, derives its user ID from that
row and reuses UserCardTransactionsQuery. Client tenant/user/provider/page-size
selectors are prohibited by CardTransactionsRequest. The response is the existing
safe 20-row transaction page plus the company's `timezone`, private/no-store.
The DTO retains hashed row identifiers and last four digits only; no provider
references, PAN/CVV, identity materials or unrestricted models are exposed.

The dialog uses GET only, cancels obsolete requests on close/card/page changes,
validates returned card/page IDs and offers explicit error retry. No provider sync,
background scan, balance refresh, financial operation, new permission or migration
is introduced. Existing user sync and notification recording retain their contracts.
