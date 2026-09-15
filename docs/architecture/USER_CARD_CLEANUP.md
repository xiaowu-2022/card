# Confirmed account card cleanup

On 2026-09-14 the user explicitly requested cancellation of all ten current cards
for Tenant A account 202609131303, confirmed processing balances before removing
them from the user list. This supersedes the earlier sandbox instruction to cancel
only one card for these specific cards. Scope is the explicit ten-ID snapshot;
new cards and other users remain unaffected.

The local-only command uses the existing provider router and ManageCardAction.
Each card has a durable USER audit intent recording actor/time, original balance
and stable cancellation request UUID before provider writes. UNKNOWN reuses that
order for reconciliation, never another cancellation. Balance refunds require
exact provider cancellation-return evidence and existing LedgerWriter settlement.
No manual credit, fabricated success or edited financial history is allowed.

user_cards.archived_at hides only explicitly cleared cards from the consumer card
list and its aggregate transaction widget. The archive action requires a scoped
cleanup intent, recently confirmed cancelled/zero provider state, no unresolved
management operations and settled cancellation-return principal covering the
snapshotted balance. It writes an append-only actor/time audit. No global model
scope or deletion is introduced: SaaS cards, historical transactions, holders,
orders, refunds and Ledger relations remain readable. Existing card limits and
financial gates are unchanged. Provider notifications still resolve archived cards.

No public deletion endpoint, scheduler replay or automatic cancellation is added.

## Completed sandbox acceptance

The ten snapshot cards are confirmed cancelled with zero provider balance and
archived from the consumer list. Nine new cancellation-return orders settled
255.00000000 USD principal to 255.00000000 USDT, with zero fees. Wallet available
changed from 9766.84000000 to 10021.84000000 USDT. The previously cancelled USMAB
card was archived without another cancellation/refund. All 24 issue orders and
ten card identities remain; ten cleanup intents and ten archive audits retain
the user actor and timestamps. Unconfirmed cancellation responses were reconciled
using the original management orders before any archival.

Ledger reconciliation passed without mismatches. Four targeted cancellation,
refund and archive tests passed with 78 assertions on card_ui_test. The live
consumer browser confirmed “暂无卡片” after refreshing. No new card was issued.
