# Security Rules

Tenant host resolution and membership scope are server-authoritative. Client-provided tenant identifiers are ignored. Authorization is enforced by backend policy/permission checks; hidden UI controls are only UX. Platform Admin is isolated on its own host and user/admin identity stores are separate.

`SensitiveDataRedactor` covers case-insensitive and nested password/password confirmation, OTP, identity number/document, API key, secret, token, authorization, cookie/set-cookie, PAN, and CVV fields. Bearer credentials are also removed from otherwise safe log strings. Application code uses the configured redacted channels and does not log request bodies in sensitive flows. Logs should include request_id and trusted tenant_id/actor_id when known. APIs use explicit DTOs/Resources with allowlisted fields and a consistent error envelope containing code, human message, request_id, and safe details.

PAN/CVV never enter ordinary persistence or logs. Provider secrets are never plaintext and cannot be re-revealed. KYC documents use private storage. Future identity numbers are encrypted for retrieval plus keyed-HMAC hashed for per-tenant duplicate checks; OCR is not KYC approval.

Audit history is append-only by Application contract and distinct from immutable Ledger history, provider operations, and application logs. Eloquent update/delete guards provide defense in depth but are not claimed to make the database tamper-proof; direct database access remains an operationally privileged boundary. Destructive cascades are avoided for history. Users and tenants transition status rather than being deleted; later erasure/anonymization must never cascade into Ledger history.

Admin models reserve encrypted 2FA secret/recovery fields. Before production, 2FA policy should require PLATFORM_OWNER, TENANT_OWNER, KYC_REVIEWER, and CARD_OPERATOR (and any equivalently sensitive role). Invitations store only token hashes; Platform invites Tenant Owner, who chooses their own password and security factors.
