# OCR.Space KYC (2026-09-30)

User selected OCR.Space instead of Aliyun and clarified that mainland national ID
recognition needs only the number. National IDs call OCR once for the front image;
no name, issuing-authority or validity-period recognition is required. The existing
CN restriction and front/back upload contract remain. Extract exactly one distinct
18-character number, validate its checksum and birth-date format, and fail closed on
empty, ambiguous or invalid results. Do not correct OCR characters by guessing.
Passport submissions retain one information page; accept an explicitly labelled
passport number or a TD3 MRZ number with its check digit, rejecting conflicting numbers.
OCR is text extraction, not proof of document authenticity or holder identity.

The adapter POSTs multipart URL parameters to `https://api.ocr.space/parse/image`,
with an `apikey` header, Engine 2 and automatic language selection. URL input uses
the existing ImageStorage original URL contract (current OSS or signed server URL),
never binary/Base64, processed images, caller-selected endpoints or historical replay.
The API must be able to fetch the supplied original URL. Application upload limits
remain 10 MiB; the provider's own plan limits can still reject an image. API failures,
partial parsing, page errors, malformed responses and timeouts cannot approve KYC.
Connections allow 10 seconds and requests 120 seconds, without retries or redirects.

Only successful extraction reaches the existing normalization, encryption, HMAC,
tenant identity-account lock and approval workflow. Client identity_number remains
excluded. New evidence records `ocr_space` and the existing encrypted OCR-source flag.
Old applications, identity records, documents and evidence are never rewritten.
Logs contain fixed phase/reason, HTTP status, allowlisted OCR/page exit codes and a
fixed provider-error category only, never text, URLs, raw errors, exception chains
or keys. Error categories are diagnostic hints from known upstream error phrases;
unknown messages stay `unclassified` and never change approval decisions.
No financial/provider operations are introduced.

For deployment troubleshooting, `phase=configuration` means the local encrypted key
is missing, empty or cannot be decrypted. `phase=response,http_status=200` means an
HTTP response arrived, not that the API key or OCR was accepted. The additional
`reason` distinguishes invalid JSON, invalid provider results and invalid page results.
`provider_error_category` can identify key rejection, quota, file size/type, download
or timeout messages without retaining upstream content. Deploy the adapter and reload
PHP workers to obtain these diagnostics on future submissions; do not replay history.

## Configuration and deployment

Set `KYC_OCR_DRIVER=ocr_space` (also the default). Store only Laravel Crypt-encrypted
key ciphertext in `OCR_SPACE_API_KEY_ENCRYPTED`, encrypted using the target deployment's
APP_KEY. The local ignored .env is configured; this does not deploy another server.
To provision a target, use `php artisan tinker`, read a hidden prompt via
`Illuminate\Support\Facades\Crypt::encryptString(Laravel\Prompts\password('OCR.Space API key'))`, in one expression so the REPL prints only ciphertext; save only the ciphertext in
the target secret environment. Never paste the raw key into a source file or command
history. Keep the existing APP_KEY stable. Aliyun OCR variables are no longer read;
independent Aliyun SMS and OSS settings are unaffected.

Deploy backend files, rebuild configuration with `php artisan config:cache`, and
reload the site's PHP workers. No schema migration or frontend build is required.
Unknown drivers and missing/corrupt encrypted keys fail closed. Mock remains explicit
and local/testing only. No real identity images were used for acceptance testing.

Tests: `tests/Feature/OcrSpaceKycIntegrationTest.php` plus existing KYC, image storage,
security, architecture and redaction suites use isolated databases and HTTP fakes.

References: [requested PHP example](https://github.com/A9T9/OCR.Space-OCR-API-Code-Snippets/blob/master/PHP%20Demo%20Web%20App/processing.php),
[official request/response documentation](https://ocr.space/ocrapi).
