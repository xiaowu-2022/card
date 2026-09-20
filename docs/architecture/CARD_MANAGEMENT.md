# PhotonPay Card management extension

The user explicitly authorized extending Phase 10 to the complete lifecycle of the
existing regular virtual USD card: holder updates, CVV, recharge, amount return,
freeze/unfreeze, cancellation and authenticated issuing notifications. Older
exclusions in CARD_RULES, CARD_PROVIDER_RULES and PHOTONPAY_INTEGRATION_RULES are
historical for these operations only. No deposit refund, commission, physical/share
product, local FX engine, direct balance setter or administrator override is added.

## Verified provider contract

Source: https://api-doc.photonpay.com/data/2026-08-06_zh.json (2026-08-06).
Basic authorization and MD5withRSA over the exact request body remain documented.
Webhook verification uses the separate PhotonPay public key, never our private key.

- `getCvv`: cardId, transient cardNo/cvv/expirationDate; discard after reveal.
- `preRecharge`: stable requestId, server accountId/cardId and requested arrivalAmount.
  `recharge` sends the saved requestId, not an invented direct amount payload.
- `rechargeReturn`: stable requestId/cardId/returnAmount; credit only verified net
  arrivalAmount, not gross requested amount. Fees are provider facts, not estimates.
- `cancelCard`: only cardId is documented. Persist one durable local intent and do
  not blindly retry on timeout. PhotonPay may automatically return remaining funds;
  those require separately verified `discard_recharge_return` transaction evidence.
- `freezeCard`: requestId/cardId/status freeze or unfreeze; getRequestResult supports
  card_freeze. Card status query is the authoritative final state for cancellation.
- `editCardholder`: only changed fields. Approved names may be immutable at PhotonPay;
  provider rejection is shown safely. Updating a holder is not reassigning the Card.
- `pagingVccTradeOrder` filters by cardId plus requestId/transactionId for recovery.
  `getRequestResult` must never pretend to query recharge/return operations.

## Money and operations

`card_management_orders` is the tenant/user/card-scoped idempotent aggregate with
immutable request facts and monetary snapshots. Reload quotes precede explicit user
confirmation. Continue the existing 1:1 USDT wallet / USD card settlement convention;
require USD provider accounts, fees and exchange rate 1, with exact decimal strings.
No additional local service fee is introduced. A quote itself cannot move money.
Quoted debit, arrival and provider fee must reconcile exactly before a hold.

On confirmation commit the order and exact USER_AVAILABLE -> USER_CARD_FUNDING_HOLD
posting, then call externally, then settle the hold to TENANT_CARD_FUNDING_CLEARING
on verified success or release only definitive failure. UNKNOWN retains the hold.
Return credits move net verified amount from TENANT_CARD_FUNDING_CLEARING to
USER_AVAILABLE once. No browser-supplied result, webhook amount or balance difference
can credit a wallet. Terminal ledger paths have deterministic order event keys.
One outstanding modifying operation per card prevents conflicting requests/new UUID
bypasses. Card cancellation is irreversible and requires explicit warning/password.

## Notifications and sensitive data

`card_provider_events` stores only allowlisted routing fields and a digest, never
raw bodies/PAN/CVV/holder materials. Verify X-PD-SIGN over untouched raw bytes first;
resolve tenant from the persisted PHOTONPAY card/holder/order mapping, not body tenant
or Host. After the inbox transaction commits, execute one synchronization attempt
inline using the trusted tenant/event IDs, then acknowledge `roger: true`. No queue
job is dispatched. Already-processed duplicate callbacks do not query again. Failed
reads preserve RETRY for inspection or a later verified delivery, without automatic
retries; receipt acknowledgement is not a claim of successful balance synchronization.

Received consumption/settlement/status notifications trigger provider re-query.
`card_transactions` is an allowlisted tenant/card-scoped provider read model. Card
balance remains a timestamped provider cache, never a local spend ledger. Serialize
refresh generations so late HTTP responses cannot replace newer refreshes. Do not
subtract consumption manually: authorizations, reversals, fees, duplicates and
out-of-order notifications make that incorrect. Failed refreshes retain old data
with its old timestamp and remain eligible for reconciliation.
Holder notifications likewise require a fresh provider read before marking the
inbox event processed; a preserved last-known READY state after timeout is not proof.

