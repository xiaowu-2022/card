# Multi-asset Git deployment

New connections, rails and company policies are disabled by default. Existing USDT/TRON
and all balances/history remain available through their existing contracts. This release
contains no private-key custody, automatic external conversion or automated payout sender.

## Maintenance release
1. Commit/review the Git change. On the server use `git pull --ff-only`; do not copy an
   update archive or replace the database. Back up PostgreSQL and stable application,
   KYC and withdrawal protection keys using the existing protected backup procedure.
2. Enter `php artisan down`. Stop queue workers and scheduler execution while financial
   writes are quiescent. Preserve pending orders and their stable request identifiers.
3. Save counts and fingerprints of all Ledger Account identities/balances, Posting
   identities/deltas and Entry identities/event hashes. For numeric fingerprints use
   PostgreSQL `trim_scale(value)::text` so added trailing scale does not change the digest.
   Run `php artisan ledger:reconcile` and `php artisan payments:reconcile` first.
4. `composer install --no-dev --prefer-dist --optimize-autoloader`
5. `npm ci --include=optional` then `npm run build`. Use a supported Node 22 runtime;
   missing Rolldown native optional dependencies require reinstalling node_modules for
   the server platform, not copying a macOS node_modules directory or deleting Git's lockfile.
6. `php artisan migrate --force`. The migration widens the general Ledger storage to
   NUMERIC(38,18), recreates the existing deferred cache validator in the same transaction,
   and adds disabled multi-asset configuration/order tables. It changes no numeric values
   or event hashes. No destructive downgrade is supported once financial history exists.
7. Compare the same counts/fingerprints and run both reconciliation commands again.
   A mismatch stops rollout; never repair it with a balance setter or historical replay.
8. `php artisan optimize:clear`, `php artisan config:cache`, `php artisan route:cache`.
   Restart workers, restore the scheduler, and `php artisan up` after checks pass.

## Enabling one rail at a time
- Built-in PublicNode endpoints need no key or environment allowlist. Only custom node
  hostnames require `ASSET_RPC_ALLOWED_HOSTS` and a rebuilt config cache.
- In SaaS -> Multi-currency settings configure HTTPS node endpoints. Credentials are
  write-only replacements protected by current-password confirmation. Restrict the node
  credentials/proxy to the read methods used by ChainReader. No wallet private keys.
- Ethereum must provide `eth_chainId`, `eth_getBlockByNumber`, `eth_getTransactionReceipt`,
  `debug_traceBlockByNumber` with complete callTracer results at finalized blocks.
  Bitcoin Core needs mainnet `getblockchaininfo`, `getblockhash`, `getblock`,
  `getblockheader`, `getrawtransaction`, `validateaddress`. Use txindex and retain the
  required block history (or a watch-only node/wallet setup providing equivalent reads).
- On first enablement choose "Start from the current confirmed block" to save its height
  plus one, or explicitly enter a start height. The connection is tested before enablement.
  Existing boundaries/checkpoints are preserved; tests never scan or credit money.
- Configure/check receiving address, enable global rail, then select a company and set
  its minimum deposit and explicit withdrawal fee in the original asset. Zero fee must
  be explicitly entered. ETH18, BTC8, USDC6, chain USDT6; internal USDT remains8.
- Enable public prices without a key, or retain an optional CoinGecko Pro key in advanced
  settings. Save first, then use Update platform rates (current password required).
  Rates are refreshed once per minute centrally; consumers only read saved snapshots.
  Configure source currency fee
  percentage, single gross-USDT limit and per-user daily gross-USDT limit per company.
  Enable exchange only after reading current reserve coverage operationally. The website
  does not obtain/guarantee reserves or perform external hedging.

## Scheduled work
Keep ONE existing `* * * * * php /www/wwwroot/card/artisan schedule:run` entry.
Do not add separate duplicate cron entries for each command. The scheduler now includes:
- `assets:refresh-prices`: every minute; shared cache lock and 60-second attempt
  gate coalesce scheduled/manual work. Use the existing shared Redis cache store across platform workers/hosts.
  Stale/invalid source data blocks new quotes after 120 seconds, without a per-user fetch.
- `assets:scan ETHEREUM` and `assets:scan BITCOIN`: every minute, bounded batches,
  per-network cursor, no-overlap locks. Disabled/unconfigured networks do no scanning.
Existing TRON/recovery/refund commands stay in the scheduler. Monitor failed command runs,
checkpoint halts, lag and unmatched evidence; unmatched items are not automatically assigned.
Payouts are manually sent by SaaS operators and then explicitly verified through the order.
Retries reuse the submitted transaction; UNKNOWN holds cannot be force-completed/released.

## Acceptance boundary
Live deposits and payouts need separately approved network, company, account and exact
amount. This implementation's tests use card_ui_test and browser-only fixtures, without
real transfers. The local card_mock schema upgrade compared every historical account,
posting and event fingerprint unchanged and reported zero Ledger reconciliation mismatches.

## Local release verification
Full suite: 1,139 tests / 9,358 assertions. Final affected-suite check: 29 tests /
136 assertions; precision/locking suite: 28 tests / 120 assertions. TypeScript,
ESLint, 67 i18n checks and Vite build pass. Vite retains the existing bundle-size
advisory; it is not a build failure. Browser checks: 36 consumer and 18 admin
cases, with no financial/configuration submissions. See [previews](../previews/multi-assets/README.md).

## Unified configuration follow-up
This follow-up needs no migration. Rebuild frontend, refresh PHP/config caches and
restart long-running application processes as usual. The page has one Save all changes action per global/company scope. Platform
updates use the active authorized session without repeated password entry, per
PLATFORM_UPDATE_AUTHENTICATION.md. Unsaved changes are not silently submitted
by test/refresh actions.

Public CoinGecko requires the descriptive User-Agent now sent by MarketPrices.
Local PHP-runtime tests successfully saved fresh market snapshots. Bitcoin full-block
reads succeeded with gzip plus the large-JSON parser fix. Ethereum PublicNode basic
reads succeeded, but its debug trace method is unavailable and its alternative trace
endpoint requires access credentials. The test now explains this instead of a generic
error. A compatible endpoint supporting complete finalized-block traces must be supplied
in advanced settings for native-ETH verification; do not bypass the capability gate.
Node tests do not start a scanner or create financial observations.

The SaaS settings page retains the last saved rates with an expired/disabled label,
rather than hiding them after two minutes. If timestamps stop advancing, check the
existing per-minute scheduler and `assets:refresh-prices` command; manually updating
once does not start recurring updates. Old rates remain ineligible for exchange.

Internal-exchange follow-up (2026-09-16): company exchange configuration now requires
only enablement. Fees are zero and the previous single/daily caps are ignored, even
when stored on existing policies. No migration or resave is required for existing
enabled currencies. Old unconfirmed fee quotes require a new quote. Completed
records, balances, withdrawal fees, freshness and expiry rules remain unchanged.

Percentage withdrawal fees (2026-09-16) require the additive
`2026_09_16_000300_add_asset_withdrawal_fee_percent` migration before serving the
updated application. Run the normal production migration command, rebuild assets
and restart long-running workers. Then enter each company's network withdrawal fee
percentage in SaaS (1 means 1%, 0 means free). Previous fixed fees are not converted;
new withdrawal requests remain unavailable until a percentage is configured. Existing
orders can still be reviewed, cancelled and settled using their immutable fee amount.
