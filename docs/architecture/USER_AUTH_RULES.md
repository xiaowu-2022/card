# User Authentication Rules

Forgotten-password recovery uses a dedicated browser-bound proof under
[USER_PASSWORD_RECOVERY.md](USER_PASSWORD_RECOVERY.md), never a registration proof or
support/admin password override. Successful recovery invalidates all prior User sessions.

Account profile editing and purpose-scoped contact replacement are specified in
[User account information](USER_ACCOUNT_INFORMATION.md); registration proofs are never reused for rebinding.

User-approved hardening adds a server-owned `users.session_version` (default 0).
Login/registration bind the exact current version into the host session. Existing
unversioned sessions count as 0, not as the latest version. Every stored User session
is compared with the scoped User version before serving User or company routes.
Password changes atomically increment the version and audit under Tenant -> User
locks; explicit current-password/confirmation other-device revocation does the same
without changing the password. Only the initiating session receives that action's
exact returned version (never a later concurrent version). Stale sessions lose only
the User guard and its contact-change binding on their next request; independent
Admin authentication and locale are preserved, with no session-table deletion or flush.
The current device remains logged in; a failed/lost response may require it to log
in again. No active-device count/list is fabricated. Suspended users retain these
account-security actions; disabled users and closed/draft companies do not.

The authorized [Promotion stage](PROMOTION_REQUIREMENTS.md) requires a valid
Tenant-scoped invitation on public email/SMS registration. Link codes are bound
to the browser and persisted immutably on the verified challenge; completion
atomically creates the User and referral member. It never weakens OTP ownership,
expiry or consumption checks and does not create a wallet or move funds.

Company-owned Proton SMTP extends email availability with an explicit Tenant and a
durable uncertain-send flag. See [TENANT_EMAIL.md](TENANT_EMAIL.md). No shared/log
registration email fallback is allowed at runtime; verification gates remain unchanged.

Company-owned Aliyun SMS configuration extends the SMS availability contract with
an explicit trusted Tenant, and persists uncertain delivery for registration recovery.
See [TENANT_SMS.md](TENANT_SMS.md). All existing OTP ownership and verification gates remain.

- End User identity (`users`, `tenant_user`) and Admin identity (`admin_users`, Admin guards) are separate even when email values match.
- Every User belongs to exactly one Tenant. Credential lookup begins with resolved `tenant_id`; contacts are unique only inside that Tenant.
- Email normalization is trim + lowercase only. Phone normalization uses libphonenumber and stores E.164.
- A formal User is created only after a Tenant-scoped Registration Challenge is VERIFIED, unexpired, and unconsumed. Creation locks the challenge and atomically creates User, profile, preference, audit, and `consumed_at`.
- OTP is six cryptographically generated digits. Only HMAC-SHA256 of `challenge_id:code` is stored; raw OTP/code hash/password never enters logs or audit. TTL, attempts, resend cooldown, and send/login limits are configured in `config/user-auth.php`.
- Challenge states are PENDING, VERIFIED, EXPIRED, CANCELLED, and LOCKED; consumption remains a separate timestamp. Allowed state transitions are PENDING to VERIFIED, EXPIRED, CANCELLED, or LOCKED. Resend marks expired PENDING history EXPIRED and cancels only a still-live prior PENDING challenge. Cancelled/expired/locked history does not block replacement.
- A valid VERIFIED/unconsumed challenge is reusable only by the browser session that initiated it; Challenge UUID alone is not a completion credential. A repeated send in that session reuses it without sending another OTP. Another session receives a fresh challenge and must verify a fresh OTP; successful verification expires any older valid VERIFIED state for the same Tenant/channel/destination. VERIFIED status never extends `expires_at`, and expired VERIFIED history cannot create a User.
- User states are ACTIVE, SUSPENDED, and DISABLED. ACTIVE means account access only—not KYC, Wallet, deposit, or card eligibility. Restricted access is deny-by-default: SUSPENDED may use only explicitly allowlisted restricted/account-security/logout routes. DISABLED cannot log in or ordinary-reactivate, and an existing session is rejected on its next request.
- Tenant ACTIVE permits registration/login/operations. Tenant SUSPENDED permits existing-user login and restricted routes only. DRAFT and CLOSED do not expose normal User authentication surfaces.
- User sessions use host-only cookies and an independent guard. The Tenant-aware provider scopes session restoration itself by resolved Tenant plus user id, and request middleware clears mismatched/disabled identities. User and Tenant status are read again for every request, so suspension/closure affects existing sessions immediately.
- Registration/login rate limits hash normalized contact identifiers before building Redis/cache keys. Raw contacts, OTPs, tokens, and passwords never appear in rate-limit keys. If `APP_KEY` supplies the OTP HMAC secret, rotating it invalidates outstanding short-lived registration challenges.
- Registration/authentication does not create or mutate KYC, Wallet, Ledger, deposit, or Card state. After KYC approval, the ACTIVE User explicitly activates a Wallet through an independent idempotent use case.
- A SUSPENDED User may read an existing Wallet but cannot activate one or perform any financial mutation. DISABLED Users have no authenticated Wallet access.
- Authentication is required for KYC submission. Suspended Users may read `/kyc` but cannot submit; KYC status remains a derived KYC concern and is never an authentication flag on `users`.