Reveal requires the exact logged-in owner and current password, a POST with CSRF,
private/no-store response and rate limiting. Only transient component memory holds
card number and CVV; close, timeout, navigation and hidden-page events clear them. Never put sensitive
values in Inertia props/history, session, logs, audit, ordinary tables or jobs.

## Deployment acceptance

Apply migration `2026_09_11_000500_create_card_management_tables.php`. The existing
Ledger account types are reused; no new balance table or generic adjustment exists.
`card_provider_events` resolves exactly one local card, holder, or issue order.
Composite foreign keys and immutable routing facts prevent cross-tenant attachment.
All HTTP user management goes through POST `/cards/{local_uuid}/management` under
the tenant/user guard and CSRF. Mutation/reveal requires the current password;
money/state/holder changes additionally require explicit confirmation.

Configure PhotonPay's callback as `https://<public-host>/webhooks/card-provider`
and set `PHOTONPAY_WEBHOOK_PUBLIC_KEY` to PhotonPay's notification verification
public key (PEM, escaped newlines supported). This is not our outbound signing key.
The route is host-independent because provider mappings resolve tenant scope.
As approved on 2026-09-16, PhotonPay notification synchronization needs neither a
queue worker nor a scheduler. Remove any server cron entry for `cards:recover`; that
command now refuses execution without querying providers. Pre-deployment serialized
notification jobs are inert compatibility handlers. Restart any existing long-lived
workers after deployment to load that handler; do not replay historical jobs. The
Platform card list retains its scoped, permission-checked manual refresh button.
No periodic card polling, balance scanning or automatic notification recovery is
registered. Other business schedules, including user-authorized timed deposit
refunds, retain their own contracts.
Inspect RETRY inbox rows and UNKNOWN orders operationally; never force success or
release a hold manually. Holder edit recovery compares only encrypted requested
changes with provider read-back, never replays an unconfirmed edit. Original opening
materials remain an immutable snapshot; updated values are encrypted on the order.

Locks are Tenant -> User -> Card -> management order -> LedgerWriter's event and
sorted account locks. The card row is the shared business aggregate lock; only the
writer locks Ledger accounts. Call PhotonPay between committed intent and settlement
transactions, never while these locks are held. Cancellation-return settlement also
uses the card lock and a globally unique provider transaction ID.

The public regular virtual card UI supports email, phone, birth date, nationality
and billing-address changes. It deliberately excludes approved name changes and
replacement of the holder identity/documents. The document issuing country follows
nationality. Physical-card reassignment, wallets/tokenization and other card products
are separate capabilities, not invented virtual-card actions.

The user-requested existing-card edit prefill (2026-09-13) reads editable saved
fields through the owner-scoped POST management action `holder_details`, with
CSRF, active account/provider gates, throttling and private/no-store headers.
It is a read-only, allowlisted response, never ordinary Inertia props or an Admin
material browser. Only original encrypted cardholder fields plus SUCCEEDED
encrypted holder-edit orders for the same Tenant/User/holder are projected;
UNKNOWN/FAILED edits cannot replace confirmed information. It makes no Provider
call and does not rewrite the immutable opening snapshot. Legacy missing material
fails explicitly rather than borrowing account KYC or fabricating values.
Names, identity numbers, documents, object keys and Provider IDs are excluded.
The edit form keeps this data only in transient component memory and clears it
on close, hidden-page events and unmount; stale reads cannot repopulate a closed
form. Only edited fields (plus required phone/address validation groups) are
submitted with the existing password, explicit confirmation and idempotency key.
No financial state, schema, holder identity or mutation permissions change.

Real production credentials, PhotonPay notification public key and a public HTTPS
callback URL must be configured outside chat. Automated fixtures may test adapters
and accounting but cannot prove a live merchant is enabled or a webhook is reachable.
Never spend, return or cancel a real card as part of automated verification.

