# SaaS domain configuration and company assignment

## Effective revision: no DNS ownership verification (2026-09-15)

The user explicitly removed DNS ownership verification. This revision supersedes
verification prerequisites in the historical sections below and AGENTS.md.
New custom domains are created ACTIVE, non-primary and unassigned by the SaaS
catalog. No token or verified_at is generated; DOMAIN_ADDED records the actual
ACTIVE state. Assignment still explicitly selects one persisted company and an
unassigned ACTIVE hostname continues to resolve to no company.

Global and company-scoped verify endpoints and CheckDomainVerificationAction are
removed. No UI TXT instructions or verification buttons remain, and query DTOs no
longer return verificationToken. Existing verification adapters are retained as
unused infrastructure for historical tests; no domain lifecycle route invokes them.

Existing PENDING_VERIFICATION and VERIFIED rows display as Not activated and can
be explicitly activated by an authorized Platform administrator. Activation locks
the company (when assigned), then the scoped domain, preserves historical tokens,
verified_at and SSL state, and audits the actual before/after status. FAILED,
DISABLED and system domains cannot use this legacy activation path. No automatic
migration, bulk activation or reassignment occurs. Existing enum values and columns
remain to preserve historical evidence; no schema migration is needed.

Hostname format, reserved hosts, global uniqueness, active Platform tenant.manage,
company ownership, primary protection and assignment concurrency checks remain.
DNS routing and HTTPS must still be configured on the hosting infrastructure;
ACTIVE is application routing eligibility, never evidence of a working certificate.

Deploy via Git and rebuild the frontend. Existing pending domains require one
explicit activation before assignment. No verification job or financial replay runs.


Approved by the user on 2026-09-14: maintain custom domains in SaaS configuration;
company configuration selects multiple configured domains. One complete hostname
belongs to at most one company. This supersedes company-specific creation UI, not
Host-based tenant isolation, system-domain immutability or ownership verification.

## Storage and migration

Migration `2026_09_14_001300_allow_unassigned_platform_domains` makes the existing
`tenant_domains.tenant_id` nullable. An unassigned row must be CUSTOM_DOMAIN and
non-primary (database CHECK). Hostname uniqueness and the per-company primary
unique index remain authoritative. Existing IDs, bindings, states, verification
records, primary choices and SSL state are preserved without a data rewrite.
No new domain status or many-company hostname routing is introduced.
Rollback refuses while unassigned rows exist; it does not silently delete them or
assign them to an arbitrary company.

## Authority and routes

Active Platform membership plus tenant.manage is required at every endpoint and
inside domain mutation actions. `/platform/settings/domains` owns create, verify,
activate and deletion UI. Global lifecycle actions derive their optional ownership
scope from the persisted domain and recheck it under lock. Bound domains cannot be
deleted through the catalog. Legacy company-scoped lifecycle routes remain scoped
to company ID plus domain ID; legacy company creation is denied. Company Admin
still has no domain routes or controls.

`/platform/tenants/{tenant}/configuration/domains` lists that company's assigned
rows plus ACTIVE unassigned custom domains. Its POST requires an explicit confirmed
multi-selection and the original custom-domain selection snapshot. Client tenant_id
is prohibited; the persisted authorized route model supplies scope. System domains
are fixed and excluded from the mutable selection. A primary custom domain cannot
be unassigned until another ACTIVE primary is chosen.

## Assignment and routing

AssignCompanyDomainsAction locks the company, checks current Platform authority,
compares the original selection with current bindings, then locks all involved
domains in UUID order. All ownership, availability and primary checks finish before
any write. A competing company cannot claim an already assigned hostname, stale
forms fail without partial changes, and unassigning releases only that company's
non-primary custom domains. Reassignment is explicit through the new company's
selection; existing bindings are never stolen. Assignment changes and the audit
COMPANY_DOMAINS_ASSIGNED (before/after IDs, actor, server time, request ID) commit in
one transaction. Existing scoped primary/delete/activate actions lock Tenant first
so they serialize with assignment. Verification calls remain outside transactions,
then recheck ownership and PENDING_VERIFICATION under lock before recording results.

TenantDomainRepository resolves only ACTIVE rows with a non-null tenant_id. An
unassigned ACTIVE domain is ready for allocation but serves no company (HTTP 404).
Assignment never fabricates verification: new domains follow the existing
PENDING_VERIFICATION -> VERIFIED -> ACTIVE workflow before selection. The existing
local verification adapter and SSL provisioning capability are unchanged; this work
does not claim production DNS/SSL readiness or add unrestricted HTTP verification.

## UI and validation

SaaS shows domain lifecycle and assigned company in a table. Company configuration
shows checkboxes, fixed system/primary selections and one save button. A confirmation
dialog lists additions/removals and their access-routing impact. No domain is added
or assigned to live company data as a UI test. Admin copy uses the shared catalog.

Focused tests cover global CRUD authority, multiple selections, pending-domain
rejection, cross-company ownership, stale form rejection, primary/system protection,
unassigned-host 404, reassignment and actor/time audit. Domain tests within
TenantOnboardingTest now use Platform actors. Broader historical onboarding tests
still contain seven outdated company-configuration permission expectations and are
not evidence of regression in the new domain flows.

Verification on 2026-09-14: 22 focused domain/company-configuration tests passed
(427 assertions), TypeScript and targeted ESLint passed. Applied the additive
migration only to the active local card_mock app on port 8001. Browser inspection
confirmed the global catalog and company assignment table with both original
system-domain bindings intact.


## Global assignment entry point (subsequent user revision, 2026-09-14)

Company allocation now lives directly in `/platform/settings/domains`. The global
catalog allows selecting multiple ACTIVE unassigned custom domains, then choosing
one persisted company in a confirmation dialog. Single-row allocation and removal
are supported. Existing company allocations are included in the original/desired
snapshots so adding selected domains preserves the company's other domains. The
POST `/platform/settings/domains/assign/{tenant}` reuses AssignCompanyDomainsAction
and its authority, locks, ownership checks, primary protection, stale-form checks
and audit transaction. Client tenant_id remains prohibited. A bound domain must be
explicitly unassigned before allocating it elsewhere; system domains stay fixed.

Company domain pages list only assigned domains, with no allocation or lifecycle
controls. The global catalog also exposes company-scoped primary-domain selection.
Legacy SaaS scoped endpoints remain compatible and protected; there are still no
Company Admin domain endpoints. Global company choices are explicit id/name DTOs
and are absent from company page props. No schema change or live reassignment occurs
in this UI revision.
