# OSS connection verification — 2026-09-28

User requested a real test of the saved local OSS configuration after the test
button appeared unresponsive. No credentials were printed or copied into artifacts.

- Saved region `beijing` produced OSS HTTP 400 / `InvalidArgument` on a read-only
  Bucket-info probe. An in-memory correction to `cn-beijing` plus regional API
  endpoint succeeded using the same encrypted credentials.
- Created a corrected immutable configuration version through `OssSettings::save`,
  preserving the original version and credential encryption. Public image domain
  and Bucket were unchanged. Did not enable the version or migrate historical files.
- Both service and UI connection tests passed: synthetic 1px PNG upload, signed
  read with SHA-256 comparison, anonymous public URL read with comparison, delete.
  Only random `connection-tests/` keys were used, and test images were removed.
  This verifies OSS integration, not OCR or real document submission.
- Fixed the missing UI feedback: row-local progress, success, server errors and
  network errors. Added explicit region/official-endpoint matching validation on
  save and check, including bucket-hosted endpoints, and a region placeholder.
- Browser verified the old version now shows the specific Chinese region error,
  and the corrected version shows progress then success. Original user tab/form
  was preserved; refresh it to see the new version.
- OssImagesTest: 18 tests / 80 assertions passed. TypeScript, targeted ESLint,
  React build and diff whitespace checks passed.

Evidence: `artifacts/oss-20260928/connection-verified.png`. No credential, authorization
header, provider response body or private image content is included.
