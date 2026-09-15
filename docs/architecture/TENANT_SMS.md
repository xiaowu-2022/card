# Company-owned Aliyun SMS registration

## SaaS profile update (2026-09-14)

SMS/email configuration now uses multiple named SaaS profiles with explicit per-company
selection and optional cross-company reuse. Per-company credential-editing statements
below are historical. Company isolation, encrypted secrets, current-password checks,
fixed providers, quotas and UNKNOWN protections remain. See PLATFORM_NOTIFICATION_PROFILES.md.


The company configuration decision extends the existing Notification contract explicitly:
`SmsVerificationSender::isAvailable(Tenant $tenant)` replaces the global no-argument check.
All implementations and registration callers use the trusted host-resolved Tenant. Runtime
uses Aliyun; Fake is restricted to automated tests. No global credential fallback exists.

## Persistence and administration

`tenant_sms_settings` is a Notification-owned, one-per-tenant configuration table with a
unique tenant FK. It stores enabled, approved signature, verification template, optional
existing-account notification template, resend interval, and code TTL. Both AccessKey fields
are Laravel encrypted casts using the persistent application encryption key, hidden from model
serialization, and never returned in props, audit or logs. Normal views only expose a configured
boolean. Both blank means retain; replacement requires both fields. No reveal endpoint exists.
The optional existing-account notice must have no variables; when absent, no SMS is sent for
an already registered phone. Browser responses remain generic and do not identify accounts.

GET/POST `/admin/settings/sms` require an active exact-tenant Admin membership and
`tenant_settings.manage`. POST additionally requires the current tenant-admin password and
is throttled. Client tenant/resource/provider/endpoint selection is forbidden. An Application
Action locks Tenant before its settings row and writes sanitized configuration audit metadata.
Credentials are never flashed to session, including validation failures. There are no seeded
SMS credentials or production test-message shortcuts; saving does not send a billable SMS.

## Sending and verification

中国站 Aliyun SendSms (`2017-05-25`) uses the fixed HTTPS endpoint
`dysmsapi.aliyuncs.com`, RPC query parameters and ACS3-HMAC-SHA256. Only a single normalized
E.164 number is accepted, converted to digits for Aliyun. International sending requires the
company's corresponding Aliyun service/signature/template approval; selecting a country does
not promise provider coverage. HTTP has bounded timeouts, no redirects and no automatic retries.
The adapter never logs HTTP requests/responses/exceptions or forwards raw provider errors.
`Code=OK` only confirms acceptance, never contact verification. OTP remains HMAC-only in
PostgreSQL; user creation still requires a locked, verified, unexpired browser-owned challenge.

SMS timing is company-specific (60–3600 seconds, resend <= TTL; defaults 60/600). Email timing
is unchanged. Existing hashed-contact and tenant/IP hourly limits also count failed sends.
The challenge is committed before HTTP. `registration_challenges.sms_delivery_uncertain` is
set before SMS sending and cleared only on a definitive result. Timeout, 5xx, malformed or
unconfirmed responses preserve it; crashes are likewise conservative. No new send occurs for
that contact while the uncertain challenge is live. Its initiating browser may reopen the same
challenge without sending; other sessions must wait. Once the authentication challenge expires,
it is expired before a genuinely new registration attempt can be created. There is no retry of
the uncertain provider operation or financial settlement. A received OTP can still verify the
original challenge. Provider rejection shows only a safe generic message. No Wallet/Ledger/Card
domain is changed by SMS configuration or delivery.

Official references:
- https://help.aliyun.com/zh/sms/developer-reference/api-dysmsapi-2017-05-25-sendsms
- https://help.aliyun.com/zh/sdk/product-overview/v3-request-structure-and-signature
- https://github.com/aliyun/alibabacloud-php-sdk/blob/master/dysmsapi-20170525/src/Dysmsapi.php
