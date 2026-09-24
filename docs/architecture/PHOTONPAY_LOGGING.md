# PhotonPay diagnostic logging

Approved 2026-09-15: add diagnostic logs for PhotonPay token acquisition, issuing,
management, transaction reads and webhook handling. This is observability only;
provider identity, credentials, request bodies/signatures, transport options, token
TTL, quote expiry, money settlement, UNKNOWN handling and retries are unchanged.

## Output and correlation

The dedicated `photonpay` daily channel writes `storage/logs/photonpay-YYYY-MM-DD.log`,
keeps 14 daily files, uses file locking and 0600 permissions, and runs the existing
sensitive-context redactor. It is enabled at INFO independently of APP_DEBUG and the
main LOG_LEVEL. PHP-FPM and queue workers should use the same file owner.

`photonpay.request.started/returned/failed` cover GET, POST, document upload,
transaction pages and card management HTTP calls. `photonpay.token.*` covers token
cache retrieval and network acquisition, including a cache-hit flag. A span UUID
links each operation's start/result. The current server request UUID connects
interactive calls to API error responses. Stable connection and provider request
references are keyed HMACs, never raw external identifiers or credentials.

Logs contain allowlisted endpoint paths (without query strings), methods, integer
HTTP status, duration, page metadata, known `0000`/`VCCnnnn` codes and bounded error
classifications. Unknown error codes are represented by keyed references. No raw
provider message, exception text or stack trace is passed to the logger. A returned
request means the adapter call returned; it does not prove final financial settlement
or that all later business validation passed.

`photonpay.management.*` records the internal card action, trusted company/card/order
UUIDs and presentation state. For quotes it includes server/deadline epoch seconds,
application timezone and configured DB timezone (not a claim about an unconfigured
server default). This helps diagnose immediate expiry. The bounded `result_summary` adds exact
amount/debit/arrival/fee/balance strings, kind/state, or a history record count.

`photonpay.webhook.*` records receipt, successful verification, acknowledgement or
failure category/status. Before verified mapping it records no company from the
body or hostname. `photonpay.notification.*` records persisted event IDs and trusted
company/resource IDs, duplicate detection, dispatch failure, processing, retry or
already-processed outcomes. Acknowledgement means durable receipt, not settlement.
Logging never dispatches or replays work by itself. The 2026-09-16 execution change
below replaces the former asynchronous/scheduled inbox recovery path. Existing verifier requirements remain enforced.

## Data boundary

No PAN/CVV, access token, app ID/secret, private key, authorization/signature header,
raw request/response body, document bytes/paths, identity data, address, contact,
password or OTP is written. Only explicitly typed metadata and allowlisted business summaries are accepted. Unknown
fields and malformed identifiers are dropped. Header category values are restricted
to the existing enum; notification types are represented by keyed references.
Logging failures are caught so a disk or logger error cannot turn a completed
business operation into a failed response or trigger a duplicate financial request.
Logs are private diagnostics, never an alternative Ledger or an admin balance API.

## Response summaries (2026-09-15 follow-up)

`request.returned` and `request.failed` include `response_summary` after an HTTP
response is received. Card detail, issuance/result, recharge/quote/return and
freeze/cancel endpoints expose only known status/currency values and exact
cardBalance/rechargeAmount/arrivalAmount/rechargeFee/returnAmount/returnFeeAmount/
exchangeRate fields. A nested cardDetail receives the same filter at one level only.
JSON number lexemes are preserved as strings before decoding; no float conversion,
rounding, invented defaults or replacement balances occur. Decimal strings must fit
12 integer digits and at most 8 fractional digits; unsupported values are omitted.

List responses show record_count and bounded pageIndex/pageSize/total, never rows.
Token, document, CVV and cardholder responses expose only data shape/count metadata;
no token value or scalar data is logged. Empty responses retain zero counts where
applicable. Unknown fields, provider messages, invalid enums and nested private data
are omitted. Invalid JSON and bodies over 2 MiB are identified without logging the
body; diagnostic parsing failures never replace the business response or exception.
All summaries are revalidated at the final logging boundary. Response summaries are
provider-reported values before business validation, not proof of settlement.

For example, a synthetic recharge may emit:
```json
{"response_summary":{"rechargeAmount":"21.00","arrivalAmount":"20.00","rechargeFee":"1.00","status":"succeed","data_shape":"object"}}
```
The later internal management result may emit:
```json
{"result_summary":{"amount":"20.00000000","debit":"21.00000000","arrival":"20.00000000","fee":"1.00000000","state":"completed","kind":"load"}}
```

## Deployment and validation

Upload the logging patch's PHP files and run `php artisan config:cache`.
No database migration or frontend build is required. Reload PHP-FPM when deployment
uses persistent OPcache; restart existing queue workers through their supervisor to
load new PHP code and the inert legacy notification handler (do not replay historical jobs).

