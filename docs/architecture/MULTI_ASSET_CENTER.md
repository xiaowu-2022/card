# Multi-asset center (approved 2026-09-16)

Update (2026-09-16): Platform mutations no longer require repeated administrator
password/code confirmation. The current scope and retained authorization checks are
specified in [Platform update authentication](PLATFORM_UPDATE_AUTHENTICATION.md);
this supersedes earlier Platform mutation password requirements in this document.

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
The reference layout keeps the estimate centered above round action shortcuts and a
compact white Accounts card. Account tiles scroll horizontally, with icon, amount and
label stacked centrally. More expands the account list inline. Selecting a currency
switches the activity and recent requests below without opening a dialog; deposit
and commission tiles link directly to their existing management pages. Bottom sheets
select currencies and networks within deposit/withdrawal flows, not homepage wallet
details. The homepage exchange entry always opens the existing USDC/ETH/BTC -> USDT flow;
the destination is fixed and card opening/top-ups use available USDT. Currency
pickers allow inspecting all supported currencies (exchange excludes USDT).
Unconfigured currencies show an explicit unavailable state, with no order/quote
submission controls; network choices remain enabled-only. This supersedes hiding
the exchange navigation entry, not transaction eligibility or SaaS configuration.
No scanner capability is implied.
The Assets homepage omits the My cards summary and empty-card promotion; card
balances and operations remain accessible through the bottom Cards navigation.

The asset center shows original balances and indicative total in USDT, not a
withdrawable total. Fiat and card USD balances are excluded. Missing quotes hide
only estimation/exchange, never original balances. USDT balances (including deposit,
commission and existing holds) contribute at 1:1 without external prices. Zero
non-USDT balances do not require quotes; an empty portfolio displays zero. Only a
nonzero foreign-currency holding requires a fresh cross-rate for the total; if
missing, do not label a partial USDT sum as total assets. Quote timestamps appear
only when market prices actually contribute to the valuation. User dialogs preserve stable
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

## Simplified configuration (2026-09-16)
Internal exchange/valuation reads only persisted platform market snapshots, independently
of ChainConnection or enabled external rails. This is not a generic balance setter or
new cross-user transfer capability. The existing LedgerWriter settlement paths remain.
The platform scheduler fetches all four USD prices in one request each minute. Shared
cache locks serialize scheduler/manual refreshes across workers and a shared 60-second
attempt gate also covers upstream failures. Consumer GET/quote/confirm never refreshes
prices. Fresh snapshots are reused; 120-second source freshness and 30-second quotes
remain. Manual refresh requires active Platform tenant.manage and current password,
and records operator/snapshot audit provenance. Enable/save does not fetch prices.

No key selects the CoinGecko public simple/price endpoint. Existing Pro keys retain
the Pro endpoint until an explicit public-mode save removes the encrypted key.
No key-bearing fallback is made. A failed fetch cannot replace a valid snapshot.
Built-in fixed HTTPS PublicNode endpoints require no deployment allowlist or credentials;
custom endpoints still require the exact deployment allowlist. Changing the endpoint
clears existing credentials; public nodes never receive stored custom credentials.
These are defaults, not an assertion of node uptime or complete method support.

The connection test and enable operation read a finalized block including complete
Ethereum call traces before accepting the connection. No observation, cursor or
financial record is produced by a test. Explicit first enablement can snapshot the
current finalized height plus one; this is an operator-selected prospective boundary,
not a historical replay. Previously set boundaries/checkpoints never reset. Advanced
configuration retains explicit start heights and Bitcoin confirmation counts.
Manual deposit confirmation also requires current Platform password, alongside existing
permission, exact order amount, acknowledgement, immutable actor/time, and the same
idempotent credit key as automatic verification. Public node failure does not block
manual receipt of an otherwise eligible existing order; neither path double-credits.
No new migration, live configuration enablement, chain transfer or balance replay.

Revision validation: 117 related backend tests / 719 assertions passed before the final
explicit-private-provider validation, followed by its targeted regression. 70 i18n
checks, TypeScript, affected-file ESLint and production build passed. Local SaaS browser
checks covered public defaults, advanced controls and 375/768/1440 widths without
configuration or financial submissions. Official defaults: [Ethereum PublicNode](https://ethereum.publicnode.com/),
[Bitcoin PublicNode](https://bitcoin.publicnode.com/) and [CoinGecko public API](https://docs.coingecko.com/docs/keyless-public-api).

## Unified settings and live query diagnostics (2026-09-16)
The active global/company scope uses one form and one transient password input.
Save all submits changed sections only, verifies the current password once, probes
required endpoints outside database transactions, then saves all sections under the
existing configuration lock and one database transaction. A validation failure rolls
back every section, including audit events. Networks are saved before dependent rails.
Original section indexes label errors. Duplicates, mixed scope, nested actions and
non-list batches are rejected. Legacy single-section calls retain password checks.
The password is reused only in mounted React memory for explicit page actions, never
localStorage, sessionStorage, browser history or a server auth bypass. Scope changes
and leaving the page clear it. Entire nested `sections` input is excluded from error
flashing because it may contain replacement credentials.

CoinGecko requests carry a descriptive application User-Agent; the keyless service
returned 403 without it and 200 with it during local acceptance. Platform price updates
still retain freshness, shared request gating, exact parsing and immutable snapshots.
Node requests accept gzip to avoid unnecessarily transferring multi-megabyte decoded
Bitcoin blocks. ExactJson validates original JSON and uses possessive string matching
to avoid PCRE JIT stack exhaustion on large transaction hex fields, without converting
money through floats or accepting malformed number tokens.
Only fixed failure classifications are displayed/logged: service/network, trusted RPC
method, HTTP status and numeric RPC error code. No raw provider body, URL credentials,
API key, password or submitted parameters are logged. Complete trace/finality checks
remain mandatory; connectivity alone is never described as verified deposit support.

Live read-only local acceptance from the PHP runtime:
- CoinGecko: saved genuine fresh USDT/USDC/ETH/BTC price snapshots successfully.
- Bitcoin: full finalized-height block 967282, 9,388 transfer outputs, approximately
  3.85 seconds after compression/parser fixes; no observations or orders persisted.
- Ethereum: chain ID, finalized block and block receipts succeed; public
  `debug_traceBlockByNumber` returns -32601 and `trace_block` is denied with 403/access
  credential requirement. This public endpoint cannot enable complete native-ETH
  verification. A compatible credentialed/custom endpoint is required. No fallback
  drops internal transfers, weakens finality or claims success. Earlier host-only 403
  observations did not establish PHP-runtime reachability and are superseded here.
No live financial transaction, scanner run, cursor advance or historical replay was
performed. Only local market snapshots and explicitly tested reversible settings were
written. Production reachability and provider capabilities remain deployment-specific.

Final validation: 108 related backend tests / 694 assertions, 70 i18n checks,
TypeScript, affected-file ESLint and production build passed. The local SaaS UI
reused its single password input for save, successful rate refresh and successful
Bitcoin verification; Ethereum displayed the explicit unsupported-capability error.
375/768/1440 widths had no horizontal overflow. Temporary market enablement and
the local Vite hot-file override were restored after verification.
