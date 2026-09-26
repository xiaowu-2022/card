# Wealth maturity redemption and renewal — 2026-09-26

Implemented only for new purchases and renewal descendants. Existing policy-null orders
retain automatic principal return. No historical contracts, money or Ledger entries were
rewritten. The new migration has been applied to the local Docker card_mock database;
production deployment has not been performed.

## Verification

- `docker compose exec -T app vendor/bin/pest tests/Feature/WealthTest.php tests/Unit/WealthMathTest.php`:
  **77 passed, 4515 assertions**, isolated `card_ui_test`, stray HTTP requests prohibited.
- Four currencies and all seven terms retain exact monthly cumulative rounding. Native
  precision survives renewal and later redemption, including ETH's 18th decimal place.
- Redemption checks maturity, last second of the local day and exclusive midnight;
  cancellation cannot bypass the new policy. Full principal returns without interest recovery.
- Renewal inherits original principal/term/APR/timezone/config revision, including when
  products are disabled, minimums raised or the company's timezone changed.
- Leap-day and DST boundaries, delayed multi-cycle recovery, disabled user/wallet/ledger
  retry, and new-cycle-only early-withdrawal interest clawback are covered.
- PostgreSQL-session races cover duplicate recovery with manual redemption at maturity,
  and duplicate renewal with a rejected redemption at midnight. Rollback tests preserve
  principal, unpaid installments and the absence of a child when renewal fails.
- Database tests reject changed contract metadata and renewed closures without a successor;
  existing posting evidence and immutable history guards remain enabled.
- HTTP tests check ownership, password, acknowledgement, amount/currency override rejection
  and repeated redemption requests. Read queries continue to be side-effect free.
- TypeScript typecheck, targeted ESLint, Pint and the production frontend build passed.
  Build retains the existing large-chunk advisory.
- `tests/Browser/wealth.mjs`: offline fixtures at 375/768/1440 pixels, en/zh-CN/ms/es;
  purchase review, early withdrawal, maturity redemption, password clearing, pending and
  completed renewal states, cycle navigation and admin read-only configuration passed.
  No database or provider calls; screenshots under `/tmp/card-wealth-browser/`.

## Deployment and monitoring

Coordinate the application and migration switch with writers/scheduler paused, then resume
`wealth:recover` on its existing every-minute schedule. Do not run financial acceptance
against deployed funds. Each run prints failure count and remaining due-order count;
failures log scoped order IDs and sanitized exception classes, and backlog produces a
summary notice. Recovery is bounded to 12 cycles per chain / 1000 processed orders per run;
remaining cycles continue on later runs without changing their scheduled starts.
