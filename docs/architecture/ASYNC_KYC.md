# Retained asynchronous KYC submissions (2026-10-05)

New submissions save both national-ID sides (passport: information page) before
recognition. KYC upload tickets use the encrypted server-copy path even when OSS
is selected. No client OCR preview or remote OSS request is required to accept
these tickets and the application. The request returns 202 with a retained
application; it does not mean identity approval.

`kyc:process-pending` is scheduled every minute by Laravel's existing scheduler.
It processes only explicitly queued new submissions or administrator retries,
never historical approved applications. No additional queue worker is required.
A per-application lock and persistent next-processing deadline allow recovery
when a process exits. Storage failures receive at most three processing attempts,
with 60/180 second delays. Scheduler cadence can extend these delays. Credentials,
image contents, OCR text and signed URLs are never diagnostic fields.

For OSS mode the processor uploads pending originals from their verified encrypted
replicas and reads back both originals to check SHA-256. It then recognizes the
front and, for national IDs, the back through the existing text-recognition service
using server-generated HTTP original URLs. Front identity validation and back
validity evidence remain encrypted. Identity number/hash can be filled once after
recognition;
previously recognized identity data and completed reviews remain immutable.
Server mode uses signed original URLs on the company's active domain.

The current global review policy selects automatic approval or the manual queue.
Approval retains account limits and identity locking. Processing failures retain
all submitted images and produce a deduplicated failure inbox event per processing
generation. Final approval/rejection reuse existing inbox events. All inbox intents
share the transaction with the result. Transient retry attempts do not notify.

Platform User management > Verification exposes processing state, failure category,
manual approve/reject and Retry verification under `kyc.read` + `kyc.review`.
Owner/Admin receive `kyc.review` incrementally. Retry requires a reason and request
UUID. A technical failure requeues the same retained application; an explicitly
rejected asynchronous application creates a linked application using the retained
immutable images and recognized evidence. Prior reviews are not overwritten.
Already approved applications cannot be retried. Re-uploading new images remains
the existing resubmission/re-verification workflow.

## National-ID back validity for automatic approval (2026-10-30)

New submissions and explicit re-verifications retain the asynchronous upload and
processing flow. National IDs also send their retained back original to the existing
fixed text-recognition endpoint. A complete, unambiguous validity period must be
recognized before automatic approval. Missing or malformed periods expose only
`KYC_BACK_VALIDITY_UNRECOGNIZED`; expired and future-start periods expose
`KYC_DOCUMENT_EXPIRED` and `KYC_DOCUMENT_NOT_YET_VALID` respectively. Consumer and
administration views translate these safe categories without exposing OCR output,
image URLs or internal errors.

Compare calendar dates in `Asia/Shanghai`, independently of company, browser or
server timezone. The start day and the whole expiry day are valid. Explicit
long-term validity is accepted only with a recognized start date that has arrived.
Store only normalized validity evidence encrypted with the KYC evidence; do not
add a client-entered date or use unrelated dates as a fallback. Automatic approval
rechecks the saved validity period against the current Beijing date. Passport
recognition and its approval requirements are unchanged.

Manual approval/rejection retains the existing rules, including manual approval
before OCR finishes or after recognition failure. This new rule adds no manual
review prerequisite. A failed application keeps its immutable original images and
evidence and permits an immediate linked submission; active pending review guards
and superseded retry/review guards remain. Failed re-verification leaves the current
approved identity in force. Existing approved identities are not re-recognized or
revoked by this change.

Deploy `2026_10_30_120000_add_kyc_back_validity_evidence.php` together with matching
PHP, administration assets and H5. The WebView shell and its authentication/line
selection are unchanged; this change does not require a shell rebuild. Validate
using isolated database fixtures, fake HTTP OCR responses and offline frontend/
browser fixtures only. Do not submit real identity documents or contact production
OCR, provider or financial services.

## Deployment / validation

- Requires migration `2026_10_05_190000_add_async_kyc_processing.php`, matching PHP,
  administration and H5 builds. Native apps need a new package because old versions
  still require front-side OCR before submission.
- Keep the existing `artisan schedule:run` every-minute cron enabled. It now also
  runs `kyc:process-pending --limit=20`. Do not manually replay historical OCR jobs.
- Preserve writable private storage and encryption keys. Uploaded images cannot be
  accepted when the encrypted server copy itself cannot be saved.
- Run isolated `AsyncKycProcessingTest` and the related KYC/media/inbox regression
  suite before production deployment. Old synchronous-submission tests need to
  explicitly process the queued application before checking recognition/approval.
- No real KYC documents, notifications to real customers, provider operations or
  financial transactions are part of validation.

Historical validation note (2026-10-05): Docker/PHP were unavailable on the
workstation during the original asynchronous implementation. The 2026-10-30
back-validity change is validated with isolated PostgreSQL/PHP regressions and
offline frontend/browser fixtures; frontend builds alone do not validate database
constraints or server-side behavior.

## One active pending application per account (2026-10-24)

Processing FAILED applications retain their original review and document evidence,
but do not occupy the pending review slot. Consumers may upload new documents to
create a linked application immediately after failure. QUEUED, PROCESSING,
WAITING_REVIEW and legacy pending applications without a processing state still
block duplicate submission. Account/company restrictions and global KYC enablement
remain enforced. Consumer status projects a failed pending attempt as
RESUBMISSION_REQUIRED while retaining its public processing failure category.

