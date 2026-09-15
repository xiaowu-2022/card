# Platform identity verification policy

Approved 2026-09-14: the user moved identity verification settings from company
configuration to SaaS global configuration, and finally selected AUTOMATIC review.
The authorized local policy is enabled, maximum one account per identity. This
supersedes per-company policy configuration, not tenant ownership or KYC data rules.

## Persistence and authorization

`platform_kyc_settings` is a database-constrained singleton with enabled, an integer
limit of 1..100 and MANUAL/AUTOMATIC review mode. The additive migration starts
disabled/MANUAL until an authorized administrator explicitly configures it; no legacy
company's automatic policy is silently promoted. Isolated automated test fixtures
explicitly enable MANUAL. Seeding other environments never overwrites global policy.

GET/POST `/platform/settings/kyc` requires active Platform membership and
`tenant.manage`. The action rechecks active administrator and membership under locks,
validates the mode and limit, and requires explicit acknowledgement for AUTOMATIC.
An append-only PLATFORM_KYC_SETTINGS_UPDATED audit records actor, server timestamp,
request provenance and before/after policy without identity or document information.
Configuration writes never invoke providers or change money or KYC applications.

Company settings navigation no longer contains KYC. Legacy SaaS company KYC GETs
redirect to the global page; legacy company POSTs and the old application action
reject mutations. Company Admin direct KYC settings views display the effective
policy read-only. Old tenant_kyc_settings rows remain historical and non-authoritative,
including compatibility rows created by the foundation creation workflow.

## Effective policy and concurrency

Submission, manual/automatic approval, user submission availability, settings/detail
queries and onboarding checks all read the singleton, including for newly created
companies. There is no per-company override or cached copy to synchronize.

Submission/review lock Tenant first, then the relevant application (submission also
locks its scoped User), then the singleton policy, then the canonical identity lock.
The policy writer locks the acting administrator/membership before the singleton.
Its audit is platform-scoped, so it never obtains a company lock after the policy.
This serializes policy updates with approval and avoids the Tenant audit foreign-key
lock inversion. Existing identity limits still count only within the application
company and use the existing tenant/document/country/number HMAC identity scheme.

AUTOMATIC approves only valid new submissions/permitted resubmissions through the
existing approval action with SYSTEM provenance. Existing PENDING applications remain
for manual review, even after switching modes. Existing identity/review history is
never changed. OCR and PhotonPay verification remain independent. No Wallet, Ledger,
Deposit, Commission or Card state is created by this configuration change.

## Verification

PlatformKycSettingsTest covers global/future-company inheritance, audit provenance,
legacy endpoint rejection, actor/membership enforcement and invalid/uncertain input.
KycAutomaticApprovalTest, KycReviewTest and KycSubmissionTest cover approval semantics,
identity limits, tenant isolation, private documents and unchanged pending history.
SaasCompanyConfigurationTest preserves company read-only permissions and redirects.
