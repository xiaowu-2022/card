On 2026-09-30 the user required image reads and public asset URL prefixes to follow
the current storage selection/configuration, including existing images. Store object
keys, not replaceable domain prefixes in business rows. Signed image gateways resolve
the current OSS config or local server at read time; current OSS failures retain the
verified server-original fallback. Previous configuration mappings remain provenance
for migration/cleanup only, not the read endpoint. A different bucket must contain
the same original objects/asset keys; changing a prefix does not copy files. Reads
never upload or rewrite business records. Changed-config processed reads verify the
original checksum before displaying a processed image.

On 2026-09-30 the user requested a Platform storage selector: server or OSS.
The storage.manage-protected selector persists media_storage_settings.storage_driver,
with locked revision checks and actor audit. A null value retains the deployment's
IMAGE_STORAGE_DRIVER until the first explicit selection; subsequent selections
override it immediately. OSS requires a saved valid configuration, not a connection
test. Mode switching does not migrate/delete originals or rewrite image mappings.
Deploy the storage-driver migration and rebuilt admin assets before using the selector.

# Server image storage (2026-09-30)

The current default is IMAGE_STORAGE_DRIVER=server (config/media.php). New uploads use the authenticated authorize / backup / complete flow but receive no OSS credentials/policy and make no OSS upload. KYC/card/support binding remains scoped and single-use. Originals are encrypted in storage/app/private/image-replicas; public artwork and compiled assets use the local public and H5 static directories. Public-assets manifests are empty in server mode, clearing remote artwork URLs on refreshed clients.

Serve /media/images/* through Laravel, not an H5 rewrite. Original image/OCR URLs are server-generated, host-bound, expire after 12 hours, and require a valid signature. OCR continues using URLs, never binary/Base64; Aliyun OCR must be able to reach this website. No actual identity submission is part of deployment.

Deploy code, public/build and public/h5 together; retain the server .env / APP_KEY / database / storage. Run php artisan migrate --force and php artisan optimize:clear, then select Local server and Save storage mode in Platform > System settings > OSS. No .env edit is required. Restart persistent workers by the existing process manager. This release requires the storage-driver migration as well as the image replica migration.

Historical local originals/replicas are read first, with checksum verification. A prior OSS snapshot can be copied as storage/app/private/oss-pull/mirror/<bucket>/<object-key> (never under public). Run php artisan images:import-server after copying it. This command contacts no OSS endpoint, creates missing encrypted copies using that server's key, and reports missing originals. Do not copy a local encrypted replica over a server replica encrypted with another APP_KEY. Unknown snapshot objects remain retained but are never bound to arbitrary business rows.

The settings page now allows saving and explicitly testing one OSS draft even in server mode. An explicit test may upload/read/delete its temporary test image, but never switches runtime storage or saves the draft. Ordinary server-mode requests still make no OSS calls.

images:replicate is a no-op in server mode. OSS originals, settings and immutable mappings remain retained; reads, new uploads and cleanup do not access OSS. Old OSS-only files absent from the server and snapshot remain unavailable until their original files are supplied. Retain snapshot and private file backups outside Git.

## Prepared local originals archive

`storage/app/private/server-image-originals-20260930.tar.gz` contains the previously downloaded 3,067 OSS objects (about 65 MB uncompressed), original bucket paths and SHA-256 manifest. It contains no APP_KEY or OSS credentials. It contains private identity images and must never be uploaded under public or committed to Git.

Upload this archive to the server outside its web root, for example `/root/server-image-originals-20260930.tar.gz`. After deploying the code:

```bash
cd /www/wwwroot/card
php artisan migrate --force
php artisan optimize:clear
mkdir -p storage/app/private
tar --skip-old-files -xzf /root/server-image-originals-20260930.tar.gz -C storage/app/private
php artisan images:import-server
```

Ensure the PHP-FPM user can read the extracted files and write image-replicas using the deployment's existing ownership convention. The import validates every mapped original and encrypts it with the server's existing APP_KEY. A nonzero missing count requires supplying those originals; it is not silently treated as a successful complete migration. The archive is ignored by Git and must be transferred separately.
