# KYC Rules

## Ownership and records

KYC is an independent Tenant-scoped Domain. `kyc_applications` is immutable submission/review history; `identity_records` is the current manually verified identity. Approval never deletes the source application. Phase 3 permits one current identity per `(tenant_id, user_id)`, provides no ordinary update/delete path, and does not implement identity renewal or erasure.

Every query starts with trusted `tenant_id` plus resource/user id. Web tenant identity comes from Host -> active Tenant Domain -> Tenant Context. Jobs carry trusted `tenant_id` and `kyc_application_id`. Composite foreign keys prevent cross-Tenant/cross-User application, resubmission, identity, and source-application relationships.

## Status derivation and state machines

User KYC status is derived, never stored on `users`: a current Identity Record wins and yields APPROVED; otherwise the latest application yields PENDING, REJECTED, or RESUBMISSION_REQUIRED; no application yields NOT_SUBMITTED. A future pending renewal must not remove APPROVED while a current identity remains valid.

Review transitions are only `PENDING -> APPROVED|REJECTED|RESUBMISSION_REQUIRED`. All three outcomes are terminal for that application. REJECTED does not permit self-resubmission; RESUBMISSION_REQUIRED creates a new application whose `resubmission_of_id` points to the prior application with the same Tenant/User. An approved User cannot submit again in V1; renewal is a separate future workflow.

OCR states are independently `NOT_STARTED -> PROCESSING -> SUCCEEDED|FAILED`. OCR failure leaves manual review PENDING and OCR success never approves KYC. Phase 3 review mode is always MANUAL.

## Submission and private documents

Submission requires an ACTIVE Tenant, ACTIVE User, enabled manual Tenant KYC settings, no current identity, and no pending or terminal non-resubmittable application. Suspended Users/Tenants can read `/kyc` but cannot submit. V1 accepts NATIONAL_ID only, requires front and back images, and stores an uppercase two-character ISO 3166-1 country code.

The server MIME-sniffs and validates a real non-empty JPEG, PNG, or WEBP image with valid dimensions and a configured per-file size limit. SVG/HTML/scriptable content is rejected. Files are stored on `KYC_DOCUMENT_DISK` (private local storage in development, private S3-compatible storage in production). Server-generated keys contain only Tenant/User/Application/random UUIDs—never contacts, identity numbers, original names, or client-provided object keys.

Both objects are written before the database transaction; a partial upload or database failure triggers best-effort deletion of newly written objects. The transaction creates the application and audit, then dispatches OCR after commit. Object keys and URLs never appear in ordinary User/Admin props.

## Identity protection and duplicate limits

V1 identity normalization trims, applies Unicode NFKC when available, collapses whitespace to a single space, and uppercases Unicode text. Punctuation is intentionally preserved until country-specific rules exist. The normalized value is encrypted through application-layer encryption for authorized internal retrieval.

Duplicate lookup uses `HMAC-SHA256(KYC_IDENTITY_HASH_KEY, tenant_id + ":" + normalized_identity_number)`. Plain SHA-256 is forbidden. The dedicated key is a long-lived data key, is never exposed/logged, and must not be casually rotated; rotation requires a planned data migration/re-hash workflow.

The identity hash is indexed but not unique. During approval, a PostgreSQL advisory transaction lock on Tenant+hash serializes competing applications, then the action counts current Identity Records against `tenant_kyc_settings.max_accounts_per_identity`. Reaching the limit returns `IDENTITY_ACCOUNT_LIMIT_REACHED` without changing review status. Lowering a limit affects future approvals only and never invalidates existing records. A future identity-update workflow for the same User must not consume a second account slot.

## OCR boundary

`KycOcrProviderInterface` accepts secure in-memory document contents plus minimal document metadata and returns a normalized `KycOcrResultDTO`. Provider payloads are untrusted, schema/length bounded, sanitized, normalized, and encrypted at rest; raw provider JSON is not persisted or passed to React. `ProcessKycOcrJob` carries only Tenant/Application ids, sets and clears Tenant Context, and calls the provider outside a database transaction. Mock SUCCESS/FAILED/TIMEOUT behavior does not alter manual review state.

## Manual review and sensitive access

Reviewers cannot edit identity numbers or documents. They may approve, reject with a bounded reason/message, or request resubmission. Application row locking permits only one final review transition. Approval creates exactly one Identity Record and Audit record; it never creates Wallet/Ledger/Deposit/Card state.

Admin detail returns only the backend-masked submitted identity and a masked, minimal OCR hint. `kyc.read` permits queue/detail metadata; `kyc.review` permits decisions; `kyc.document.view` is separate. SUPPORT has basic read only, FINANCE_VIEWER has none, and KYC_REVIEWER has all three KYC permissions without money/card mutations.

Raw document access requires the current active Tenant Admin membership, `kyc.document.view`, password re-confirmation tied to that AdminUser and Tenant-host session within the configured 15-minute window, and a Tenant-scoped application lookup. The access request creates a five-minute signed streaming route; the signed route rechecks signature, permission, Tenant scope, and recent auth. Every grant writes `KYC_DOCUMENT_VIEWED` with side only—never object key, identity, or signed URL.

## Sensitive output and audit

Models hide encrypted identity, HMAC, object keys, and encrypted OCR. Controllers use explicit allowlisted query output; full identity is never sent to JavaScript for masking. Audit events are `KYC_APPLICATION_SUBMITTED`, `KYC_APPLICATION_APPROVED`, `KYC_APPLICATION_REJECTED`, `KYC_RESUBMISSION_REQUIRED`, and `KYC_DOCUMENT_VIEWED`. Audit/log context excludes full identity, identity hash, encrypted identity/OCR, object keys, signed URLs, and provider payloads.

KYC has no dependency on Wallet, Ledger, Security Deposit, Card, or Card Provider. KYC approval changes Identity/KYC and Audit only.