The six implemented core card-management entries remain visible below owned cards.
Only server-returned capabilities enable them; unsupported/test cards, unavailable
service and ineligible card states show disabled controls with an explanation.
This is availability presentation for implemented flows, not mock functionality or
permission to bypass backend ownership, provider or financial gates. Holder editing
still edits the existing holder and must not imply reassignment to another person.

### SaaS card balance visibility (2026-09-14)

The Platform card list displays the last provider-confirmed decimal USD balance
and its synchronization timestamp. An active Platform member with `cards.read`
may refresh one persisted company/card through the scoped refresh route (throttled).
The action derives user ownership from the selected company/card and uses the
existing product-specific provider/read-model refresh. It never changes Ledger or
issues a provider financial mutation. Failed refresh preserves the previous balance
and timestamp and reports an unavailable refresh; null is never displayed as zero.

## Card information reveal and copy (2026-09-15)

The user replaced the CVV-only entry with View card information and authorized
showing the full card number and CVV, with separate copy and copy-all controls.
The existing password-verified, tenant/user-scoped, no-store reveal POST returns
only pan and cvv after validating a numeric PAN and its last four digits against
the owned card. No ordinary props, database record, audit payload or log receives
these values. The existing metadata-only CARD_CVV_VIEWED event remains compatible.
Both values share transient React state and clear on close, page hide, navigation
and after 30 seconds. Copy occurs only on an explicit click; copy-all contains
the unformatted card number and CVV on separate labelled lines. Clipboard errors
show a translated manual-copy instruction without including values or raw errors.
The screen states that copied data remains in the clipboard; the application
never silently overwrites unrelated clipboard content. Provider/financial flows
and password checks are unchanged.

## Single-submit card reload (2026-09-15)

The user explicitly removed the second reload confirmation, including the repeated
password and confirmation checkbox. The initial Reload click obtains a server/provider
quote and confirms that same persisted LOAD order in sequence. This narrowly
supersedes the password and explicit second-confirmation requirements for LOAD
confirmation only; reveal and other management mutations retain their checks.
No new endpoint, provider operation, money formula, order state or Ledger path is
introduced. The existing confirm action accepts only a tenant/user/card-scoped LOAD
order and preserves quote expiry, exact debit/arrival/fee, eligibility and balance
checks. Replay of the same order cannot post or call the recharge provider twice.
The UI retains the order as awaiting confirmation before the confirm request so a
lost response offers status querying rather than a new charge. Provider UNKNOWN
still retains its hold and must be reconciled with the same request identity.
No live recharge is performed merely to test this UI change.

## Diagnostic logs (2026-09-15)

PhotonPay interfaces, quote confirmation and notifications use the private daily
logging channel described in [PHOTONPAY_LOGGING.md](PHOTONPAY_LOGGING.md). Logs
contain bounded metadata only; transport, financial and retry contracts are unchanged.

### Notification public-key compatibility (2026-09-16)

An operator-supplied PhotonPay notification public key was a parseable RSA-1024
SPKI PEM; the verifier's previous hard-coded 2048-bit floor rejected it with
CARD_WEBHOOK_UNAVAILABLE before signature verification. Notification verification
now accepts configured RSA public keys of at least 1024 bits, including RSA-2048.
This compatibility change is confined to incoming PhotonPay notifications; it does
not change merchant key generation or request signing. The documented MD5withRSA
check over exact raw bytes is still mandatory, with no unsigned fallback. Missing,
malformed, non-RSA and smaller keys fail with 503; invalid signatures fail with 401.
Actual and literal-escaped newlines are supported. A literal backslash before the
PEM END marker is malformed and must be corrected in configuration, not silently
stripped. No key material is logged or committed as a deployment default.

Contract rechecked against the sandbox documentation's signing/notification sections:
https://api-doc.sandbox.photontech.cc/data/2026-08-06_zh.json . That document directs
merchants to obtain the platform verification key from developer settings and does
not prescribe the previous 2048-bit notification-key minimum. Local generated-key
tests establish parsing/signature compatibility, not the authenticity of an operator's
key or successful live callback delivery. Deploy the verifier change, correct the
server PEM if needed, rebuild configuration cache and reload PHP-FPM before acceptance.

