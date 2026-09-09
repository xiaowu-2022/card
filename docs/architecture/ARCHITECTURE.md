# Architecture

## Shape

The platform is one Laravel 13 application, one React 19/Inertia 3 frontend, one PostgreSQL 18 database, and one Redis service. It is a pragmatic modular monolith: `Application` orchestrates use cases, `Domain` owns rules/contracts, `Infrastructure` adapts storage and third parties, and `Http` translates requests and responses. Controllers remain thin.

Four independent surfaces share one design system: Public Website, Tenant User Center, Tenant Admin, and Platform Admin. Routes live in `public.php`, `user.php`, `admin.php`, `platform.php`, and `webhooks.php`. End-user and admin identities are separate; Platform and Tenant admin authority is expressed by scoped memberships and permissions.

## Tenant context

Tenant web traffic resolves the normalized HTTP host through an ACTIVE `tenant_domains` record and binds its Tenant to `TenantContext` regardless of the Tenant lifecycle status. Resolution answers ownership only; a separate `TenantSurfaceAvailability` policy decides whether the End User or Tenant Admin surface is allowed, restricted, or unavailable. Application code passes the tenant id explicitly into actions and Domain services. The Platform Admin host bypasses tenant resolution and is rejected by tenant middleware.

DRAFT permits Tenant Admin setup but not End User operations. ACTIVE permits both. SUSPENDED permits Tenant Admin and is represented as RESTRICTED for End User so later phases can expose deliberately allowlisted read-only/recovery routes while blocking registration and new financial/card operations. CLOSED blocks normal End User and V1 Tenant Admin surfaces, while retained records remain available to authorized Platform inspection. Status transitions never delete tenant history.

## Money and providers

`Money` uses Brick Math decimal arithmetic at eight decimal places. Phase 0 has no Ledger tables or money-changing workflow. Future Ledger is immutable double-entry storage; no administrator has balance mutation powers.

Card business code will depend on `CardProviderInterface`. `MockCardProvider` exercises the same contract and explicit SUCCESS/FAILED/TIMEOUT/UNKNOWN paths. An external call is made only after local intent/hold commits, then settled or released in a new transaction. A timeout is UNKNOWN.

Production card access is always `CardProviderInterface -> third-party Provider Adapter`. A local card pool is development-only Mock infrastructure and is not a production Card Domain or schema model.

## Future phase boundary

Phase 0 intentionally stops before formal end-user registration, KYC, Wallet/Ledger, payments, withdrawals, security-deposit workflows, Card Product, card issue/load, real providers, agents, commission, and SaaS billing. Static UI records do not represent persisted business data.
