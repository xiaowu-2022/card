# Image URL KYC (2026-10-01)

## Restored original provider (2026-11-02)

At the user's request, the Aliyun switch has been reverted. Set
`KYC_OCR_DRIVER=image_url`; this is again the default and selects the original
fixed endpoint below. The legacy `aliyun` and `ocr_space` values also resolve to
`image_url`, as before the switch. The runtime no longer calls Aliyun OCR or reads
the dedicated `ALIYUN_OCR_*` settings. OSS originals again use the existing HTTP
convention; signed server originals keep their permanent links. PNG upload MIME
correction remains in the separately committed H5 build.

Deploy through Git, rebuild configuration with the site's PHP
(`php artisan config:cache`) and reload the PHP processes. This rollback requires
no migration, new frontend/native build, credential change or historical replay.
Validate only with isolated fake-HTTP tests; do not retry applications or call real
OCR as part of deployment.

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

## Permanent KYC originals (2026-11-02)

KYC originals stored on the application server now use permanent, host-bound
signed `/media/images/{image}?profile=original&signature=...` URLs. They have no
`expires` parameter and require no administrator session when read by OCR. The
signature still prevents changing the image, profile or hostname; the original
remains encrypted in private storage and is served only from its trusted, ready
KYC image mapping. The link does not time out while that host, signing key and
stored original remain available. Other image previews retain their existing
expiration rules. OSS originals keep the existing storage and HTTP OCR convention.

Platform document photos provide Copy original link for each available side.
An explicit scoped POST to
`/platform/tenants/{tenant}/kyc/{kyc}/documents/original` accepts `side` and the
existing recent-password confirmation, requires active `kyc.read` and
`kyc.document.view`, and returns only that side's original URL in a private/no-store
response. It records the application, administrator and side in the existing audit,
never the URL, signature or document contents. The UI keeps the link in memory,
supports clipboard/manual copying and clears it on leaving that application.
Ordinary detail DTOs and preview access do not automatically disclose these links.
Generating or copying a link does not call OCR, replay processing or change a review.

Deploy matching PHP/routes and compiled admin `public/build`. No migration,
consumer H5 or native rebuild is required. Existing expiring links keep their
original expiration; copying an original or a future OCR request generates the new
permanent link without rewriting stored evidence or re-recognizing history.

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
