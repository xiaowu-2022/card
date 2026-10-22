WebView login session fix (2026-10-19)

For installed Token shell 2.6.12. H5 update only; no APK reinstall or PHP/schema changes.
Back up the current deployed H5 index.html outside the public directory.
Upload all public/h5/assets files FIRST, preserving existing hashed assets.
Then atomically replace public/h5/index.html and build-manifest.json.
Refresh only HTML CDN/browser caches if necessary.

The previous startup-entry-only patch does not contain this fix.
After deployment, fully close and reopen the App, sign in, check the home page and
background/foreground return. Explicit logout and revoked tokens still return to login.
SHA256SUMS.json lists exact deployment file hashes.
Do not replace a signed APK with this archive. Device acceptance is still pending.
