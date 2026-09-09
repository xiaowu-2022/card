# Virtual Card SaaS Agent Rules

These rules are mandatory for every future change. If a requested feature requires changing a locked rule, report an `ARCHITECTURE CONFLICT` with the current rule, required change, reason, and affected modules; pause only the conflicting work.

## Tenant safety

- Never trust `tenant_id` from client input. Tenant web requests resolve `Host -> tenant_domains -> TenantContext`.
- Tenant resolution requires an ACTIVE domain but is independent of Tenant lifecycle status. Apply DRAFT/ACTIVE/SUSPENDED/CLOSED availability in a separate surface policy.
- Keep domain lifecycle status separate from `is_primary`; never add a `PRIMARY` domain status. Reject Unicode/IDN domains until an explicit IDNA design is approved.
- Every tenant business query must be tenant-scoped by both `tenant_id` and resource id.
- Tenant Admin must never access another tenant. A membership and the resolved tenant must match.
- Platform roles cannot back Tenant memberships and Tenant roles cannot back Platform memberships; preserve the database-level scope match.
- Queue jobs must explicitly carry trusted `tenant_id` and `resource_id`; never depend on session, host, or request context.
- Webhook tenant resolution must use trusted provider connection/resource/order mappings, never a body tenant id.
- Scheduler work must receive an explicit tenant id or deliberately iterate tenants.

## Money safety

- Never modify money outside the Ledger architecture. PostgreSQL is authoritative; Redis is not.
- Never create generic balance setters or helpers named `setBalance`, `updateBalance`, `adjustBalance`, `creditBalance`, `debitBalance`, `manualCredit`, or `manualDebit`.
- Never manually modify wallet, security-deposit, or commission balances—not even for Platform Owner.
- Never edit or delete Ledger history. Correct errors through a new reversal/refund business flow.
- Money must use decimal strings and `NUMERIC(20,8)`; never float/double/real or JavaScript numbers.
- Every future money-changing request must be idempotent. Persist intent and hold, commit, call external providers, then settle/release in a new transaction.

## Provider safety

- Mock and real Card Providers must implement `CardProviderInterface`; business code depends only on that contract.
- Never call an external provider API inside a long database transaction.
- Never implement domain verification as an unrestricted HTTP fetch; production verification must be DNS-only or explicitly SSRF-hardened.
- Provider timeout or an unconfirmed response is `UNKNOWN`, not `FAILED`; reconcile before retry/refund.
- Provider adapters handle HTTP, authentication, and mapping only. They must never modify Wallet, Ledger, Deposit, or Commission.
- Never store Provider credentials in plaintext. Existing secrets are replace/test/disable only, never revealed.

## Sensitive data

- Never store PAN/CVV in ordinary application tables and never log PAN/CVV.
- Never log passwords, password confirmation, OTP, identity numbers/documents, API keys, tokens, or Provider secrets.
- Never dump an entire request in a sensitive flow.
- Never expose unrestricted Eloquent models as API JSON; use explicit DTOs/Resources and allowlisted fields.
- KYC documents use private storage. Identity lookup uses encrypted values plus keyed HMAC, never plaintext indexes.
- Never expose KYC identity ciphertext/HMAC, document object keys, signed URLs, or encrypted OCR results in ordinary model serialization, Inertia props, logs, or audit.
- `KYC_DATA_ENCRYPTION_KEY` and `KYC_IDENTITY_HASH_KEY` are stable persistent-data keys; rotation requires a dedicated verified re-encryption/re-hash migration and must never be treated as routine API-key rotation. KYC encryption must never silently fall back to `APP_KEY` or plaintext.
- Canonical identity matching includes Tenant, document type, country, and normalized number through unambiguous serialization. Never put raw identity data in advisory-lock, cache, rate-limit, audit, or log keys.

## Architecture

- Controllers remain thin: Controller -> Application Action -> Domain Services.
- Complex use cases use explicit Application Actions and DTOs; Domain code receives explicit TenantId/UserId and never calls `auth()`.
- Do not introduce circular Domain dependencies or modify another Domain merely to finish a feature.
- Ledger does not know Card/KYC/Agent/Commission. CardProvider does not depend on Wallet. KYC does not change Wallet. Tenant settings do not change money.
- Do not add tables or states without updating `docs/architecture/`.
- Do not silently modify completed Domain contracts.
- Preserve existing migrations after formal phases begin; use new migrations for changes.
- Audit history is append-only by Application contract. Eloquent guards are defense in depth, not a claim that privileged direct database access is impossible.
- Admin authentication must always validate AdminUser status plus an ACTIVE membership for the exact Platform or resolved Tenant scope; a password or role name alone is insufficient.
- Admin invitation tokens must remain cryptographically random, hash-only at rest, expiring, single-use, Tenant-host scoped, and absent from logs/audit data. Resend must invalidate the previous token.
- Tenant foundation activation must remain computed from persisted requirements. Never add a writable onboarding-complete override or equate Tenant ACTIVE with Card/Provider/money readiness.
- System domains are immutable. Custom domains must pass PENDING_VERIFICATION -> VERIFIED -> ACTIVE, and only ACTIVE domains can become primary.
- End User and Admin identities/guards remain separate. Every End User credential lookup starts with resolved `tenant_id`; never perform a global email/phone lookup and check Tenant afterward.
- Registration creates a User only from a locked, VERIFIED, unexpired, unconsumed Tenant-scoped challenge. OTPs are HMAC-hashed, never persisted or logged raw, and PostgreSQL remains authoritative.
- Expired PENDING challenges become EXPIRED before replacement. A still-valid VERIFIED/unconsumed challenge is reused only for its initiating browser session; another session must prove contact ownership with a new OTP, whose verification expires the older verified state.
- Tenant User session restoration must query by resolved Tenant and user id. User/Tenant status is re-evaluated on every request; restricted access is deny-by-default with an explicit safe-route allowlist.
- Rate-limit keys must hash normalized email/phone and never contain raw contact data, OTPs, tokens, or passwords.
- User `ACTIVE` means account access only; it never means KYC approval, Wallet readiness, deposit qualification, or card eligibility. Suspending a User restricts access and never changes money or cards.
- KYC status is derived from immutable applications/current Identity Record, never stored on `users`. OCR is untrusted assistance and never automatic approval.
- KYC review transitions only from PENDING, approval enforces the Tenant identity limit under a database lock, and no KYC action creates Wallet/Ledger/Deposit/Card state.
- KYC document access requires separate permission, current Tenant scope, recent Admin password confirmation, short-lived signed private access, and a sanitized audit event.

## UI

- Use shadcn/ui primitives and approved project components; do not introduce another design system.
- User surfaces are mobile-first. Admin surfaces are desktop-first but must remain usable at 375, 768, and 1440 px.
- Do not expose Ledger/clearing/posting terms or raw provider errors to end users.
- Do not add arbitrary tenant CSS/JS, custom React uploads, dashboard builders, or page builders.
- Avoid glassmorphism, large gradients, neon/crypto styling, decorative 3D cards, and complex animation.
- Critical actions require explicit warning and confirmation; a toast is insufficient.

## Phase boundaries

Phase 3 contains Tenant-scoped KYC submissions, private NATIONAL_ID documents, Mock OCR, manual review, derived KYC status, current Identity Records, duplicate limits, sensitive access, and KYC audit. It does not contain wallets, ledgers, top-ups, withdrawals, deposit/refund flows, products, card issue/load, real providers, agents, commissions, or billing.
