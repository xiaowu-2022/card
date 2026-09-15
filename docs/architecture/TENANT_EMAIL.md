# Company-owned Proton SMTP registration email

## SaaS profile update (2026-09-14)

SMS/email configuration now uses multiple named SaaS profiles with explicit per-company
selection and optional cross-company reuse. Per-company credential-editing statements
below are historical. Company isolation, encrypted secrets, current-password checks,
fixed providers, quotas and UNKNOWN protections remain. See PLATFORM_NOTIFICATION_PROFILES.md.


The user's Proton SMTP selection extends the existing email-registration delivery path,
following the confirmed company-owned notification configuration. The explicit contract
extension is `EmailVerificationSender::isAvailable(Tenant $tenant)`; no global availability
check or runtime fallback remains. Laravel/Mail fake delivery is automated-testing-only for
registration. Platform/global administration mail (such as existing invitation delivery) is
outside this customer registration extension; no password-reset feature is introduced.

## Settings and transport

`tenant_email_settings` has a unique Tenant FK, disabled-by-default switch, public sender
address/name, encrypted hidden `smtp_token`, daily recipient limit (0–1000, default 10),
opaque configuration version and timestamps. SMTP username is the sender address, not a
separate credential field; Proton pairs each token to one address. A changed address requires
a replacement token. Blank token retains the old one. Tokens never appear in props, session
old input, audit, logs or browser remembered forms. Settings updates require current Admin
password plus active exact-tenant `tenant_settings.manage`, and lock Tenant before settings.
Queries return allowlisted public configuration and only a token-configured boolean.

Host, port and encryption cannot be supplied by clients: `smtp.protonmail.ch:587` with required
STARTTLS, TLS peer/name verification, LOGIN/PLAIN authentication and bounded socket timeout.
Every send constructs an isolated Symfony transport; no global Mail/config mutation, credential
cache, log/array transport, shared company fallback, automatic retry or caller-selected URL.
Only Proton-generated SMTP tokens work; paid Proton plans and custom-domain addresses are
required. The adapter has no logger/event dispatcher and never exposes SMTP transcripts (AUTH
and DATA include secrets). Explicit negative SMTP replies become sanitized rejection; missing
confirmation/timeouts become UNKNOWN, never a fabricated success. SMTP acceptance does not
prove inbox delivery or contact ownership.

## Registration

As of the 2026-09-12 bug fix, UserVerificationEmailLimit counts registration, contact-change
and password-recovery delivery intents together in the Tenant's calendar day. All three
creators lock Tenant before checking the shared count and hold that lock through intent
insertion; registration then takes its existing contact advisory lock. This prevents
concurrent cross-flow sends from exceeding the cap without a new quota table/cache.
Normalized email and the existing tenant/channel keyed contact hash identify recipients.
Reused challenges do not count again; rejected/uncertain attempts
remain counted. A zero setting removes only this daily limit, not existing hourly/IP limits.
There is no separate quota cache or balance. OTP remains HMAC-only in PostgreSQL and only a
locked, verified, unexpired, browser-owned challenge can create a user.
`registration_challenges.email_delivery_uncertain` is committed before external SMTP. On a
definitive result it clears; otherwise the same live challenge is retained and can accept a
late code. Its owning browser may reopen it without sending; other sessions cannot take it
over. A new authentication attempt is allowed only after expiry; this is not a retry of the
previous SMTP operation. Email and SMS flags are separate; SMS timing and provider stay intact.

## Test email

POST `/admin/settings/email/test` requires the same company permission and current password.
It sends one fixed, non-OTP test message to an explicitly entered recipient with saved, enabled
configuration; settings saves never send. `tenant_email_test_requests` stores only Tenant,
request UUID, configuration version, keyed recipient HMAC, status and timestamps. Its states
are PENDING -> ACCEPTED / REJECTED / UNKNOWN. Stable request replay never sends again, and a
different recipient cannot reuse that request. Tenant locking serializes creation. A live
PENDING/UNKNOWN for the same recipient/configuration blocks new requests, including after a
process crash. Administrators must inspect Proton Sent/inbox for unconfirmed tests; there is no
automatic retry or force-success endpoint. Actual credential rotation permits a new configuration
test. Durable per-company test limits are one/minute and ten/hour. HTTP runs after commit;
audit only contains result and safe request/resource identifiers. Tests neither create Users nor
touch Wallet/Ledger/KYC/Card.

Reference: https://proton.me/support/smtp-submission
