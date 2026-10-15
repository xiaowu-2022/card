# Ordinary business request throttling — 2026-10-15

Ordinary configured routes on Platform administration, company administration,
consumer web and App/H5 APIs use `business-throttle`. It runs after scope/identity
restoration, with independent counters per controller function, company, actor guard
and identity. Object IDs, page filters, domains and query strings never create fresh
authenticated budgets. Company comes from TenantContext or the authorized Platform
route binding; it is never selected from an unvalidated request body. Public guest
bootstrap/version/locale reads use function plus host/IP until identity exists.

| Requests | Allowance per minute |
| --- | --- |
| Ordinary database reads | at least 600 per function/company/user |
| Ordinary numeric write limits | at least 60; larger existing limits retained |
| Presence and transaction failure reports | 60 each, independent |
| Card history reads | 600, separately from provider sync (240) |
| Card operations, topup, security-deposit funding, withdrawal | existing 20 or explicit configured quota, separated by function |
| Media/OCR and promotion purchase | existing independent company/user/action quotas |

`BUSINESS_READ_RATE_LIMIT_PER_MINUTE` and `BUSINESS_WRITE_RATE_LIMIT_PER_MINUTE`
configure ordinary defaults (600 and 60). Existing payment/withdrawal/security-deposit
configuration overrides remain effective. Underlying provider limits are unchanged.
Multiple applicable group/route policies never count the same request twice in the
same bucket. Shared invokable card-management actions have an allowlisted action key.

Login, registration OTP, password recovery/change, recent authentication, contact
verification and protected document/reveal limits remain on existing security
policies. Requests carrying current_password also retain their original password
attempt ceiling, including a shared 20/minute card-password guard across card actions.
No validation, authorization, confirmation, idempotency, financial evidence or provider
retry behavior changes. Financial writes are never automatically resent.

429 diagnostics include function, limit, time window and retry-after without raw keys,
credentials or submitted values. Webhook limits are unchanged, as are public domain
discovery and security middleware. Ordinary reads that had no throttle remain read-only
and do not gain provider requests or other side effects.

Deploy PHP/config/routes together. Rebuild route/config caches using the deployment's
usual commands. No migration, financial production test, external provider calls or
frontend build is needed. Old generic counter data expires naturally; do not flush
production cache or security counters. Native and H5 use the same server policies.
