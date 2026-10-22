WebView startup compatibility fix, 2026-10-19

Target: existing consumer H5 document root (repository public/h5).
1. Back up the deployed index.html outside the public directory.
2. Upload public/h5/assets/webview-entry-25d473f841d9.js to assets/ first.
3. Verify retained assets/index-CjVTTRnC.js and assets/index-xhpijFp8.js exist.
4. Atomically replace the deployed index.html with public/h5/index.html.
5. Revalidate/purge only HTML caches if the CDN retains the old entry.
Do not delete or overwrite existing hashed assets. No PHP/config/database changes.
Existing cookie shells need no reinstall for this compatibility patch.
New Token shells must use native_auth=token-v1 and need a newly signed build.
The source/resource build is not a signed package or iPhone acceptance result.
Production publication has not been performed by this task.