Inspect with `tail -n 100 storage/logs/photonpay-$(date +%F).log`.
The first relevant operation creates the daily file. Use the response request UUID
or internal event UUID to correlate records. Keep APP_DEBUG=false.

Offline tests cover token/cache redaction, HTTP/authentication/rate-limit/timeout
classification, unknown response codes, webhook rejection, quote expiry metadata,
exact numeric/string response amounts, safe nested card summaries, list counts,
sensitive endpoint exclusions, malformed/oversized response isolation, final-boundary
filtering, logger failure isolation and existing card/notification/tenant/financial behavior.
No live provider request or real financial operation is used for verification.

## Notification processing diagnostics (2026-09-16)

The user replaced background card polling with one inline synchronization attempt
per verified callback after persistence. `notification.persisted` records trusted
IDs, stored event status and transaction-presence metadata. `inline_started` means
processing is starting in the webhook request. `notification.processed/retry`
records the outcome; `inline_failed` catches unexpected execution failures while
preserving the durable inbox. Already-processed duplicates do not query again.
HTTP200 still acknowledges durable receipt, not guaranteed synchronization.

Processing records include attempt number, event age, duration and a bounded stage:
cancellation return, management synchronization, holder synchronization, issue
synchronization, card refresh, or event persistence. Pending management/issue and
stale holder responses have distinct reason codes. Event UUID context is inherited
by nested provider/token logs and restored in finally. External transaction
references remain keyed hashes.

`card_refresh.stage` distinguishes transaction lookup, card lookup, response
validation and cache persistence. A failed transaction lookup stops before card
lookup under the existing contract. `card_refresh.applied` is emitted only after the
outermost database transaction commits, with previous/provider/stored balance decimal
strings, refresh generation and synchronization epoch. Provider balance is a USD
card cache, not the USDT Wallet. `card_refresh.returned` alone is not commit evidence;
superseded refreshes, rejected responses and rolled-back updates never emit applied.
Refresh validation and supersession failures have separate bounded failure codes.

No new notification job is dispatched and no cards:recover schedule remains. The
legacy job is an inert compatibility handler logging `notification.legacy_job_skipped`;
old cards:recover cron calls refuse execution. RETRY is retained for diagnosis and
a future verified callback, not automatically consumed. Existing scoped Platform
manual refresh queries the selected card only; unrelated business workflows retain
their own contracts. Historical dispatch/job logs describe the former execution path.

Operators may run `php artisan cards:notification-inspect <tenant-uuid> <event-uuid>`
to read one company-scoped event and its related cached card. It reports timestamps,
attempts and inline execution mode; it neither calls a provider nor dispatches/retries
a job or changes money. No raw payload, provider identifier, digest, private materials
or credentials are printed. No database migration or frontend build is required.

## Callback validation diagnostics (2026-09-16)

A 422 `invalid_notification` from the verifier occurs after exact-body signature
verification, during JSON/object or identifier validation. It precedes inbox
persistence and provider balance lookup. Older logs cannot identify which field
failed and must not be treated as proof of any particular malformed value.

Rejections now include a bounded stage/reason, signature_verified, body_bytes,
allowlisted category and keyed notification-type reference. Identifier errors add
only a fixed field name and one of wrong_type/too_long/invalid_characters; they
never include the value, body, signature or provider message. The final logger
revalidates these fields. A missing/invalid key remains 503, a bad signature 401,
unsupported headers 422 and an unmapped verified resource 404.

The four extracted optional identifiers accept absent/null/empty-string as absent.
Nonempty values retain strict length/character validation (including rejecting
trailing newlines); whitespace, arrays and booleans are not normalized. A callback
must be a JSON object. Resource resolution still requires an existing trusted
provider card/holder/order mapping; empty identifiers cannot select a company.
No callback amount or balance is used to calculate or write funds. This fixes the
empty-optional-field compatibility case without claiming that old 422 logs prove
it was the deployed incident's cause.

Reference: PhotonPay official sandbox OpenAPI `2026-08-06_zh.json`, issuing transaction
and settlement notification schemas (`vccTransactionNotify`,
`vccTransactionSettledNotify`). Merchant requestId is not required by those schemas;
settlement examples omit it. Existing key, signature, mapping, idempotency and
inline authoritative-query contracts remain enforced. No migration or build is
needed; deploy the PHP changes and reload PHP-FPM. Verify the next delivery's
correlated logs, or use the existing explicitly scoped manual refresh. Do not
replay old callbacks or create another purchase as part of deploying this fix.

`webhook.validated` and post-signature rejection diagnostics also include
`notification_ref` (keyed exact-body digest) and `notification_fields`: each of
cardId/cardholderId/transactionId/requestId is classified as missing, null, empty,
valid, wrong_type, too_long or invalid_characters. Valid values have keyed references
for correlation; neither valid nor invalid raw values are recorded. This metadata
is computed only after signature verification and object parsing. Unknown keys,
raw values and malformed references are dropped again by the logger. The body
reference correlates retries across request UUIDs without retaining the callback.

