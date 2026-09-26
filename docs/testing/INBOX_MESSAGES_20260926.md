# Inbox verification — 2026-09-26

All database tests use guarded `card_ui_test`; browser tests serve local fixture responses and built assets. No live money/provider operations or real broadcasts were performed. Local `card_mock` inbox migration was applied; the live local broadcast table was empty when configuring the dedicated inbox worker.

## Passing checks

- Final inbox feature suite: **11 tests, 100 assertions**. Covers rollback, exact decimals, delivery idempotency, partial failure/recovery, preview snapshots, later registrants excluded, read-only GET, read/read-all concurrency watermark, authentication, same-company/cross-company ownership, suspended access, and 20-item pagination/filtering.
- Focused business regression: **32 tests, 224 assertions**, including inbox cases, wallet sender/receiver notifications, card load definite outcomes versus UNKNOWN, physical-card flows, and wealth maturity notices without financial settlement.
- Combined Inbox/WalletTransfer/Wealth/PaidPromotion/MultiAsset regression: 282 cases; 281 passed on the combined run. The remaining failure was a test comparing PostgreSQL JSONB property order, not financial or message behavior. Changed to order-independent comparison and reran that transfer case successfully in the focused run. Existing financial scopes/precision/renewal tests remained intact.
- TypeScript typecheck, scoped ESLint, PHP formatting, Vite build and `git diff --check` pass. Vite retains the existing large-chunk advisory.
- New four-language business-template/placeholder check and whitespace-tolerant existing header check pass.
- `node tests/Browser/inbox.mjs`: zh-CN/en/ms/es at 375/768/1440px; two shared `99+` badges, read transitions, native ETH precision, escaped administrator text, long titles, empty states, failed read retry, admin layout, explicit preview checkbox and a fixture-only send. Screenshots in `/tmp/card-inbox-browser/`.

## Broader existing regression limitations

The broader CardIssue/Withdrawal run also exercises pre-existing assertions unrelated to inbox. Remaining failures concern the changed public TRON gateway expectation, an existing SaaS card-reveal route, and old cardholder phone/form fixtures. No inbox implementation failure was reported by those cases. Earlier PaidPromotion/MultiAsset/Withdrawal KYC fixtures still used unsupported MY national IDs; relevant setup helpers now use CN with a matching offline OCR stub. MultiAsset SSR is disabled for offline tests.

The full frontend suite reports **68 passed / 8 failed**. Remaining failures concern existing card controls/copy, admin copy and connection text, cardholder phone expectations, default card-name expectations, and older promotion test props. New inbox strings/templates and the modified header check pass; no inbox strings remain in the untranslated-copy failures.

## Operations

Production uses the existing minute Laravel scheduler. Local Compose includes a dedicated `inbox` process that runs only `messages:recover` every 60 seconds; it does not enable global financial schedules. Recovery is tenant-scopeable and only writes inbox records. See `docs/architecture/INBOX_MESSAGES.md` for permission and deployment details.
