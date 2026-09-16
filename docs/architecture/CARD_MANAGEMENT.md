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
or Host. Duplicate callbacks acknowledge without new financial actions. Jobs carry
explicit trusted tenant and resource IDs. Persist before acknowledging `roger: true`.

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
Run the queue worker and Laravel scheduler; `cards:recover --tenant=<tenant_uuid>`
performs trusted re-queries, never resends money/state mutations. Without `--tenant`
it deliberately iterates all tenants including suspended ones for paid settlement.
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