## Complete encrypted callback evidence (user approved 2026-09-16)

The user's explicit follow-up supersedes the former no-body logging rule **only for
a separate encrypted webhook diagnostic channel**. Each callback is captured before
verification so invalid signatures, unavailable keys and malformed JSON are also
diagnosable. `photonpay-webhooks-YYYY-MM-DD.log` stores:

- request UUID, keyed body reference, byte count, schema version, cipher and key reference;
- `unverified_parameters`: validated known business values such as amounts, currencies,
  status, type, times and safe identifiers, as exact strings (never PHP floats);
- `encrypted_envelope`: authenticated AES-256-GCM encryption of the exact original
  body and X-PD-SIGN / X-PD-NOTIFICATION-CATAGORY / X-PD-NOTIFICATION-TYPE values.
  Bytes are base64-encoded **inside** the envelope to preserve invalid UTF-8 as well.

All original fields, including unknown, nested, empty and malformed values, remain
recoverable from that envelope. Sensitive fields (PAN/CVV, holder material, free text,
secrets and potentially card-like digit sequences) never appear in plaintext.
Unknown keys/values are encrypted rather than guessed safe. No request-wide header,
Authorization, cookie or session dump is collected. The visible view is explicitly
unverified diagnostic evidence, never proof of amount, ownership or settlement.

The channel uses JSON lines, 0600 files, locking and daily rotation; default retention
is seven files/days of activity and `PHOTONPAY_WEBHOOK_LOG_DAYS` is bounded to 1–14.
Rotation cleanup occurs on log writes (no new scheduled task); if traffic stops,
existing files remain until rotation or authorized filesystem retention cleanup.
Keep storage outside the web root and exclude these files from ordinary log export
and long-lived backups. Do not grant consumer/admin HTTP access to these logs.

Configure a persistent **separate** 32-byte key:

```sh
/www/server/php/84/bin/php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
```

Save the result as `PHOTONPAY_WEBHOOK_LOG_ENCRYPTION_KEY` in the site's private `.env`
(do not paste the key into support messages). Set `PHOTONPAY_WEBHOOK_LOG_DAYS=7`, run
`php artisan config:cache` and reload the site's PHP-FPM. Do not change APP_KEY.
No automatic APP_KEY fallback exists. Without a valid key, capture emits the bounded
`webhook.payload_unavailable` / `log_key_invalid` diagnostic and writes no payload;
normal notification verification and processing continue. Oversized requests beyond
the existing 2 MiB body limit are not retained; header capture is also bounded.
Encryption/storage failures log only a bounded reason and never change financial
results or acknowledge an otherwise rejected callback.

Authorized offline inspection can decrypt `encrypted_envelope` with Laravel
`Encrypter($decodedDedicatedKey, 'aes-256-gcm')->decryptString(...)`; then decode the
JSON and base64 body/header fields. Decryption is never automatic and must not write
plaintext into ordinary logs, terminal transcripts or browser responses. Preserve
old keys securely for retained evidence if explicitly rotating; key_ref identifies
the needed key. No decryption endpoint, database migration, replay or new funds
operation is introduced. Tests use synthetic secrets only and verify lossless byte
recovery, precision, authentication/tamper rejection, plaintext exclusion, key failure,
size limits, logging failure isolation and correlation with rejected HTTP callbacks.

## 2026-09-24 主动接口诊断

用户要求完整业务参数以排查渠道拒绝。PhotonPayCardProvider 的 POST/GET
在发起前保存业务参数，收到响应后在业务校验前保存原始响应字节（包含 msg、
未知字段及错误响应），通过 span_id 与普通日志关联。独立 AES-256-GCM 密文
写入 `storage/logs/photonpay-requests-YYYY-MM-DD.log`，0600 权限、每日轮换、
7 日活动文件保留。不得公开下载、自动解密或转发到普通日志平台。

配置独立 32 字节 `PHOTONPAY_REQUEST_LOG_ENCRYPTION_KEY=base64:...`，没有
有效密钥时只记录 `request.payload_unavailable`，不降级为明文、不影响业务结果。
单报文上限 2 MiB，超过上限明确记录未捕获原因。发布后更新 config:cache 并
重载 PHP 进程。不会追溯恢复旧请求，也不会重放任何业务接口。

不捕获 HTTP 认证头、Token 响应或私钥。激活请求删除 pin/pinConfirm，激活
响应不保存原文以防上游回显 PIN；现有安全状态摘要保留。证件上传记录 MIME、
大小、面别及 SHA-256，不复制图片。此接入范围是发卡适配器，后台独立连接检查
和商户报表尚不生成该完整报文日志，不能将本变更宣称为所有 HTTP 客户端全覆盖。

离线授权解密使用独立密钥与 Laravel Encrypter 的 aes-256-gcm 模式，对
`encrypted_envelope` 解密后解析 JSON，`body_base64` 解码得到业务请求或原始响应。
不要将解密后的个人资料粘贴到公开工单或普通日志。
