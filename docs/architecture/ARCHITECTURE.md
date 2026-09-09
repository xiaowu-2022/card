# Architecture

## Shape

The platform is one Laravel 13 application, one React 19/Inertia 3 frontend, one PostgreSQL 18 database, and one Redis service. It is a pragmatic modular monolith: `Application` orchestrates use cases, `Domain` owns rules/contracts, `Infrastructure` adapts storage and third parties, and `Http` translates requests and responses. Controllers remain thin.

Four independent surfaces share one design system: Public Website, Tenant User Center, Tenant Admin, and Platform Admin. Routes live in `public.php`, `user.php`, `admin.php`, `platform.php`, and `webhooks.php`. End-user and admin identities are separate; Platform and Tenant admin authority is expressed by scoped memberships and permissions.

Phase 1 activates the administrative control plane. `platform_admin` and `tenant_admin` are separate session guards over the Admin identity provider. Login authorization requires an ACTIVE AdminUser plus an ACTIVE membership for the exact scope, role, and permission. Platform tenant creation and Tenant settings use thin Controllers over Application Actions; audit records are emitted at the same orchestration boundary. Admin invitations use random single-use tokens whose SHA-256 hashes alone are persisted.

Phase 2 activates the End User identity plane through a separate `tenant_user` guard and `users` table. Credential lookup and session restoration are Tenant-scoped from the first query. Registration is challenge-first: PostgreSQL stores the authoritative HMAC-hashed OTP lifecycle, valid verified state is reused only by its initiating browser session, and the User/profile/preferences are created atomically only after a verified challenge is locked and consumed. Email delivery occurs after the state transaction through Laravel Mail; SMS uses a capability-aware contract with a test fake and no log-based local transport.

Phase 3 adds the independent KYC Domain. An authenticated User submits a NATIONAL_ID to private storage; immutable KYC application history is OCR-assisted and manually reviewed into at most one current Identity Record per User. Identity values are encrypted and matched only through a dedicated-key Tenant-scoped HMAC. OCR runs after commit with explicit Tenant/Application ids and never approves. Raw documents require separate RBAC, recent Tenant Admin password confirmation, expiring signed access, and audit.

## Tenant context

Tenant web traffic resolves the normalized HTTP host through an ACTIVE `tenant_domains` record and binds its Tenant to `TenantContext` regardless of the Tenant lifecycle status. Resolution answers ownership only; a separate `TenantSurfaceAvailability` policy decides whether the End User or Tenant Admin surface is allowed, restricted, or unavailable. Application code passes the tenant id explicitly into actions and Domain services. The Platform Admin host bypasses tenant resolution and is rejected by tenant middleware.

DRAFT permits Tenant Admin setup but not End User operations. ACTIVE permits both. SUSPENDED permits Tenant Admin and is represented as RESTRICTED for End User so later phases can expose deliberately allowlisted read-only/recovery routes while blocking registration and new financial/card operations. CLOSED blocks normal End User and V1 Tenant Admin surfaces, while retained records remain available to authorized Platform inspection. Status transitions never delete tenant history.

Tenant creation atomically creates the DRAFT tenant, immutable active system subdomain, branding, initial locale, business/KYC configuration, and Owner invitation. `TenantOnboardingStatusService` derives `foundation_ready` from persisted facts; no mutable completion flag exists. DRAFT to ACTIVE means the web foundation is available, never that issuing cards or handling funds is ready. `business_ready` remains false until later Product/Provider phases.

Custom domains follow `PENDING_VERIFICATION -> VERIFIED -> ACTIVE`. Verification is behind `DomainVerificationService`; local development has a deterministic adapter and production must supply DNS verification. Becoming ACTIVE does not claim a certificate exists: SSL remains an infrastructure status. Only an ACTIVE domain can become primary, and PostgreSQL preserves one primary per tenant.

## Money and providers

`Money` uses Brick Math decimal arithmetic at eight decimal places. Phase 0 has no Ledger tables or money-changing workflow. Future Ledger is immutable double-entry storage; no administrator has balance mutation powers.

Card business code will depend on `CardProviderInterface`. `MockCardProvider` exercises the same contract and explicit SUCCESS/FAILED/TIMEOUT/UNKNOWN paths. An external call is made only after local intent/hold commits, then settled or released in a new transaction. A timeout is UNKNOWN.

Production card access is always `CardProviderInterface -> third-party Provider Adapter`. A local card pool is development-only Mock infrastructure and is not a production Card Domain or schema model.

## Current phase boundary

Phase 3 includes real KYC submission, private documents, Mock OCR, manual Tenant review, derived status, verified Identity Records, duplicate enforcement, and sensitive-document access. It intentionally stops before Wallet/Ledger, payments, withdrawals, security-deposit workflows, Card Product, card issue/load, real providers, agents, commission, and SaaS billing. KYC approval has no cross-Domain side effects.
