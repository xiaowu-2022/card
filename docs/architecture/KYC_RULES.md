# KYC Rules

## Ownership and records

KYC is an independent Tenant-scoped Domain. `kyc_applications` is immutable submission/review history; `identity_records` is the current manually verified identity. Approval never deletes the source application. Phase 3 permits one current identity per `(tenant_id, user_id)`, provides no ordinary update/delete path, and does not implement identity renewal or erasure.

Every query starts with trusted `tenant_id` plus resource/user id. Web tenant identity comes from Host -> active Tenant Domain -> Tenant Context. Jobs carry trusted `tenant_id` and `kyc_application_id`. Composite foreign keys prevent cross-Tenant/cross-User application, resubmission, identity, and source-application relationships.

## Status derivation and state machines

User KYC status is derived, never stored on `users`: a current Identity Record wins and yields APPROVED; otherwise the latest application yields PENDING, REJECTED, or RESUBMISSION_REQUIRED; no application yields NOT_SUBMITTED. A future pending renewal must not remove APPROVED while a current identity remains valid.

Review transitions are only `PENDING -> APPROVED|REJECTED|RESUBMISSION_REQUIRED`. All three outcomes are terminal for that application. REJECTED does not permit self-resubmission; RESUBMISSION_REQUIRED creates a new application whose `resubmission_of_id` points to the prior application with the same Tenant/User. An approved User cannot submit again in V1; renewal is a separate future workflow.

OCR states are independently `NOT_STARTED -> PROCESSING -> SUCCEEDED|FAILED`. Provider/worker exceptions are rethrown so the queue can retry from PROCESSING; `failed()` marks non-succeeded work FAILED only after attempts are exhausted. OCR failure leaves manual review PENDING and OCR success never approves KYC. A late OCR result may update only OCR metadata after manual review has reached a terminal state; the review result is immutable. Phase 3 review mode is always MANUAL.

## Submission and private documents

Submission requires an ACTIVE Tenant, ACTIVE User, enabled manual Tenant KYC settings, no current identity, and no pending or terminal non-resubmittable application. Suspended Users/Tenants can read `/kyc` but cannot submit. V1 accepts NATIONAL_ID only, requires front and back images, and stores an uppercase two-character ISO 3166-1 country code.

The server MIME-sniffs and validates a real non-empty JPEG, PNG, or WEBP image with valid dimensions and a configured per-file size limit. SVG/HTML/scriptable content is rejected. This is type validation, not malware scanning; production may later add malware scanning/CDR behind the private-storage boundary. EXIF is neither extracted nor persisted. Files are stored on `KYC_DOCUMENT_DISK` (private local storage in development, private S3-compatible storage in production), never through a public disk or web-root symlink. Server-generated keys contain only Tenant/User/Application/random UUIDs—never contacts, identity numbers, original names, or client-provided object keys.

Both objects are written before the database transaction; a partial upload or database failure triggers best-effort deletion of newly written objects. The transaction creates the application and audit, then dispatches OCR after commit. Object keys and URLs never appear in ordinary User/Admin props.

## Identity protection and duplicate limits

V1 identity normalization trims, applies Unicode NFKC, collapses whitespace to a single space, uppercases Unicode text, requires 3..128 characters, and permits Unicode letters, marks, numbers, punctuation, and ordinary spaces. Punctuation is intentionally preserved until explicit country-specific adapters exist. This bounded generic policy plus manual review does not claim to validate every country's identity format.

The canonical identity tuple is `(tenant_id, document_type, uppercase document_country, normalized_identity_number)`. Each field is serialized as a 32-bit length prefix plus its exact UTF-8 bytes, preceded by a version field, before `HMAC-SHA256` with `KYC_IDENTITY_HASH_KEY`. This prevents delimiter ambiguity and ensures equal numbers in different countries or future document types are independent. Plain SHA-256 and raw identity values in lock/cache/log keys are forbidden.