Submission and administrator retry share the tenant transaction lock; submission
also locks the user. Retry cannot reactivate a superseded failure or create a second
active pending review. Accepted request replays retain their original application.
The existing partial unique index excludes FAILED processing and already enforces
this rule. No migration or consumer build is required; deploy matching PHP.

## Manual decisions independent of OCR (2026-10-08)

Authorized administrators may approve or reject a current pending application
while it is queued, processing, awaiting review, or technically failed. OCR success
is no longer a manual approval prerequisite. Existing review permissions, company
scope, confirmation, rejection reasons and immutable actor/request audit remain.
A superseded application or completed review cannot be changed by this operation.

Manual approval creates or rebinds the identity projection with `verification_basis=MANUAL`.
If no number has been recognized, both encrypted number and hash remain null;
OCR status, errors and evidence are retained, never fabricated. Known identity
numbers still use the existing company account limit. Unknown numbers cannot
participate in number-based deduplication until a later explicit re-verification
recognizes one. Automatic approval retains its successful encrypted OCR prerequisite.

Both manual outcomes stop scheduled processing. In-flight recognition must recheck
the locked review state before storing evidence or completing review. Consumer
verification reads accept manual identities with no number; card material extraction
uses the approved originals without requiring an OCR number. It uses the existing
profile birth date or requests a real date for this card application when missing;
see PER_CARD_MATERIALS.md.

Deploy `2026_10_08_230000_allow_manual_kyc_without_ocr.php` before the matching PHP
and rebuilt administration assets. The migration changes constraints and adds
identity review provenance without replaying or approving historical applications.
No consumer rebuild or live OCR/financial testing is needed for this change.

## Required national ID number on new manual approvals (2026-10-08)

This supersedes numberless new national-ID approvals above. Platform's review form
shows an identity-number input only when the selected application lacks a number.
Existing numbers stay masked and cannot be replaced. Rejection requires no number;
passport behavior is unchanged. National-ID approval with a missing number validates
format, calendar birth date and checksum, then stores encrypted number/hash with
`identity_number_source=ADMIN` in the same transaction as the review and audit.
Known-number account limits apply equally to administrator input. Invalid or duplicate
numbers leave the pending application unchanged. OCR status/evidence stay intact.
New recognized numbers record `OCR`; legacy source metadata remains readable.

The new `2026_10_08_233000_require_identity_for_manual_kyc_approval.php` migration
allows one initial administrator fill only together with approval, preserving the
existing immutable number and completed-review guards. It rejects new national-ID
approval transitions without a number and leaves historical numberless approvals
unchanged. Deploy it before matching PHP and rebuilt admin assets. Consumer forms
need no identity-number entry for these approvals; card birth dates derive from the
saved national ID. Existing historical numberless card flows remain compatible.

## Standalone Platform application list (2026-10-08)

Platform Identity verification (`/platform/kyc`, `kyc.read`) lists all applications
newest first, with company, account and review-status filters and pagination.
View details opens a read-only right drawer; pending rows expose Review to
`kyc.review` actors in a centered modal. Both lazily read the existing scoped
detail route as no-store JSON; legacy Inertia detail links remain supported.
Review retains confirmation, required national ID on new manual approvals,
immutable completed decisions, document password/audit gates and server checks.
Refresh after review preserves the list filters/page; closing restores trigger focus.
The existing user-row Verification entry remains available. Deploy matching PHP
and rebuilt admin assets; this navigation change requires no migration or H5 rebuild.

## Consumer submission error details (2026-10-09)

App/H5 KYC submission errors identify local photo reading, upload preparation,
front/back upload, upload verification, or final application submission separately.
The upload transport retains same-origin validation errors; the KYC screen uses
only approved translated copy and allowlisted field labels. Raw proxy HTML,
provider output, document paths and signed URLs are never shown. Timeouts, missing
responses, HTTP upload-size limits, unsupported formats, authentication expiry,
throttling and unavailable services have distinct explanations and next steps.
Unknown errors explicitly say that no specific reason was supplied.

An unconfirmed final submission tells the user to refresh status before resubmitting:
a timeout does not prove that the server rejected the application. There is no
new automatic upload/submission replay. The selected photos remain available for
correction; cancelling or starting a new verification clears stale form errors.
Existing approved identity and asynchronous processing rules are unchanged.

Deploy the rebuilt `public/h5` for the WebView shell. This presentation change
requires no backend migration or APK rebuild. Offline frontend tests cover the
real upload/action path; browser fixtures cover failed re-verification upload and
unconfirmed submission without contacting document services.

## Platform verification detail display (2026-10-25)

The shared Platform verification detail and user drawer history display FAILED
when review is PENDING and processing has FAILED. Completed review decisions remain
authoritative. Preserve raw reviewStatus for manual review authorization and audit;
this presentation correction never rejects or mutates an application on reads.
Deploy matching PHP and administration assets.

Platform identity verification list uses the same FAILED display projection as
its details. FAILED filtering selects pending reviews with failed processing;
PENDING filtering excludes these failures and includes legacy null processing
states. Completed decisions retain precedence. Pagination totals use these exact
filters. Manual review authorization continues to use the original review state.
