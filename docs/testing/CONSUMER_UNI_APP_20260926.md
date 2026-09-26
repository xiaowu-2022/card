# uni-app foundation verification — 2026-09-26

- `npm run client:typecheck`: passed.
- `npm run test:client`: 3 company-configuration validation tests passed.
- `npm run client -- build --company local --platform h5`: passed.
- `npm run client -- build --company local --platform app`: passed (resources only).
- Existing root `npm run build`: passed; existing chunk-size/runtime-image warnings remain.
- `tests/Feature/ConsumerApiTest.php`: 10 tests, 58 assertions, passed on isolated
  `card_ui_test`. Covers hash-only tokens, tenant/user boundaries, native independence
  from browser cookies, expiry/version revocation, suspended/disabled/closed access,
  read-only GET, scoped read POST, sign-out, malformed tokens and unchanged financial rows.
- Before adding the last two cases, combined ConsumerApi/Inbox/SupportChat/
  UserSessionRevocation run: 41 tests, 307 assertions, passed. The final ConsumerApi
  rerun includes those two additional cases.
- `node tests/Browser/consumer-uni.mjs`: 12 combinations passed, four locales ×
  375/768/1440px. Mocked API responses only. Checks combined 99+ badges, plain-text
  rendering of HTML-like messages, GET vs POST reads, rendered support cursor and
  no page errors/horizontal overflow. Screenshots saved to `/tmp/card-uni-ui`;
  inspected account/messages screenshots and removed uni-app default button borders.
- PHP style check for new backend files and `git diff --check`: passed.

No production migration, provider/chain calls, real notifications, uploads to DCloud,
certificate use, APK/IPA signing, store submission or native-device acceptance occurred.
The new client remains a migration foundation, not full consumer feature parity.
The DCloud toolchain audit and native security/storage/release gates are recorded in
`docs/deployment/UNI_APP_PACKAGING.md` and are not represented as passing.
