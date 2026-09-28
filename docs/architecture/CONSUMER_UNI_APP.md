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

### Consumer implementation and acceptance

The consumer routes are implemented in Vue/uni-app, including public landing,
login/register/email challenges/recovery, account/settings/security/KYC, assets,
wallets and all four-asset deposit/withdrawal/exchange/transfer views, security
deposits, wealth purchases/interest/maturity/renewal/redemption, card applications,
holder documents, shipping/physical activation, card management/reveal/reload/return,
transactions, promotion purchase/upgrade/renewal/reports/posters, academy/articles,
messages and support text/image uploads. Administration stays React/Inertia.

`pages/screen/index.vue` dispatches explicit consumer DTO components to Vue screens;
this is not a WebView/iframe of old pages. Direct home/message/support pages use the
same scoped API. Internal links stay in uni-app. Original approved images, four
translation catalogs, precision helpers and geography data are bundled. App geography
uses eager data imports because the App service bundle is an IIFE; H5 lazy-loads it.

Original H5 remains the production baseline. Acceptance uses the **compiled** H5,
not the dev server: isolated Pest DTOs plus explicitly synthetic read-model states
are rendered by both React and uni-app, at 375/768/1440px in four languages. See
`docs/testing/uni-app/PARITY.md` for the matrix and generated comparison gallery.
Passing compilation and screenshot runtime checks alone does not certify every
financial path or exact pixel equality. Human visual acceptance and signed native
install/device acceptance remain separate.

Native bearer tokens remain in memory: restarting the App requires login. No token,
password, OTP, full PAN/CVV or PIN is persisted in uni storage. Adding persistent
login requires a verified Keychain/Keystore bridge. Transfer retry intent stores
only its scoped non-secret request ID, asset, amount and recipient; passwords and
confirmations clear on submission/leave. Card details expire after 30 seconds and
clear when leaving/hiding. UNKNOWN issuing/recipient/activation results are not
silently re-submitted. Card transaction reads use platform records only.

## API and identity

API families are explicitly routed in `routes/consumer-api.php`, with full consumer
screen/action adapters in `routes/consumer-client.php`:

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

`/client/<original-consumer-path>` reuses the existing controllers, FormRequests,
authorization, throttling and domain actions. `ConsumerPageResponse` emits allowlisted
page JSON, converts local redirects into client navigation, translates flashed field
errors to HTTP 422, strips client partial-Inertia headers and never returns admin
props. API responses are private/no-store. There is no wildcard route to arbitrary
controllers, mock payments or administration.

Native multi-step registration/recovery/contact proofs use `X-Consumer-Flow`, an
opaque random handle to an isolated server session. It is scoped to tenant, expires
in two hours and is never authentication. Bearer authentication is checked separately.
Flow requests are serialized, controller session rotation is retained, and changing
the authenticated user clears previous flow proofs. Native registration completion
issues its own scoped device token; H5 completion refreshes cookie-session bootstrap.

Redirect flash messages/errors use that same isolated flow session. The request-scoped
redirector is restored in finally, avoiding default/browser session leakage. Bootstrap,
login, locale and recovery API limits use separate operation prefixes so routine reads
cannot consume recovery attempts; the existing rates and tenant-host scope remain.
Auth acceptance evidence: `docs/testing/uni-app/ACCEPTANCE_AUTH_20260926.md`.

Transfers, asset order creation, exchange confirmation and asset withdrawal cancellation
also have independent API throttle prefixes. Unread polling must not consume these
financial-operation allowances. Asset acceptance evidence and remaining visual differences:
`docs/testing/uni-app/ACCEPTANCE_ASSETS_20260926.md`.

Card acceptance evidence, API adapter coverage and outstanding UI/device checks:
`docs/testing/uni-app/ACCEPTANCE_CARDS_20260926.md`.
H5 cardholder uploads decode the selected local blob and inspect PNG/JPEG signatures;
the H5 image-info API does not supply the native MIME-type field. Server validation
remains authoritative. App packaging/device testing is deferred at the user's request
while H5 parity acceptance continues.

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

Latest bounded PhotonPay sandbox lifecycle evidence: `docs/testing/uni-app/ACCEPTANCE_H5_20260927_R3.md`; broad H5 acceptance remains recorded in R2. Public callback delivery is not yet confirmed. This does not replace production deployment, device, or signed App release checks.
