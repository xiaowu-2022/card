# Hardening implementation and acceptance plan

User approved 2026-09-11. Preserve existing identities, company boundaries, invitation
relationships, money and immutable history. No automatic live financial tests or job replay.

## 1. Effective contracts

- [x] Publish CURRENT_CAPABILITIES.md with domain contracts and acceptance caveats.
- [x] Remove conflicting transfer/promotion/card-history pause statements.
- [x] Mark original phase exclusions historical where an explicit extension supersedes them.
- [x] Audit product ownership against routes/admin.php, routes/platform.php and ConfigureTenantCardProductAction; company controls are sales configuration only. Correct obsolete tenant-created product description.
- [ ] Audit user-facing disabled-state reasons: configuration, permissions, provider/card state, uncertainty.

## 2. Account safety

- [x] Name and purpose-scoped verified contact replacement; isolated tests and mobile QA.
- [x] Invalidate other end-user sessions on password change, preserve the current device and independent Admin guard.
- [x] User-triggered other-session revocation with current password and explicit confirmation.
- [ ] Device list with masked network/device facts; no raw session identifiers.
- [x] Forgotten password: separate browser-bound, tenant-scoped expiring proof using an existing verified contact, generic anti-enumeration responses, delivery uncertainty, rate limits and atomic single-use password reset. See USER_PASSWORD_RECOVERY.md; isolated regression and 375px entry QA completed, real delivery acceptance remains in stage 4.
- [ ] Security event history: explicit allowlist, no password/OTP/contact secrets.
- [ ] Contact-change notice to the former contact through durable nonfinancial delivery intent; never undo a successful replacement when notification fails.
- [ ] Lost-all-contacts appeal: independently designed evidence/review flow; no support direct override.

Acceptance: wrong/expired/replayed proof, other tenant/user/browser, concurrent changes,
suspended/disabled access and independent user/admin sessions are tested. Never enter
new live credentials or send real verification messages during automated UI QA.

## 3. Runtime and recovery

- [ ] Read-only inventory of pending/failed jobs and unresolved orders before execution.
- [ ] Dedicated scheduler/worker deployment with bounded retries, graceful shutdown and alerts.
- [ ] Persist trustworthy last-run/last-success health without claiming configuration equals health.
- [ ] Exception center: inspect/query/reconcile only; no force-success, manual credit or history editing.

Acceptance: duplicate processing does not duplicate financial events; UNKNOWN holds
remain; crash recovery uses the original business/provider request identity.

## 4. Real integration acceptance

- [ ] Production DNS verification adapter, validated HTTPS/certificate lifecycle and expiry alerts.
- [ ] Per-company SMS/email delivery acceptance with explicit test recipient and authorization.
- [ ] PhotonPay merchant/product/authentication acceptance and HTTPS notification verification.
- [ ] Named, user-approved card lifecycle acceptance with bounded amounts and no implicit cancellation.
- [ ] Duplicate/tampered/out-of-order notification acceptance and provider-authoritative balance refresh.

## 5. Support and reporting

- [ ] Unread cursors and notification counts based on persisted reads, not last sender.
- [ ] Authorized assignment and pending/in-progress/resolved transitions with audit.
- [ ] Search, pagination and message failure recovery; private attachment rules retained.
- [ ] Consistent dates/money and permission-scoped redacted exports.

## 6. Release readiness

- [ ] Database/private-file/key backup plus restore rehearsal in isolation.
- [ ] Concurrency, company-boundary, secret-redaction and provider recovery suites.
- [ ] 375px consumer and 375/768/1440px Admin QA; four consumer/two Admin locales.
- [ ] Production debug/test-route/network-exposure review.
- [ ] Rollback/roll-forward runbook preserving irreversible financial facts.

Not expanded by this plan: pure-random suffix allocation, cross-company/FX transfers,
automatic custodial payouts, arbitrary uploads, holder identity reassignment. Invitation
counter expansion and deposit-allocation capacity each need a compatible dedicated design.