## Frozen card presentation (2026-09-17)

Frozen cards use a gray card face and a localized Frozen badge (冻结 in Chinese).
Their primary action row exposes Unfreeze in place of Reload, through the existing
password/confirmation flow and server-returned capability checks. More omits this
duplicate action. The generic disabled-actions explanation below cards is removed.
Refund-locked cards retain transaction-only controls and their specific explanation.
This changes presentation only; provider status and all mutation gates remain authoritative.

## Unverified application entry (2026-09-17)

The application intro omits the generic product-requirements sentence. Signed-in
unverified users immediately see a verification dialog when entering Cards, with
a Verify identity (去实名) link to `/kyc`. Dismissing via Cancel, close, Escape or
outside click returns to `/dashboard` with history replacement. The intro retains
its Verify identity button; there is no bottom-of-page KYC banner. Server KYC and card-application gates remain.

### Per-card local balance ceiling (2026-09-19)

User approved a Platform-only, per-card nullable USD balance ceiling. Blank means
unlimited; zero blocks new loads. Active Platform `card_product.manage` sets it
from Cards, with exact tenant/card scope and append-only actor/time audit. A
pending QUOTING/QUOTED/PROCESSING/UNKNOWN operation blocks changes. Lowering the
ceiling never withdraws money or changes the provider balance. This is a local
reload policy, not a provider-side spending limit; external refunds/adjustments
may increase the balance beyond it.

New capped LOAD requests refresh the provider balance, serialize creation using
the existing ownership locks and outstanding-operation guard, and snapshot:
requested principal, actual principal, overflow and ceiling. Actual principal is
min(requested, ceiling minus confirmed balance), rounded down to cents. No room,
unconfirmed balance, or room below the product minimum fails before a provider
quote or Ledger hold. Confirmation refreshes again and expires a quote that no
longer fits. A completed racing operation after the initial read began requires
a new balance read rather than trusting that stale read.

Provider quotes and fees use actual principal only; the existing Ledger flow
holds/settles actual debit (arrival plus provider fee). Overflow is an uncharged
request remainder, not funds held, revenue or a new balance. It stays in the
wallet and is not automatically sent later. Example: ceiling 500, card balance
400, request 500 -> actual arrival 100, overflow 400, wallet debit 100 plus quoted
fee. Consumer orders/balances retain actual amounts. Only Platform/company admin
reload-order lists expose requested principal and overflow. Snapshots are
immutable in PostgreSQL; retries use the original requested-amount fingerprint,
UNKNOWN retains only the actual hold, and failed loads release only that hold.
Existing orders and Ledger history are untouched. This approval changes load
sizing, not the exact provider-evidence or Ledger settlement contracts above.

No product-wide default was applied; limits are independently set per existing
card. No real provider financial tests or automatic balance changes are authorized.


## Product default balance limit (2026-09-19)

SaaS create/edit product includes nullable `balance_limit` in USD, nonnegative exact
cents. Existing and new cards without an individual override dynamically inherit the
product value on new reload quotes; per-card values take precedence. Clearing the
single-card input restores product inheritance. Both null means unlimited. Platform
card listings show the effective limit. Product configuration audit includes the
before/after limit, and changes never move money, alter confirmed balances, or rewrite
existing order snapshots. Lowering a limit below an existing balance prevents further
loads until capacity exists; the provider is not asked to withdraw excess funds.


## Platform overflow reporting (2026-09-19)

The platform funds overview includes total and daily card reload overflow (USD),
scoped by the existing persisted company filters and UTC+8 calendar date boundaries.
Only SUCCEEDED LOAD orders with a matched same-tenant settlement Ledger entry count;
the immutable entry creation time supplies the settlement date. Null historical
splits count as zero. The cards.read permission gates this series independently.
Overflow is uncharged wallet money, so inflow, outflow and net remain unchanged.
Reporting only reads orders and Ledger; it never calls providers or moves funds.


## Voiding unsubmitted reload quotes (2026-09-19)

