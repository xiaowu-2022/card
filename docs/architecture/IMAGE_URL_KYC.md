# Image URL KYC (2026-10-01)

The user replaced Aliyun and OCR.Space with the fixed endpoint
`http://202.95.12.185:9601/ocr`. Laravel sends one GET with an `image` query
parameter containing its own generated front-original URL. Query encoding preserves
the complete signed URL. This user-selected HTTP endpoint requires no API key.
There are no redirects, retries, binary uploads, alternate services or caller-selected
endpoints. Connect timeout is 10 seconds and request timeout is 120 seconds.

The accepted JSON response is `{"texts":["recognized line", "another line"]}`.
Only string entries are parsed; failures and malformed responses fail closed.
National IDs require one distinct 18-character number with a valid birth date and
checksum. Whitespace and letter case are normalized without guessing characters.
Name, address, ethnicity and back-side authority/validity are not gates or retained
OCR text. Both national-ID images are still required. Existing passport labelled
number / TD3 MRZ parsing uses the same front-only service.

## Immediate number preview

Uni-app uploads the front when selected, requests a verified front ticket using
`recognize_front=true`, and calls authenticated POST `/client/kyc/recognize-front`.
The server checks active tenant/user and KYC submission eligibility, upload owner,
purpose, side, readiness, expiry, unclaimed state and original checksum. It generates
the original URL itself; caller image URLs and numbers cannot affect recognition.
Preview requests are throttled and concurrent calls for an upload are locked.

Only the validated number is returned, with `Cache-Control: private, no-store`.
The frontend displays it read-only and keeps it only in page memory. It clears the
result on replacement, document-context changes and page exit, discards stale async
responses and blocks submit before recognition succeeds. Back-side selection makes
no OCR call. Submission sends the front upload ID, not the number, and uploads the
back through the existing image contract.

`direct_image_uploads.kyc_ocr_evidence_encrypted` contains the number, document type,
country, provider, upload ID, tenant, user and original checksum, encrypted with the
existing KYC data key. It is hidden from model serialization. The existing 15-minute
upload expiry also bounds preview use. Repeated previews reuse valid evidence.
Submission validates the binding before claiming the front and uses that result
without another OCR call. Wrong context, corrupt evidence, expired or claimed upload
fails closed. Existing clients without preview still recognize the front on submit.

Preview creates no KYC application or approved identity. Final submission retains
the existing tenant identity-account locks, automatic/manual review, immutable
approved history and encrypted identity/evidence. No historical re-recognition or
identity rewrite occurs. Logs contain only fixed phases/reasons and HTTP status;
the OCR text, number and original URL never enter diagnostic logs.

## Deployment

Deploy all backend changes and the new uni-app H5 build together. Before enabling
the new UI, run the migration and rebuild configuration/routes using the site's PHP:

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
its PHP workers. Native apps require their normal separate resource build/package
release. OCR must be able to fetch the generated original from the current image
host. Changing OCR service does not move originals to the service's sample Uploads
directory. No real identity submissions or financial operations are used for tests.
