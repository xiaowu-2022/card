# uni-app consumer migration

## Decision and current boundary — 2026-09-26

The user selected **uni-app / Vue 3 / TypeScript with HBuilderX cloud packaging**,
superseding the earlier React/Capacitor proposal. Apps are separately branded and
packaged per company from shared source. Laravel and the existing React/Inertia
administration remain. The existing consumer H5 has NOT been replaced.

`mobile/uni-app` is an independently locked CLI project, also importable in
HBuilderX. Root npm dependencies still belong to the existing React application.
No Capacitor dependencies/configuration remain. The prepare script copies only
pure translation/data catalogs from the current frontend; it never copies PHP,
`.env`, credentials, certificates or the repository into a cloud project.

### Delivered foundation

- Company profile validation, generated manifest, distinct debug package ID,
  deterministic company/mode/platform output folders and build metadata.
- H5 and App resource compilation; no APK/IPA, signing or cloud submission.
- Email/password login, company bootstrap, user account, language preference,
  original-precision asset overview and existing masked card reads.
- Messages: filters, 20-row pagination, escaped plain text detail, explicit POST
  read/read-all, independent message/support badges and aggregate Me badge.
- Support: scoped conversation and private image reads, text send with an immutable
  retry intent, and POST acknowledgement of rendered sequences. No real messages sent.
- Shared four-language catalogs and safe error copy, foreground-only unread refresh.

### Still required before replacing H5 / publishing

Registration and password recovery proof binding; account/security forms; KYC and
cardholder uploads; card issue/reload/return/reveal/activation; deposits, withdrawals,
transfers, exchanges, security deposits, wealth, promotion, academy and record links;
support image upload; native sharing/deep links; complete original UI and financial
confirmation parity; account deletion/privacy release requirements. Do not invent
unimplemented financial controls or silently route a native transaction through a
browser wrapper. Current asset/card pages are **read-only**.

Native persistent authentication is also pending: bearer tokens currently stay only
in memory (relaunch requires login). Do NOT replace this with uni storage, Preferences,
localStorage, a hardcoded encryption key or insecure plugin fallbacks. Implement and
device-test Keychain/Keystore storage before claiming secure persistent login.

## API and identity

Both API families are explicitly routed in `routes/consumer-api.php`:

| Prefix | Authentication |
| --- | --- |
| `/api/v1` | Existing scoped tenant-user session, web CSRF and same-origin cookies |
| `/api/mobile/v1` | Bearer token only; native requests never restore browser/admin cookies |

`GET bootstrap` returns allowlisted tenant/user data, locale/timezone, unread counts
and an H5 CSRF token (null on mobile). `POST login`, `POST logout`, `POST locale`,
`GET account`, `GET assets`, `GET cards`, `GET unread`, `GET messages`,
`GET messages/{uuid}`, `POST messages/{uuid}/read`, `POST messages/read-all`,
`GET support`, `POST support/messages`, `POST support/read`,
`GET support/images/{uuid}` are currently exposed.

Money remains decimal strings; no JavaScript-number financial calculations. Asset
and card reads call the existing scoped queries, not providers or Ledger mutations.
Message read-all reuses the existing receipt watermark and concurrency protection.
GET never marks read. Restricted users can access account/messages; support and
asset/card views retain operational-user requirements. Closed/draft companies and
disabled users fail closed. Normal exceptions use API JSON, not Inertia redirects.

Native tokens use the Sanctum token model in `consumer_device_tokens`, extended with
mandatory tenant scope and captured session_version. Credentials are random 64-byte
alphanumeric secrets (only SHA-256 hashes stored), expire after 30 days and authorize
only the consumer API. Lookups check tenant before user resolution; malformed IDs,
wrong hash/type, expiry, session-version changes and disabled identities are rejected.
The issuance action rechecks password, user and company under Tenant -> User locks.
Sign-out deletes the current device token only. No provider secrets, password or
token is written to audit/log output. Existing authentication audit is reused.

Browser authentication remains independent. Root web/admin routes are unmodified;
there is no broad CSRF exclusion or permissive cross-origin credential policy.
The native client fixes API origin in its company configuration; bootstrap confirms
the expected tenant slug, while server authorization relies on Host and stored IDs.

## Deployment and recovery

Apply the additive `2026_09_26_230000_create_consumer_device_tokens` migration through
the normal deployment process before enabling native sign-in. It creates only a
token table; no historical rows, funds or Ledger are changed. It has been exercised
in isolated `card_ui_test`, not applied to production as part of this work.

A client rollback must not roll back finance or token migrations. Keep old supported
API contracts when shipping later App versions. To stop serving this development
client, remove its separate H5 deployment; existing H5/admin remain available.
Expired token cleanup may delete expired authentication rows only, never financial
or identity records. Add a scoped maintenance command when native deployment begins.

Build and cloud packaging instructions: [UNI_APP_PACKAGING.md](../deployment/UNI_APP_PACKAGING.md).