Platform card_product.manage may explicitly void a scoped LOAD in QUOTING or
QUOTED only when no provider_called_at, hold, settlement or release entry exists.
Lock tenant, user, card and order, then transition to EXPIRED with an append-only
CARD_LOAD_QUOTE_VOIDED administrator audit. Replays are inert. Late quote responses
cannot resurrect it; confirmation and voiding serialize on the same card/order.
PROCESSING/UNKNOWN and any funds-bearing order are never force-cancelled. This
operation neither calls the provider nor changes Ledger. Platform load tables expose
a void button only for eligible quotes, with translated status labels.


## External-channel recharge funding (2026-09-19, supersedes uncharged overflow)

User explicitly requires all requested recharge principal to be debited, with
provider arrival and overflow funded through their externally monitored channel
recorded separately. They declined manual receipt registration and requested local
success for external funding without a provider recharge response. We do not implement
or assert delivery by that external monitor, invent provider transaction IDs, or
increase confirmed card balances locally.

New immutable manual_funding_amount equals overflow_amount and represents charged
external-channel principal. Existing records receive zero for this new captured
funded amount: historical uncharged overflow and immutable economics are not rewritten.
The successful LOAD equation is debit = provider arrival + fee + manual funding;
RETURN/CANCEL_RETURN still use debit = arrival + fee. Null arithmetic cannot bypass
validation. Ledger hold/settle/release continue posting the complete debit through
LedgerWriter and the existing recharge clearing account; the order provides the
channel allocation. A provider quote/result is checked against debit minus external
funding. A provider failure releases the complete hold; UNKNOWN remains unresolved.

When automatic capacity is zero or below the provider minimum, the whole requested
principal is external funding. Quotes remain nonfinancial. Confirmation atomically
posts hold and settlement and marks local success, with no provider write or invented
transaction. Replays do not post again; insufficient funds roll back everything.
The original requested minimum and all eligibility checks remain. Admin displays
provider arrival, external funding and total wallet debit separately. Historical
external payments are not replayed; no live financial testing is authorized.


## Independent spendable overflow (2026-09-19, latest clarification)

The user confirmed provider balance and transaction responses exclude external-channel
money. Each card therefore owns one nonnegative USDT USER_CARD_OVERFLOW Ledger account,
linked immutably to that card, tenant, user and USDT wallet. The displayed USD available
balance is the confirmed provider balance plus this balance at the approved 1:1 basis.
An unavailable provider balance remains unavailable, never assumed zero.

A successful LOAD allocates its manual_funding_amount from TENANT_CARD_FUNDING_CLEARING
to the card account in the same transaction as full wallet settlement, through LedgerWriter.
The immutable card_overflow_movements row and database deferred evidence validation bind
this allocation to exactly one successful, settled source order. Failure or UNKNOWN never
credits overflow. Retries cannot allocate it twice. Migration changes schema only.

Reload capacity uses the combined available balance. Capacity below minimum_reload
(including negative or zero capacity) makes the entire principal external; exactly the
minimum remains eligible for provider funding. Fees apply only as actually quoted.

SaaS card_product.manage exposes Record overflow consumption. This is an explicit record
of an already-completed external spend, not a request to the provider. It refreshes the
provider balance and requires it to be zero before deducting overflow; it cannot represent
unconfirmed provider spending or arbitrarily decrement the provider read model. The user
must acknowledge occurrence and provide a reference/note. Amount, stable request UUID,
operator identity and server record time are immutable. Insufficient overflow, request
conflicts, unresolved financial orders and unauthorized/cross-tenant requests fail closed.
The debit posts back to TENANT_CARD_FUNDING_CLEARING; no wallet debit occurs again.

Consumer histories merge settled external/mixed recharge principal and overflow purchases
with persisted provider transactions, paginated together. Exact provider transaction IDs
are deduplicated against mixed recharge orders; amounts alone never match. Recorded
SaaS operator and notes appear only in the SaaS transaction view. Refreshing provider
balances/transactions cannot debit, erase or double-credit overflow. Card cancellation is
blocked while overflow remains; no implicit return or generic adjustment flow is introduced.
