# PhotonPay physical cards and recipients

Approved 2026-09-24. This extends the previous virtual-only boundary for USD regular
recharge cards. It does not authorize converting existing card identities or rewriting
orders, balances, Ledger, or historical callbacks.

## Capability and application

Platform explicitly refreshes the merchant BIN catalog or a product's card types.
Saved `supported_form_factors` controls selection; ordinary product and consumer page
reads never request a BIN catalog. The adapter also checks the selected BIN and exact
form factor when issuing. Virtual is the default for omitted client fields and existing
records. Physical-only BINs default to their supported type. Both forms share product
opening fees and initial-load minimums; no shipping fee is introduced.

Each physical application retains its own cardholder materials and uppercase
FIRST/LAST card name (maximum 26 characters). Recipients are per application, not an
address book. The authenticated server creates recipients with merchant credentials;
clients cannot supply upstream recipient/member identifiers. Recipient rows bind the
tenant, user, product, merchant connection, and physical holder. Address/name/phone
material and immutable order snapshots use AES-256-GCM with a domain-separated key
derived from the existing persistent sensitive-data root. Request fingerprints are
keyed, scoped hashes, not plaintext addresses.

`addRecipient` has no documented idempotency key. A local request is recorded before
calling it. Replays return the saved attempt; unresolved attempts also block new UUIDs.
Timeouts remain UNKNOWN and are never automatically resubmitted. Only an exact
returned recipient ID can be checked using `pagingRecipient`; no fuzzy name/address
lookup is allowed. A positive exact scoped read makes the recipient READY. A browser
reload requires reviewing the saved recipient before confirming the card order.

Orders bind a READY recipient from the same tenant/user/product/holder/merchant and
copy its encrypted immutable snapshot. Database guards enforce form-factor and
recipient identity consistency. Existing issue holds, settlement, explicit-failure
release and UNKNOWN retention are unchanged. No new fulfillment money operation is
introduced. Provider detail, transactions and funding result checks use the persisted
form factor, rather than accepting any form.

## Status and activation

Card lists expose form, actual production state and actual tracking number. Unknown
status never becomes normal by default for physical cards. Unactivated cards expose
activation and transaction reads, not normal spending controls.

Activation requires ownership, active account, current password, receipt acknowledgement,
MM/YY expiry, PIN and matching PIN confirmation. The published API describes PIN as
string(8), without a numeric-only or fixed-length rule: local validation applies the
documented maximum and rejects controls; PhotonPay validates issuer-specific policy.
PIN is transient request memory only, is excluded from input flashing and redacted in
structured diagnostics. It is absent from attempts, audit, snapshots and browser
storage; component state is cleared after submission/closing/hiding the page.

The server first reads the latest card status and accepts only `unactivated`. A durable
activation attempt serializes double clicks. Acceptance alone remains UNKNOWN; only
an authoritative `normal` read marks success. Timeouts are never resent, including
with a different request ID or PIN. Explicit rejections may start a new attempt.
The explicit refresh action and existing verified-notification inline refresh can
confirm completion. No new queue, polling schedule, callback amount posting, PIN reset,
or notification replay is introduced.

## Verification and release

Offline coverage includes existing virtual issue/manage flows, physical scope and
snapshot guards, recipient UNKNOWN duplicate prevention, provider form matching,
issue failure/timeout holds, activation authentication/rejection/timeout/replay and PIN
redaction. Direct sandbox acceptance is separate from application Ledger/callback
acceptance and must be reported as such.

Deploy the additive migration `2026_09_24_120000_add_physical_cards.php` before switching
to the new backend/frontend assets. Use the existing production migration role and
normal `php artisan migrate --force`, then deploy code/assets, clear/rebuild configuration
as required and reload the serving PHP-FPM/Octane processes using the site's existing
process manager. No migration contacts PhotonPay or moves money. No destructive down
migration is supplied for physical-card history. Explicitly refresh BIN capabilities
in SaaS after deployment to enable physical options. Deploying code does not create a
recipient, card, or activation request. This implementation does not itself deploy to
production or reload remote processes.
