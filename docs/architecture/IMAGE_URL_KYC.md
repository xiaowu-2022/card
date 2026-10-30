# Image URL KYC (2026-10-01)

The user replaced Aliyun and OCR.Space with the fixed endpoint
`http://202.95.12.200:9601/ocr`. Laravel sends GET requests with an `image` query
parameter containing its own generated original URL for the required document side. Query encoding preserves
the complete signed URL. As requested on 2026-10-01, OSS original URLs sent to OCR
use HTTP even when the configured public prefix uses HTTPS. Only the scheme is
changed; the current host, object key and original bytes remain the same. Display
and upload URLs retain their configuration, and signed server URLs are unchanged. This user-selected HTTP endpoint requires no API key.
There are no redirects, per-request retries, binary uploads, alternate services
or caller-selected endpoints. Asynchronous application processing retains its
existing bounded retry policy. Connect timeout is 10 seconds and request timeout is 120 seconds.

The accepted JSON response is `{"texts":["recognized line", "another line"]}`.
Only string entries are parsed; failures and malformed responses fail closed.
National IDs require one distinct 18-character number with a valid birth date and
checksum. Whitespace and letter case are normalized without guessing characters.
Name, address, ethnicity and issuing authority remain outside the validation gates.
Both national-ID images are required. Since 2026-10-30, new asynchronous national-ID
processing also recognizes the back original and validates its complete validity
period before automatic approval. Only normalized validity evidence is encrypted;
raw OCR text is not retained. Existing passport labelled number / TD3 MRZ parsing
continues to use only its information page.

Validity uses Beijing calendar dates (`Asia/Shanghai`): start day and expiry day
are inclusive; explicit long-term validity requires a recognized start date that
has arrived. Unrecognized/malformed, expired and not-yet-valid periods produce safe
`KYC_BACK_VALIDITY_UNRECOGNIZED`, `KYC_DOCUMENT_EXPIRED` and
`KYC_DOCUMENT_NOT_YET_VALID` processing categories. Automatic approval rechecks the
saved period against the current Beijing date. Manual review rules remain unchanged.
Failed applications retain their originals and allow immediate linked resubmission;
failed re-verification preserves the current identity. Historical approvals are not
re-recognized or automatically invalidated. See [asynchronous KYC](ASYNC_KYC.md).

## Legacy number preview compatibility

The maintained uni-app H5 selects photos locally, uploads both required originals
on submission and receives an asynchronous application; it does not call a preview
endpoint or require client-side recognition. No client identity number or validity
date is trusted. The retained authenticated POST `/client/kyc/recognize-front`
endpoint serves older clients only and does not approve identity or bypass the
back-validity gate for automatic approval.

Legacy preview still checks tenant/user eligibility, upload ownership, purpose,
side, readiness, expiry, unclaimed state and checksum under the existing lock and
operation limits. Its validated number and upload-bound evidence remain private,
encrypted and short-lived. The server generates OCR URLs; caller image URLs or
numbers cannot change recognition. Logs contain only fixed phases/reasons and HTTP
status, never OCR text, numbers or original URLs.

## Deployment

For the 2026-10-30 validity rule, deploy
`2026_10_30_120000_add_kyc_back_validity_evidence.php` with matching PHP,
administration assets and the uni-app H5 build. Keep the current WebView shell;
this change does not alter its resources or authentication handshake. Run the
migration and rebuild configuration/routes using the site's PHP:

```sh
/www/server/php/84/bin/php artisan migrate --force
/www/server/php/84/bin/php artisan config:cache
/www/server/php/84/bin/php artisan route:cache
```

Use `KYC_OCR_DRIVER=image_url`. The default is now `image_url`; retired `aliyun` and
`ocr_space` environment values map to it when configuration is rebuilt. Other
unknown drivers fail closed. Mock remains explicit and local/testing only.
`OCR_SPACE_API_KEY_ENCRYPTED` is no longer read. Preserve `APP_KEY`,
`KYC_DATA_ENCRYPTION_KEY` and `KYC_IDENTITY_HASH_KEY`.

Rebuild H5 with Node 22 using the current company/base selection; for Spec Pay:

```sh
npm run client -- build --company specpay --mode release --platform h5
```

Deploy `dist/clients/specpay/release/h5` to the site's existing H5 location and reload
its PHP workers. The current WebView App reads this H5; no shell rebuild is
required for the validity rule. OCR must be able to fetch the generated original
from the current image host. Changing OCR service does not move originals to the service's sample Uploads
directory. No real identity submissions or financial operations are used for tests.
