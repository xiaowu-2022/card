# Platform update authentication

Approved 2026-09-16: SaaS updates use the authenticated Platform session without
re-entering an administrator password or verification code. This supersedes the
previous per-operation password clauses in notification profiles, administrator
management, paid promotion and multi-asset documentation, including the earlier
single-password multi-asset form.

Covered mutations: global/company multi-asset configuration, rate refresh and node
tests; SMS/email profile creation, replacement, assignment and test send; Platform
and company administrator creation and company membership updates; paid promotion
configuration and fee-rebate review; manual multi-asset deposit confirmation.
No update-time verification-code challenge existed in these flows; none is added.

Authorization remains on the server: active administrator and Platform membership,
operation-specific permissions, trusted selected company/resource, CSRF and existing
throttles. Administrator actions recheck membership under their existing locks.
Configuration batch atomicity, encrypted provider credentials, deterministic financial
request keys, explicit money-operation acknowledgements, immutable business records
and operator/time audits remain unchanged. No generic balance manipulation is added.

Login and new administrator passwords remain. Consumer authentication, Tenant-only
request validation and sensitive read/reveal verification (including withdrawal
addresses) are not update prompts and retain their existing requirements. Old
clients may still send current_password/password; Platform updates ignore them,
and the existing dontFlash exclusions continue protecting error sessions. Shared
notification/administrator requests exclude current_password only on Platform routes.

No database migration, data rewrite, session-wide impersonation, remembered-password
mechanism or permission bypass is introduced. Verification uses isolated test data;
no real email, payment, refund or administrator grant is performed in browser tests.

Validation: 158 backend regressions / 1,187 assertions passed across multi-asset,
administrator creation/membership, notification profiles, SMS/email, paid promotion
and admin authentication. TypeScript, affected-file ESLint, 70 i18n checks and
production build passed. Local browser acceptance confirmed zero current-password
inputs on multi-asset settings and successful direct save, followed by restoration
of the reversible test configuration. No financial actions or external test sends.
