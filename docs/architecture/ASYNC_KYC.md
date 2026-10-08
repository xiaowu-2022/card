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
replicas and reads back both originals to check SHA-256. It then recognizes only
the front, via the configured HTTP original URL, retaining the identity validation
and encrypted evidence. Identity number/hash can be filled once after recognition;
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

Implementation validation in this workstation is limited while Docker/PHP are
unavailable. Frontend checks/builds and PHP syntax parsing do not validate PostgreSQL
constraints, scheduler execution or server-side regression behavior.

## Consumer resubmission after failure

Technical FAILED and rejected applications allow the consumer to upload new documents
and create a linked application. Old documents, processing errors and reviews remain
unchanged. Pending active work still blocks duplicate submission; superseded failed
applications cannot be approved or retried. Existing approved identity remains valid
during a failed re-verification and a subsequent explicit re-verification.

Deploy `2026_10_05_200000_allow_failed_kyc_resubmission.php` before enabling the matching
PHP and uni-app H5 build. The pending uniqueness index excludes technical failures
without rewriting historical applications. All new KYC upload tickets use encrypted
server originals even in OSS mode; unsubmitted legacy URL-only tickets require re-upload.
Existing failed URL-only applications may be left intact and replaced by a consumer
submission with new photos. No historical OCR or automatic retry is performed.

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
