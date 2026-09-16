# Multi-asset center (approved 2026-09-16)

## Contract
Four separately denominated wallets: USDT, USDC, ETH, BTC. USDT/TRON retains its
existing endpoints, orders and reservations. Additional rails are USDT/Ethereum,
USDC/Ethereum, native ETH/Ethereum and native BTC/Bitcoin. New configurations are
OFF until explicitly configured by active Platform tenant.manage. Credentials are
replace-only encrypted values. No signing/private key custody or exchange execution
API is added. Company Admin configuration stays read-only.

USDC/ETH/BTC -> USDT is an internal, user-confirmed exchange of available balances.
A CoinGecko USD cross-rate snapshot (including USDT/USD) expires after 120 seconds;
quotes expire after 30 seconds. Fees are percentages deducted in USDT, rounded up
to eight decimals; gross output rounds down. All amounts and rates use decimal
strings. Quotes snapshot the fee; confirmation rechecks availability and current
company enablement/limits. Exact completed request replay is preserved.
Paired single-asset LedgerWriter events settle atomically, through explicitly typed
TENANT_EXCHANGE_CLEARING accounts. Each independently sums to zero. No partial
exchange, artificial provider success or generic balance adjustment is exposed.

## Precision and history
New migration expands generic ledger amounts to NUMERIC(38,18) without changing
numeric values, identity or historical event hashes. ETH has 18 fractional digits,
USDC 6, BTC/USDT 8, legacy currencies keep 8. The existing 12 integer digit limit
is retained. Database validation enforces asset scale and Ledger cache truth.
Security deposit and commission stay USDT; card operations keep existing USD/USDT
contracts. Legacy consumers explicitly select the company's default wallet rather
than an arbitrary first wallet. No historical data or jobs are replayed.

## Lifecycle
New shared-address deposits snapshot rail/address/amount and globally reserve their
matching tuple. Under/overpayments and ambiguous transfers never receive guessed
ownership. Chain evidence is identified by network/transaction/output-or-trace.
Manual confirmations share the order credit key, require SaaS receipt acknowledgement,
and retain amount reservations permanently. Automatic reads and repeated events
cannot double-credit. Scans use persisted start/checkpoint and never reset history.
Bitcoin mining rewards are excluded from ordinary payment proofs; they require
separate maturity handling and are not supported as deposit payments.

New withdrawals hold only the selected asset. Platform review, external manual
sending, and final chain proof are required; ambiguous outgoing transactions retain
the hold. No manual force-success. Exchange cannot spend deposit, commission or holds.

## UI / deployment
2026-09-16 UI revision: the horizontal account selector lists Security deposit,
Commission, USDT, USDC, ETH, BTC in that order. Deposit and commission are independent
UI account entries denominated in USDT, linking to their existing management pages.
They are not new currencies or spendable wallet balances. Remove the processing-amount
row from the asset center only; settlement holds, estimates and financial rules remain.

The asset center shows original balances and indicative total in USDT, not a
withdrawable total. Fiat and card USD balances are excluded. Missing quotes hide
only estimation/exchange, never original balances. User dialogs preserve stable
request IDs; all copy uses four-locale catalogs. No GET creates financial records.
Deploy via Git in a maintenance window; verify historical balances before/after
migration, configure/enable each rail and exchange policy explicitly. Live acceptance
requires separate concrete authorization. Automated tests use isolated databases.

## Delivered surfaces and storage
- `/dashboard`: four-currency estimate and independent balances, USDT guarantee and
  commission links, recent scoped orders and currency activity. `/assets/{asset}/activity`
  paginates original-currency available-account postings.
- `/assets/operate`: currency/network selection, exact-amount deposit receipt,
  original-currency withdrawal with fee/net review, saved exchange quote and confirmation.
  Existing `/wallet/top-up` and `/wallet/withdraw` retain USDT/TRON compatibility.
- `/platform/settings/assets`: global encrypted CoinGecko/node connections, static
  canonical rails, and company-specific rail policies/exchange fees and limits.
  All saves require active Platform `tenant.manage` and the current password.
- `/platform/asset-deposits`, `/platform/asset-withdrawals`: scoped orders and proven
  operations. `/platform/asset-tron-withdrawals` reuses existing TRON actions behind
  Platform withdrawal permissions. It does not introduce another settlement path.
- `asset_exchange_orders`: immutable quote economics; QUOTED -> COMPLETED. Quote
  snapshots fee; current single/daily limits and enablement are checked at confirmation.
  Daily limit is gross USDT output per source asset and user, using the company timezone.
- `asset_deposit_orders`: immutable snapshots, permanent cross-company rail/address/amount
  reservations. No reservation recycling in this release, including expired orders.
  Exact amount collisions increment the smallest chain unit, bounded to 1,000 attempts.
  Expiry closes manual confirmation; late verification may credit only a transfer whose
  block timestamp was within the original receiving window. Other evidence stays in review.
- `asset_withdrawal_orders`: PENDING -> APPROVED -> PROCESSING/UNKNOWN -> COMPLETED.
  PENDING alone permits user cancellation or platform rejection. Approval may precede an
  external wallet send, so subsequent uncertainty never releases funds. Transaction hash,
  original economics and reviewed actor/time become immutable once recorded. A payout
  position can settle only one withdrawal globally. Destination encryption reuses the
  persistent withdrawal encryption/HMAC keys. Reveal requires current Platform password.
- `asset_chain_observations`: immutable network/event/position/block/amount evidence;
  only projection status changes. Wrong/ambiguous evidence has no credit assignment.

Ethereum requires chain ID 1, `finalized` blocks, successful receipts, exact canonical
ERC20 contracts/log indexes, and complete Geth `callTracer` trees for native ETH.
Reverted ancestor calls invalidate their descendants. Bitcoin Core requires mainnet,
finished initial download, canonical block hashes and at least six confirmations;
outputs use txid/vout identities. Checkpoint disagreement disables that scanner for
review, with no automatic historical balance reversal. RPC endpoints are restricted
by explicit deployment hostname allowlist, HTTPS and no redirects. Node adapters
never post money, sign, broadcast, import keys or create wallets.

Evidence: isolated `MultiAssetTest`, existing Ledger/Promotion/Deposit/Payment/Withdrawal
regressions, type/i18n/build checks, and browser-only responsive fixtures. Fixture screenshots
are illustrative; they do not enable networks, create real orders or move funds.
Offline tests and successful builds are not proof of live mainnet acceptance.

## Verification
- Full backend regression: 1,139 tests, 9,358 assertions; includes live independent
  PostgreSQL sessions racing exchange/withdrawal and manual/automatic receipt credit.
- 67 translation checks, TypeScript, ESLint and Vite production build.
- Browser-only enabled-rail/balance fixtures: 36 consumer locale/viewport checks and
  18 admin checks, no financial or configuration writes. Preview amounts are examples.
- Local card_mock migration verified all existing Ledger identity/value/hash fingerprints
  unchanged, zero reconciliation mismatches. No production funds were used.