Identity numbers and normalized OCR hints use authenticated randomized AES-256-GCM through `KycDataCipher` and the independent `KYC_DATA_ENCRYPTION_KEY`, never `APP_KEY`. Decryption occurs only in explicit KYC services. Both KYC keys are long-lived persistent-data keys, never exposed or logged. They must not be casually rotated or discarded: rotation requires a dedicated migration that can read the old key, re-encrypt/re-hash every record, verify completion, and only then retire it.

The identity hash is indexed but not unique. Approval locks the Application row, then the Tenant KYC settings row, then takes a deterministic PostgreSQL transaction-scoped advisory lock derived from the canonical HMAC, before counting Identity Records and writing the identity/review/audit atomically. The settings update Action locks the same settings row, so limit changes and approvals have deterministic ordering. `max_accounts_per_identity` is bounded to 1..100. Reaching the limit returns `IDENTITY_ACCOUNT_LIMIT_REACHED` without changing review status. Lowering a limit affects future approvals only and never invalidates existing records. A future identity-update workflow for the same User must not consume a second account slot.

## OCR boundary

`KycOcrProviderInterface` accepts secure in-memory document contents plus minimal document metadata and returns a normalized `KycOcrResultDTO`. Provider payloads are untrusted, schema/length bounded, sanitized, normalized, and encrypted at rest; raw provider JSON and full candidate identity are not persisted or passed to React. The server persists only MATCH/MISMATCH/UNKNOWN, a bounded candidate name, bounded confidence, and provider reference. `ProcessKycOcrJob` carries only Tenant/Application ids, sets and clears Tenant Context, and calls the provider outside a database transaction. Mock SUCCESS/FAILED/TIMEOUT is restricted to local/testing. Production resolves an unavailable provider until a real adapter is explicitly installed, producing no fake OCR result and never blocking manual review.

## Manual review and sensitive access

Reviewers cannot edit identity numbers or documents. They may approve, reject with a bounded reason/message, or request resubmission. Application row locking permits only one final review transition. Approval creates exactly one Identity Record and Audit record; it never creates Wallet/Ledger/Deposit/Card state.

Admin detail returns only the backend-masked submitted identity and a masked, minimal OCR hint. `kyc.read` permits only the basic queue/status view; `kyc.review` permits the sensitive detail view and review decisions; `kyc.document.view` is separate. SUPPORT has basic queue/status read only, FINANCE_VIEWER has none, and KYC_REVIEWER has all three KYC permissions without money/card mutations.

Raw document access requires the current active Tenant Admin membership, `kyc.document.view`, password re-confirmation tied to the tenant-admin guard, AdminUser, Tenant id, current password hash, and server-side configured 15-minute window. Logout, password change, membership/permission revocation, Admin suspension, Tenant change, or TTL expiry invalidates access. Requests are rate-limited by resolved Tenant plus Admin. The access request creates a five-minute signed application streaming route; the signed route rechecks signature, permission, Tenant scope, recent auth, application, side, and stored MIME. Responses are inline validated images with `Cache-Control: private, no-store`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: no-referrer`. Every grant writes `KYC_DOCUMENT_VIEWED` with side only—never object key, identity, or signed URL. A future S3 pre-signed adapter must use HTTPS, a very short TTL, and perform authorization at issuance because it cannot recheck on object fetch.

## Sensitive output and audit

Models hide encrypted identity, HMAC, object keys, and encrypted OCR. Controllers use explicit allowlisted query output; full identity is never sent to JavaScript for masking. Audit events are `KYC_APPLICATION_SUBMITTED`, `KYC_APPLICATION_APPROVED`, `KYC_APPLICATION_REJECTED`, `KYC_RESUBMISSION_REQUIRED`, and `KYC_DOCUMENT_VIEWED`. Audit/log context excludes full identity, identity hash, encrypted identity/OCR, object keys, signed URLs, and provider payloads.

KYC has no dependency on Wallet, Ledger, Security Deposit, Card, or Card Provider. KYC approval changes Identity/KYC and Audit only.

Tenant suspension and disabling KYC block new User submissions but do not strand existing pending work: authorized Tenant reviewers may complete it. A CLOSED Tenant's Tenant Admin surface remains unavailable and its history is retained. No User document download, full identity reveal, or bulk KYC export exists in Phase 3.1.
