# SaaS-owned company configuration

## SaaS profile update (2026-09-14)

SMS/email configuration now uses multiple named SaaS profiles with explicit per-company
selection and optional cross-company reuse. Per-company credential-editing statements
below are historical. Company isolation, encrypted secrets, current-password checks,
fixed providers, quotas and UNKNOWN protections remain. See PLATFORM_NOTIFICATION_PROFILES.md.


## Unified business settings form (2026-09-14)

The user combined company security-deposit amount, refund waiting days and fixed
withdrawal fee into one form and one save. The Platform company business endpoint
uses UpdateCompanyBusinessSettingsRequest and the existing business-settings action
to update all submitted fields in one transaction under Tenant/settings row locks.
The amount and fee remain decimal strings, the asset is server-derived, and optional
waiting days remain null when unconfigured. A single append-only configuration audit
captures all three fields, actor and time. Invalid fields prevent the entire save.
Legacy fee-only callers preserve omitted deposit settings, and the dedicated deposit
endpoint remains compatible. Company Admin's separate request/middleware still denies
deposit and business-setting mutations. No balance, existing order quote, deposit
refund deadline or financial history changes when settings are saved.

## Wallet operation policy update (2026-09-14)

The user removed the wallet top-up and withdrawal switches and required both to be
allowed. The additive `2026_09_14_001100` migration enables both compatibility flags
for all existing company settings and changes database defaults to true. New-company
creation and seeds explicitly enable both. Configuration saves enforce true; old
HTTP payloads may omit the flags or send accepted true values, but cannot disable
them. The UI no longer displays or submits either flag. Existing runtime eligibility
checks remain as defense in depth, including availability, verified identity, wallet,
balance, fees, payment configuration, review and authoritative external verification.
No Ledger, order, deposit amount or fee history is modified by the migration. Rolling
back defaults does not fabricate prior flag values or disable existing companies.

## Global KYC policy update (2026-09-14)

Identity verification settings now use one SaaS global policy for all existing and
future companies, selected AUTOMATIC by the user. Per-company policy statements below
are historical. Tenant-scoped identities and limits, explicit automatic confirmation
and no retrospective approval remain unchanged. See PLATFORM_KYC_SETTINGS.md.


User approved 2026-09-14: company configuration is managed exclusively by SaaS.
Company Admin sees read-only configuration. This supersedes older tenant configuration
permissions, including card offerings, branding/locales/business/KYC policy, articles,
notification settings/tests, promotion levels and member assignments, team invitations
and administrator creation, and computed foundation activation. Domain and security
deposit settings already belong to Platform and keep their existing routes.

Platform entry: opening a company with `tenant.manage` redirects directly to its
card-product configuration. Domain management appears in the same configuration
navigation; the old domain GET URL redirects to `/configuration/domains`. Existing
domain mutation routes/actions retain their exact authorization, tenant/resource
scoping, immutable system-domain and verification rules. Configuration header
offers a company-list return link and a confirmed lifecycle control; DRAFT companies
retain the computed foundation activation entry there. Legacy read-only Platform
roles retain their existing detail view rather than gaining configuration access.
`/platform/tenants/{tenant}/configuration/...` requires an active Platform membership
and tenant.manage, resolves the persisted Tenant route model, and passes it explicitly
to controllers/actions. Never derive the target company from request body, session,
or an administrator's tenant membership. Nested resources still match selected Tenant.
Controllers reuse existing requests/actions and safe query DTOs. Notification/admin
creation password checks use the Platform guard on these routes. Invitation links
remain company-host scoped; tokens, passwords and service secrets remain protected.

Company configuration mutations return 403 before validation/side effects, even for
old forms or direct HTTP calls. Application actions independently require fresh active
Platform authority. Existing tenant permission names permit viewing only, never writing.
Company forms use disabled fieldsets and suppress submit actions; team creation and
activation controls are absent. Navigation/locale choices outside mutation forms remain
usable. Platform pages reuse configuration components within the separate Platform shell.

## Administrator table and dialogs

The company team page uses a member table and separate create/edit dialogs. Creation
keeps the existing confirmed-password action. Editing calls
`PUT /platform/tenants/{tenant}/configuration/team/memberships/{membership}` and
requires active Platform `tenant.manage` plus the current Platform password, checked
again inside the transaction. The action locks company, actor and Platform membership,
then the exact company-scoped target membership. Only its existing allowed non-owner
Tenant role and ACTIVE/SUSPENDED status may change. Owner and revoked memberships
are protected. Shared administrator name, email, password and global account status
are not changed; other company memberships remain intact. The table displays account
and membership status separately. Company Admin gets no create/edit controls.

Each changed membership appends `ADMIN_MEMBERSHIP_UPDATED` with trusted actor, time,
company, request ID and before/after role/status. A no-op save creates no duplicate
audit. Passwords are excluded from audit and cleared from dialogs on completion or
close. No new tables, money operations, or provider requests are introduced.

No configuration migration changes stored values, balances, orders, provider routing,
or financial history. Opening fees remain Platform-only with existing order snapshots.
Promotion changes do not replay rewards. KYC policy changes do not bulk approve history.
Existing operational review flows (e.g. KYC/withdrawal review), support replies, login,
logout, invitation acceptance and personal UI language remain separate from configuration.

Verification covers every configuration write path denied to Company Admin; all read
views still work; Platform pages and scoped saves; body tenant spoofing; inactive Platform
membership; direct action denial; no mail/provider calls in rejection paths.


2026-09-14: custom domains are now configured in the global SaaS domain catalog.
Company domain configuration selects multiple available domains, preserving unique
hostname ownership and protected primary/system domains. See PLATFORM_DOMAIN_CONFIGURATION.md.
