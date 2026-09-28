# SaaS support acceptance — 2026-09-27

Implemented and verified against local development; no real customer messages or
provider operations were sent. Local `card_mock` received the additive migration.
All mutation tests ran on isolated `card_ui_test`, with upstream HTTP blocked.

## Backend

- PlatformSupportTest, SupportChatTest, InboxTest: **34 tests / 361 assertions**.
  Cross-company reads/search/status/30-row pagination; scoped image reads; proactive
  first contact and reuse of tenant conversation; concurrent first creation and UUID
  retries in separate PostgreSQL connections; first-send rollback and staged-image
  cleanup; 50-message history; read/send/manage permission separation, suspended
  staff/customers, forged company/user pairs, exact company authorization;
  self/admin nickname changes, length validation, audit and historical snapshots;
  private DTO identity fields; support/inbox counts and concurrent unread arrivals.
- OssImagesTest and PlatformSettingsNavigationTest: **20 tests / 135 assertions**.
  Existing OSS image behavior and independent configuration/navigation regressions.

## Frontend

- React TypeScript check and uni-app Vue TypeScript check passed.
- Changed support components passed ESLint; localization suite passed 77 checks.
- React production build and uni-app H5 production build passed. Existing build
  advisories remain: large React chunks and the runtime-resolved card background URL.
- Desktop local browser: separate Customer support menu, original thread and image,
  company-scoped customer search, selecting an existing customer, staff tab and fixed
  email width. No production-like form was submitted.
- A loopback-only fixture serves both compiled applications with synthetic DTOs and
  no upstream connection. Both consumer surfaces passed zh-CN/en/ms/es at 375×812:
  nickname text stays verbatim, generic old-message names are localized, `<b>` stays
  literal text with zero HTML nodes, and there is no horizontal overflow.
- Synthetic Platform send failure preserves the draft and displays retry guidance;
  switching from customer A to B empties the composer and disables send.

Artifacts: `artifacts/platform-support-20260927/center.png`, eight consumer screenshots,
`ui-results.json`, and `preview.mjs`. Run the fixture with Node 22 from repository root
after building both applications; it binds only `127.0.0.1:5255`, serves synthetic
responses and rejects all sends. It never proxies backend requests. Close it after QA.

Native app/cloud packaging remains outside this acceptance, as requested.
