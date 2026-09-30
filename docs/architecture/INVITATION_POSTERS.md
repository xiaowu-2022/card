# Invitation posters

Approved 2026-09-19. Platform company Promotion settings accepts JPG/PNG/WebP
backgrounds up to 8 MiB, 300–4000 pixels wide and 300–6000 pixels high. Active
Platform tenant.manage authority is checked before upload and during the locked
company update. Paths stay private; reads are authenticated and company-scoped.
Configuration changes retain actor/time audit and do not alter promotion or money.

The consumer Promotion hub renders a PNG locally from the company's background,
the existing current-origin registration link. Only the QR code is composited in
the reserved bottom center of the artwork, preserving the image dimensions. Its
white quiet zone remains; no invitation-code text, caption or footer panel is added.
The save button is centered. This supersedes the earlier panel layout (2026-09-19).
No configured background uses the built-in neutral invitation design. Preview,
download and phone long-press saving are available; no external image service or
sharing request is made. Background load failures never produce a misleading
replacement poster. Reopening retries generation.

Verification: tests/Feature/InvitationPosterTest.php covers upload, audit,
company-scoped reads, rejected formats and Platform authority. Frontend typecheck,
i18n checks and build apply. Default PNG generation verified in local browser.

## 2026-09-30 Storage read recovery

Platform previews and consumer poster DTOs always reference their existing authenticated
company-scoped image routes, including in OSS mode. Generating page data performs no
remote reads. Image GET resolves the current storage configuration via ImageStorage:
OSS processing is preferred, with checksum-verified encrypted server-original fallback
for pending uploads or read/processing failures. Changed configurations retain the
existing original-checksum gate. This also avoids cross-origin canvas image access.
GET does not upload, repair, rewrite mappings or replay business operations. Existing
posters work without re-upload; backend deployment only, no migration or asset rebuild.
