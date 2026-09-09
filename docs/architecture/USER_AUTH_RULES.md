# User Authentication Rules

- End User identity (`users`, `tenant_user`) and Admin identity (`admin_users`, Admin guards) are separate even when email values match.
- Every User belongs to exactly one Tenant. Credential lookup begins with resolved `tenant_id`; contacts are unique only inside that Tenant.
- Email normalization is trim + lowercase only. Phone normalization uses libphonenumber and stores E.164.
- A formal User is created only after a Tenant-scoped Registration Challenge is VERIFIED, unexpired, and unconsumed. Creation locks the challenge and atomically creates User, profile, preference, audit, and `consumed_at`.
- OTP is six cryptographically generated digits. Only HMAC-SHA256 of `challenge_id:code` is stored; raw OTP/code hash/password never enters logs or audit. TTL, attempts, resend cooldown, and send/login limits are configured in `config/user-auth.php`.
- Challenge states are PENDING, VERIFIED, EXPIRED, CANCELLED, and LOCKED. Consumption is a separate timestamp. Resend cancels the previous pending challenge.
- User states are ACTIVE, SUSPENDED, and DISABLED. ACTIVE means account access only—not KYC, Wallet, deposit, or card eligibility. SUSPENDED may log in to restricted/account-security/logout routes. DISABLED cannot log in or ordinary-reactivate.
- Tenant ACTIVE permits registration/login/operations. Tenant SUSPENDED permits existing-user login and restricted routes only. DRAFT and CLOSED do not expose normal User authentication surfaces.
- User sessions use host-only cookies and an independent guard. Restored session identities are rechecked against TenantContext.
- Registration/authentication does not create or mutate KYC, Wallet, Ledger, deposit, or Card state.
