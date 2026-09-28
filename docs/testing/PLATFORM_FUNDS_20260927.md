# SaaS unified funds lists — 2026-09-27

Scope: desktop admin navigation, read-only combined recharge/withdrawal lists, filters and order details. Consumer pages and financial settlement implementations are unchanged. The user explicitly excluded backend mobile compatibility during this work.

## Verification

- Final backend run: Trc20SharedTopupTest, PlatformAccountOperationsTest, MultiAssetTest — **164 passed, 1,769 assertions** in isolated `card_ui_test`.
- Added mixed-source pagination, exact amounts, company/account/email/order/currency/network filters, query retention, old link redirects, invalid filters, company-admin/missing-permission rejection, masked destinations and read-only Ledger/balance checks.
- Existing scoped manual receipt, withdrawal review and financial action tests remain included. No real deposits, withdrawals, provider calls or historical financial modifications were performed.
- Initial regression had 2 failures: the old topup DTO field assertion and an outdated MY national-ID KYC fixture. The assertion now checks the unified operator/time fields; the fixture uses CN dual-side synthetic images and matched mock OCR, preserving isolation/privacy and wallet assertions. The final three-file run passed in full.
- TypeScript, changed-component ESLint, PHP Pint and React build passed. The 77 frontend/i18n checks passed after updating the existing navigation assertion from “Payment orders” to the unified deposit page. The pre-existing large JS chunk warning remains.
- Browser inspection with the existing local sandbox Platform login: merged sidebar entries, active state, ETHEREUM filtering, `500.000001 USDT` displayed without rounding, detail dialog, withdrawal empty state. No financial operation was submitted in the browser.

Evidence: `artifacts/platform-funds-20260927/` (backend log, initial failure log, typecheck/build/lint/translation logs and desktop screenshot).

This closes the admin-list consolidation only. OSS external credentials/migration, PhotonPay public callbacks, dependency upgrades and independent H5 deployment/real-device release checks are separate outstanding tasks.
