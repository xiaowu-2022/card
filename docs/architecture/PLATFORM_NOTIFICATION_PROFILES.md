# SaaS notification profiles

Update (2026-09-16): Platform mutations no longer require repeated administrator
password/code confirmation. The current scope and retained authorization checks are
specified in [Platform update authentication](PLATFORM_UPDATE_AUTHENTICATION.md);
this supersedes earlier Platform mutation password requirements in this document.

Approved 2026-09-14: multiple named Aliyun SMS and Proton SMTP profiles are managed
by SaaS; each company selects one saved profile per channel. The same profile may be
assigned to multiple companies. This supersedes company-owned credential editing and
the prohibition on explicitly shared configuration, not the prohibition on implicit
fallback, arbitrary endpoints, plaintext credentials or weakened authentication.

## Persistence and migration

`platform_sms_profiles` and `platform_email_profiles` contain named configurations,
enabled status, existing validated public fields and Laravel-encrypted hidden secrets.
They preserve existing timing/recipient-limit/enablement database checks. The unique
Tenant binding table `tenant_notification_profiles` has independently nullable SMS and
email foreign keys with restrictive deletion. No delete endpoint is provided; profiles
can be disabled. Only enabled/complete profiles can be newly selected.

The additive 001200 migration copies each legacy settings record into its own named
profile and binds its original company, preserving encrypted bytes, IDs and email
configuration versions. It never decrypts credentials, sends messages, merges unlike
configurations or replays requests. Original company rows remain historical only and
are never used as a runtime fallback. New companies start unbound. A rollback drops
the new profile/binding tables; production rollback requires backup/export planning for
profiles created since upgrade and must not silently restore stale credentials.

## Management and authorization

GET `/platform/settings/sms` and `/platform/settings/email` provide explicit allowlisted
profile DTOs, configured booleans and usage counts. POST to these paths creates profiles;
POST with a profile UUID edits one. Routes require active Platform membership and
`tenant.manage`; writes additionally validate the current Platform password. Actions
recheck active administrator/membership under locks and emit sanitized append-only audits.
Secrets are never returned, remembered, flashed, logged or audited. Blank credentials
retain existing values. SMS keys replace as a pair; sender-address changes require a
Proton token. Identical token saves do not create a new email test configuration version.

Company configuration POST `/platform/tenants/{tenant}/configuration/settings/{sms|email}`
now accepts only a nullable profile selection plus current password. Tenant identity is
trusted from the persisted authorized route; client tenant IDs/credentials are prohibited.
Binding changes lock Tenant then the selected profile and record before/after profile IDs,
channel, actor and time. Company Admin sees only its selected profile, never the global
catalog or secrets, and all its configuration writes remain forbidden. Legacy direct
credential-update actions reject mutations. The UI uses global tables and edit/create
dialogs, and per-company selection controls. Saving configuration never sends messages.

## Delivery and isolation

TenantSmsPolicy and TenantEmailPolicy read only the explicitly bound global profile,
with no process-global cache or fallback. Disabling a shared profile affects every bound
company immediately. Provider endpoint/TLS/authentication and encrypted-key failure rules
remain unchanged. Normal SMS/Proton delivery contracts still receive a trusted Tenant;
OTP challenges, identity/contact hashes, availability checks, quotas and registration,
contact-change/password-reset flows remain company-scoped. Sharing a profile does not
share challenge ownership or aggregate company daily recipient quotas.

Company test email continues through its existing SaaS-only authenticated endpoint.
Creation records the selected configuration version and snapshots an ephemeral decrypted
EmailConnection in the same transaction. Sending uses that snapshot outside the
transaction, so a subsequent binding/rotation cannot change which profile was tested.
The snapshot is never persisted or serialized. Duplicate request IDs and PENDING/UNKNOWN
for a recipient/version retain the existing no-resend protections, including switching
away and back to an unchanged profile. No real test send is implied by configuration
migration, save or binding.

## Verification

PlatformNotificationProfilesTest verifies multiple profile creation, independent/shared
bindings, secret-free props, inactive/missing profile denial, company isolation and exact
legacy ciphertext/version migration. Updated TenantSmsTest, TenantEmailTest and
UserEmailQuotaTest exercise the real adapters with isolated fake transports, OTP timing,
tenant-scoped quotas, secure TLS, current-password checks, credential retention and
UNKNOWN/replay behavior. No real recipient or provider is contacted during these tests.
