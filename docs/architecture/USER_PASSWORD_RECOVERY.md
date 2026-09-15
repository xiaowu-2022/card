# End-user password recovery

Approved in the 2026-09-11 hardening plan. This is an independent User-domain proof,
not registration, contact replacement, KYC or an administrator override. Guest
routes live under the host-resolved user-auth surface; ACTIVE/SUSPENDED companies
permit recovery, DRAFT/CLOSED do not. A successful reset preserves User lifecycle,
company, contacts/verification dates, public account ID, profile and financial data.

## Persistent intent

`user_password_resets` has a UUID, company FK, nullable User/company composite FK,
company/request UUID uniqueness, channel, encrypted destination, keyed contact,
browser and IP hashes, nullable credential fingerprint, HMAC-only OTP, attempts,
delivery uncertainty, expiry, cancellation and consumption. Nullable mapping represents
an unmatched/unverified/disabled account and can never become a resettable user later.
Do not serialize the model: public DTO is only id/channel/expiry after browser proof.

Creation normalizes email or E.164 phone, locks Tenant then any mapped User, and
looks up User by company + contact + verified timestamp + ACTIVE/SUSPENDED status.
The credential fingerprint covers company/user/password hash/session version/both
contacts. Any subsequent password change, contact replacement or session revocation
invalidates the proof. The browser binding is random session-only material; public
challenge UUID alone grants no rights. Registration/contact-change OTPs cannot be used.

## Enumeration and delivery

All syntactically valid destinations take the same generic OTP delivery path, including
unmatched contacts. Receiving a code never proves an account exists and never creates
an account. Identical response shapes and transport behavior avoid an account-existence
oracle through errors or synchronous email/SMS latency. Only a valid mapped verified
contact can actually reset. Invalid/expired/wrong-browser/unknown proof returns the same
generic failure. No raw destination is embedded in URLs, cache keys or audit records.

Public delivery has database-backed recipient/browser hourly caps (configured send
limit), per-company hashed-IP cap of 20/hour, company cooldown/TTL, and HTTP throttles.
Registration, contact-change and recovery now share UserVerificationEmailLimit under
the Tenant row lock (2026-09-12). All three count each other's persisted email intents
in the company calendar day. Administrative test mail keeps its separate existing limits.
Unavailable company transport fails closed before creating intent. Intent commits
before sending; no raw or decryptable OTP is persisted or queued. Retries of the same
request return the existing proof without sending twice. Uncertain/crashed delivery
retains the proof and blocks recipient resend until expiry. Definitive rejection cancels
it. Resend cancels only prior same-browser/contact proof; another browser cannot replace
the original proof merely by requesting a code (recipient throttles still apply).

## Reset

One POST verifies six digits plus a confirmed password of at least 12 characters,
upper/lowercase letters and a number, and explicit acknowledgement of session expiry.
Lock Tenant -> User -> reset row. Incorrect attempts commit before returning failure;
expiry, attempt limit, company/user state, current verified destination, session binding
and credential fingerprint are checked again. Success atomically changes the password,
increments session_version, consumes the proof and writes a sanitized User audit event.
Consumed same-browser/code retries acknowledge only and never apply another password.

No automatic login. The initiating guest session ID regenerates without flushing its
independent Admin login; the user returns to login with localized success feedback.
All prior User sessions are rejected at their next request by existing version checks.
No browser local storage, password/code flashing, live mail/SMS or live credential
changes during automated QA. Private/no-store pages use the shared four-locale catalog.
