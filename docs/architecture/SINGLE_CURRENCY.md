# Single currency: USDT / USD at 1:1

2026-09-16 revision: [MULTI_ASSET_CENTER.md](MULTI_ASSET_CENTER.md) supersedes
the single-wallet/currency-choice exclusion for USDC, ETH and BTC accounts and
internal conversion to USDT. The card, guarantee and commission boundaries below
remain unchanged. Historical wallets and ledger identities are never relabelled.

Approved explicitly on 2026-09-13. The product has one financial unit for now.
Wallet, top-up, withdrawal, deposit, commission and company accounting use `USDT`.
PhotonPay card products and provider requests continue to use genuine `USD`.
The existing card issue/load/return boundary maps the principal decimal amount
unchanged at 1:1; no exchange quote, spread, currency selector or FX fee is added.
Existing opening fees and authoritative provider fees are separate from principal.
This is a product settlement convention, not a claim of external token/USD parity.

New companies and demonstration seeds default to USDT. Company creation accepts
only USDT. Company deposit settings cannot select another asset. Do not normalize
provider receipt assets or change Money/Ledger equality: an incoming transfer must
still be the official TRON USDT token, and every Ledger entry uses one exact asset.
Card provider requests still validate USD, not arbitrary currencies.

## Existing unused USD wallets

Migration `2026_09_13_000400` fixes the old demonstration USD configuration only
when *all* affected USD companies have no financial history, orders, payment
events, withdrawal destinations, cards, funding intents or commission awards and
every existing account has zero balance and USD denomination. It preserves company,
user, wallet and Ledger Account IDs, KYC, invitation relationships, deposit amounts,
timestamps and permissions; it changes only denomination metadata and appends a
SYSTEM audit. It never changes balance, Ledger Entry or Posting data.

Run during maintenance. It locks all inspected financial tables with a short lock
timeout and preflights everything before updates. The three denomination-identity
triggers and one ownership FK have transaction-local DDL exceptions only while
tables are locked; they are restored and FK/alignment revalidated before commit.
Any exception rolls back DDL and data together. Normal runtime immutability stays.
Re-running after success is a no-op; down never relabels now-funded accounts.

If a legacy USD company has even zero-net financial history or an unsettled order,
the entire migration fails with a diagnostic, without rewriting or skipping it.
Such a database needs a separately specified append-only funding conversion and
order settlement migration; the empty-wallet migration must never be forced.
No old jobs or provider operations are replayed. Local mock acceptance is not a
real deposit or PhotonPay acceptance test.

Adding a second currency, a market exchange rate or a general conversion endpoint
requires a new design/approval. Historical multi-asset Money/Ledger read semantics
remain intact for immutable records; they are not newly offered product choices.

## Verification (2026-09-13)

Applied only to the running isolated `card_mock` database: two unused companies
normalized, existing wallet ID retained, zero orders/entries/nonzero balances before
and after. Browser `/security-deposit` now shows USDT and an enabled payment button;
no payment instructions, incoming transfers or card operations were created there.
`card_platform` was not migrated or replayed.

The full isolated suite ran 876 tests: 875 passed; the only failure was an old route
allowlist missing the already-approved `wallet/transfers` route. After explicitly
adding that route, 125 financial/migration/card/promotion tests passed (999 assertions),
including that audit test. Frontend 36 tests, typecheck, lint and production build
passed; the existing large-bundle warning remains. This is offline acceptance only.
