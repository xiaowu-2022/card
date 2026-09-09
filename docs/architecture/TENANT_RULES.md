# Tenant Rules

- Web tenant identity comes only from trusted Host -> ACTIVE `tenant_domains` record -> Tenant -> `TenantContext`. Resolver ownership lookup never filters on Tenant lifecycle status.
- Query parameters, headers, form fields, and JSON `tenant_id` never select a tenant.
- Platform Admin uses `PLATFORM_ADMIN_HOST` and must never resolve as a tenant domain.
- Host-only session cookies are required; do not configure a shared parent-domain session cookie. Tenant User login and session restoration queries both start with the resolved Tenant; a copied identity cannot restore on another Tenant host.
- Tenant Admin authorization requires an ACTIVE TENANT membership whose `scope_id` equals the resolved tenant.
- Tenant-scoped queries use `tenant_id + resource_id`; unscoped `Model::find($id)` is forbidden for tenant APIs.
- Tenant jobs carry trusted `tenant_id` and `resource_id`. Schedulers use explicit ids. Webhooks map trusted provider connection/merchant/resource/order ids back to a tenant and ignore submitted tenant ids.
- Tenants are suspended or closed, not deleted. Platform-owned resources may have null owner tenant only when their owner scope is PLATFORM.
- Domain->Tenant, branding, locale, and status may be cached and invalidated after change. Redis never owns balances.
- Cross-tenant negative tests are required for every new tenant-scoped module.
- End User identity is Tenant-scoped. Email and phone uniqueness, challenge lookup, credential lookup, verification, and challenge consumption always include the resolved Tenant. The same normalized contact may independently exist in different Tenants.
- KYC applications, identities, review queues, resubmission links, document access, OCR jobs, and duplicate counts always begin with trusted Tenant scope. Client `tenant_id` and known foreign UUIDs never switch or reveal Tenant data.
- Wallet activation receives the trusted Tenant/User identifiers from authenticated Application code. Wallet, Account, Entry, Posting, eligibility, reconciliation, and Admin reads are Tenant-scoped; composite database keys prevent cross-Tenant User/Wallet/Account/Entry relationships. Client input never selects Ledger Tenant or Account instructions.
- Tenant suspension or disabling KYC blocks new User KYC submissions while allowing explicitly authorized reviewers to finish already-pending applications. CLOSED makes the Tenant Admin surface unavailable and retains history. KYC identity limits are `1..100`; settings changes and approvals lock the same Tenant KYC settings row for deterministic ordering.
- Admin invitation links are generated from the invited Tenant's current primary/system domain. The token is valid only when that host resolves the same Tenant; it cannot be accepted on another Tenant or the Platform host.
- Tenant slugs are normalized, globally unique, protected from reserved Platform names, and immutable through ordinary Phase 1 settings. Creation always provides an active `{slug}.{PLATFORM_ROOT_DOMAIN}` system domain.
- System domains cannot be deleted. Custom hostnames are globally unique and move through `PENDING_VERIFICATION`, `VERIFIED`, `ACTIVE`, `FAILED`, or `DISABLED`. Primary designation is an independent `is_primary` dimension, never a lifecycle status: `ACTIVE + is_primary=true` is the canonical primary domain. Multiple domains may be ACTIVE, but only one may be primary. Only ACTIVE domains may become primary and the switch is atomic. A primary domain cannot be disabled or deleted until another ACTIVE domain is selected first.
- V1 accepts ASCII hostnames only. Schemes, paths, ports, wildcards, userinfo, Unicode/IDN input, the Platform root/host, and reserved Platform subdomains are rejected. IDN support requires an explicit future architecture change with reliable IDNA normalization.

Locale priority is User Preference -> Cookie -> Accept-Language -> IP suggestion -> Tenant default. IP is a first-use suggestion, never coercion. Database timestamps are TIMESTAMPTZ/UTC; tenant timezone controls display and business calendars.

Surface availability is separate from resolution:

- DRAFT: Tenant Admin allowed for setup; normal End User operations unavailable.
- ACTIVE: Tenant Admin and End User allowed.
- SUSPENDED: Tenant Admin allowed; existing End Users may authenticate and use only explicitly allowlisted restricted/account-security/logout and Wallet read-only routes. Restricted access is deny-by-default, registration, Wallet activation, and financial/card mutations are blocked, and status is re-evaluated on every request.
- CLOSED: normal End User unavailable and Tenant Admin unavailable in V1; retained records remain inspectable to authorized Platform scope.

The database can enforce at most one default locale. The Application layer must also preserve at least one enabled locale because that cross-row cardinality rule is not represented by a simple ordinary constraint.

Foundation activation is derived by `TenantOnboardingStatusService` from Tenant creation, an active Owner membership, valid branding, locale invariants, KYC/business settings, and an active system domain. The creation defaults—Tenant name as brand name and a valid default HEX primary color—intentionally satisfy the branding portion until an Owner customizes them. No client or Admin can submit an `onboarding_complete` override. `ACTIVE` means the Tenant web foundation is enabled; it never implies Card Provider/Product or money readiness.
