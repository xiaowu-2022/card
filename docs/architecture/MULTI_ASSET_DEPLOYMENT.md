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
- Add exact node hostnames to `ASSET_RPC_ALLOWED_HOSTS`, rebuild config cache.
- In SaaS -> Multi-currency settings configure HTTPS node endpoints. Credentials are
  write-only replacements protected by current-password confirmation. Restrict the node
  credentials/proxy to the read methods used by ChainReader. No wallet private keys.
- Ethereum must provide `eth_chainId`, `eth_getBlockByNumber`, `eth_getTransactionReceipt`,
  `debug_traceBlockByNumber` with complete callTracer results at finalized blocks.
  Bitcoin Core needs mainnet `getblockchaininfo`, `getblockhash`, `getblock`,
  `getblockheader`, `getrawtransaction`, `validateaddress`. Use txindex and retain the
  required block history (or a watch-only node/wallet setup providing equivalent reads).
- Set an explicit current start height BEFORE enabling. No default start or replay of old
  blocks is chosen. Cursor/checkpoint advancement is persisted independently per network.
- Configure/check receiving address, enable global rail, then select a company and set
  its minimum deposit and explicit withdrawal fee in the original asset. Zero fee must
  be explicitly entered. ETH18, BTC8, USDC6, chain USDT6; internal USDT remains8.
- Configure CoinGecko Pro API key and enable prices. Then configure source currency fee
  percentage, single gross-USDT limit and per-user daily gross-USDT limit per company.
  Enable exchange only after reading current reserve coverage operationally. The website
  does not obtain/guarantee reserves or perform external hedging.

## Scheduled work
Keep ONE existing `* * * * * php /www/wwwroot/card/artisan schedule:run` entry.
Do not add separate duplicate cron entries for each command. The scheduler now includes:
- `assets:refresh-prices`: every minute, stale/invalid data blocks new quotes after120s.
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
