# Consumer Token authentication (2026-10-19)

The user approved the same explicit Bearer transport for browser H5 and the
WebView application. Both now reuse `/api/mobile/v1`, `ConsumerApiContext:mobile`,
`IssueConsumerDeviceToken` and the existing consumer controllers. The historic
transport name does not limit these routes to native applications. Administration
continues to use its existing session and CSRF middleware.

## Identity and form proofs

Login and completed registration return the existing opaque `id|secret` token.
The database keeps only the secret hash. Every business request, upload, private
image and support WebSocket HTTP authorization carries `Authorization: Bearer …`.
Browser fetches explicitly omit cookies. Tokens never enter URLs, document titles,
logs, diagnostics, public assets or WebSocket URLs. The server derives the company
from its currently assigned active Host, then validates token company, owner,
abilities, user status, session version, expiry and revocation. Activity retains
the existing sliding 30-day inactivity lifetime; logout deletes the current token.

Registration, password recovery, contact changes and other transient proofs keep
the existing independent `X-Consumer-Flow` server store. It is not authentication.
Bootstrap establishes a flow; login/registration and identity changes discard the
old client flow. Idle form proofs refresh through bootstrap before a submission.
No financial submission or upload is automatically replayed.
A 401 plus valid same-company guest confirmation discards the rejected credential
and obtains a fresh guest bootstrap;
network, timeout, malformed response and server failures retain credentials for
retry. Responses from an earlier client credential generation cannot update state
or flow headers. Ordinary browser storage notifications immediately leave the
previous account's mounted page and recheck the latest credential through login.

## Credential storage

Ordinary H5 persists its token in current-origin `localStorage`, under
`consumer-bearer-v1`. No company/build constant chooses another browser origin.
This deliberately makes the credential accessible to same-origin application
JavaScript; Token transport does not itself improve XSS protection. Maintain the
site's script/CSP and output-escaping protections. No password is retained.

WebView H5 keeps credentials in memory only, awaiting its owning shell's document
handshake before bootstrap. Android reuses the existing AndroidKeyStore AES-GCM
vault, with a separate app/company token namespace. iOS uses a private native file
with atomic `NSDataWritingFileProtectionComplete`, checks the stored protection
class on reads, and excludes it from backups. Locked or unavailable native storage
fails closed into retry. The remote document still has `plusrequire:none` and
receives no filesystem or general native privileges. Native storage requires
Android/iOS device acceptance; browser mocks cannot establish native persistence.

## Native document handoff

The shell first freshly verifies its selected company directory, calls the minimal
Bearer `/session` endpoint, and opens an unguessable document mailbox with a POST.
Only the current verified origin receives the mailbox secret and initial token via
`evalJS`; they never cross title/URL channels. The H5 login/registration path uses
the normal token endpoint, then publishes through an authenticated POST. The
normal lifecycle title signal contains only company, booleans, sequence and a
non-secret credential revision. The owning shell claims that exact revision using
the secret header, independently verifies the resulting token and saves it before
passing the native reveal identity barrier.

Mailbox cache keys hash company and channel secret. Pending channels expire after
two hours; authenticated channel metadata renews for 30 days. Credential envelopes
are encrypted by APP_KEY and expire after one minute, separately from channel
metadata. Same-revision claims can retry a lost response during that minute;
older revisions and other companies fail. These are short-lived authentication
handoffs, not cached API identities or business evidence. A newer publication marks
native storage recovering before any request: a failed account switch cannot
restore the preceding account. Explicit logout advances a local epoch and stores
its tombstone before deletion, preventing late captures from resurrecting it.

Directory discovery randomly samples two groups of up to three distinct candidates,
accepts the first company-verified response immediately, and stops after at most
six probes (2s/group, 4s total). Fresh directory entries are cached without adding
probes. Warm foreground mounted pages/scroll,
no-switch foreground refresh, minimal native identity check, separate full H5
bootstrap and current-document/layout reveal checks remain in place.

## Release and validation

No migration, provider operation or financial replay is required. Deploy the new
PHP routes/action before distributing the rebuilt shell and matching H5. This is a
coordinated authentication cutover: cookie-only installed shells cannot operate
the new memory-only H5 handshake and must be upgraded. Do not replace their shared
H5 entry in isolation. If old installations must remain usable during rollout,
retain their existing H5 entry/assets and route a versioned entry for the new shell
before switching the ordinary-browser entry. The implementation does not silently
fall back to cookie authentication or copy browser/admin cookies.

Existing cookie credentials are not imported; users sign in once after cutover.
Never publish new release metadata before a signed verified package is available.
The shell source version is 2.6.12 (2612); local resource compilation is not a
signed APK/IPA or an installation test.

Offline validation: `ConsumerApiTest`, `ConsumerTokenBridgeTest`, the consumer
Token/flow/upload/restoration/lifecycle tests, WebView shell/readiness tests, and
`tests/Browser/consumer-token-auth.mjs` with Chromium/WebKit and mocked APIs.
Device acceptance must cover cold restart, line change, changed account, logout,
password revocation, locked iOS storage and unavailable Keystore/Data Protection.
