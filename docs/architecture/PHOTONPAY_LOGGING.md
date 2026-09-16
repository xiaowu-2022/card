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
