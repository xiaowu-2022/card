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
