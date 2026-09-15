# PhotonPay sandbox acceptance — 2026-09-14

## Authorized scope

The user authorized reuse of existing PhotonPay sandbox holder
`CH2096144404451819520` for Tenant A user account `202609131303`, one card per
eligible USD/recharge/virtual BIN, with 20 USD initial funding, and sandbox
function testing. This narrowly supersedes fresh materials per card for this run.
It does not change production holder policy or permit unverified ownership,
fabricated READY applications, arbitrary wallet funding or Mock identity conversion.
Existing order idempotency, exact Ledger holds/settlement and UNKNOWN recovery apply.

## Observed provider evidence

Live read-only sandbox queries succeeded for token authentication, USD account
balance, card count, holder lookup and BIN discovery. The existing signed adapter
also successfully queried the specified holder. Reporting returned 100000 USD and
zero cards at 2026-09-14T09:31:06Z. These are provider facts, not user wallet funds.

All 11 BINs passed the corrected issuing adapter eligibility check:
367218, 522105, 52298927, 524984, 53493435, 546032, 546503, 558325, 658706,
658715, USMAB. Discover: 367218/658706/658715; the others report MasterCard.

The adapter previously required exact currency/form-factor strings. It now checks
explicit comma-separated membership for USD, recharge and virtual_card, matching
the reporting catalog. Share-only, physical-only, other-currency and missing
capability records fail closed. This is eligibility evidence, not issuance success.

## Acceptance matrix

| Capability | Live sandbox evidence | Offline coverage |
| --- | --- | --- |
| Token/account balance/card count | Passed | Reporting tests |
| Existing holder query | Passed | Adapter and issue tests |
| Eligible BIN lookup | All 11 passed | Positive and rejection membership tests |
| Holder creation/material updates | Not executed; no fresh materials supplied | Adapter and application tests |
| Open card / initial funding | Not executed | Issue, holds, settlement, replay and UNKNOWN tests |
| Reload / amount return | Not executed; no sandbox-owned application card | Management and issue tests |
| Freeze / unfreeze / cancellation | Not executed; no sandbox-owned application card | Management and issue tests |
| Sensitive reveal / transaction reads | Not executed; no sandbox-owned application card | Management and transaction tests |
| Signed notifications / reconciliation | No live callback acceptance | Signature, deduplication and recovery tests |

Offline fakes do not establish provider acceptance. No live write API was called
and no wallet or card balance was changed during these checks.

## Remaining prerequisites

The selected company/user are active, KYC approved, wallet active and deposit
100 USDT meets the 100 USDT requirement. Available wallet funds are 40.03 USDT;
eleven initial loads require 220 USDT plus platform opening fees. The user then
set the remaining BIN fees to 5 USDT each. Ten missing products were created via
the existing audited action and all eleven products configured for Tenant A
(active offering, max 20 cards, sequential sort). The existing 367218 fee remains
2 USDT; the ten new fees total 50 USDT. Batch total is 272 USDT, leaving a
231.97 USDT wallet shortfall before subsequent management tests.

The broad automated run had 252 passing tests and one failure caused by a BIN
fixture missing the now-required explicit virtual capability. The fixture was
corrected so its intended mismatched-issue-result case still reaches issuance;
focused adapter/simulator regression tests then passed. The earlier simulator
configuration test was also updated to use the SaaS actor required by current rules.

Directory products still have no real issuing runtime. Implement a verified,
tenant/user-scoped existing-holder binding and directory connection routing through
the full provider contract before executing orders. Do not change global Mock
runtime or point old cards at the sandbox connection. The requested reuse exception
has been recorded but its binding workflow is not yet implemented.

After ordinary wallet funding and explicit fee configuration, execute persisted
orders with stable request IDs, retain sanitized results, reconcile UNKNOWN before
continuing, and then exercise management against confirmed new sandbox cards.

## Scoped issuing implementation

The authorized sandbox run now has an immutable encrypted per-directory issuing
connection, accepted only in local/testing isolated databases and only for the
sandbox hostname. The global Mock driver is unchanged. Issue/recovery and card
management resolve the provider from the persisted product, preserving old routing.
The reference guard permits non-Mock sandbox identities only for that explicit
product connection, and still rejects Mock identities on the sandbox route.

`provider_cardholders.sandbox_existing_holder` records verified imported origin,
without fabricated documents. A database trigger limits reuse to the approved
holder and Tenant A account in isolated databases and prevents cross-owner reuse.
Each product still gets its own single-use application and order; deterministic
batch request UUIDs preserve replay. Existing application/order uniqueness and
Ledger boundaries remain. `cards:photonpay-sandbox-batch` verifies account and
normal holder status; `--execute` is the explicit local acceptance action.

## Final live batch result (supersedes earlier prerequisite status)

Wallet funding was observed at 10040.04 USDT before this run. All 11 eligible
BINs were attempted. Nine succeeded and were read back from PhotonPay as normal,
each with exactly 20.00000000 USD. Two were rejected with VCC3020; subsequent
`getRequestResult` returned `status=failed`. The exact upstream cause is not
established. No physical/shared substitution or further blind retry was performed.

| BIN | Result | Provider card ID | Last four | USD balance |
| --- | --- | --- | --- | --- |
| 367218 | Provider rejected | — | — | — |
| 522105 | Succeeded | XR2099434360767266816 | 5517 | 20.00 |
| 52298927 | Succeeded | XR2099434726401523712 | 1900 | 20.00 |
| 524984 | Succeeded | XR2099434755342204928 | 2727 | 20.00 |
| 53493435 | Succeeded | XR2099434776020123648 | 3063 | 20.00 |
| 546032 | Succeeded | XR2099434802813353984 | 6936 | 20.00 |
| 546503 | Succeeded | XR2099434850863300608 | 5710 | 20.00 |
| 558325 | Succeeded | XR2099434885856378880 | 6670 | 20.00 |
| 658706 | Succeeded | XR2099434924741754880 | 0707 | 20.00 |
| 658715 | Provider rejected | — | — | — |
| USMAB | Succeeded | XR2099434953904766976 | 1913 | 20.00 |

Net wallet charge: 180 USDT principal + 45 USDT opening fees = 225 USDT.
Available wallet: 9815.04 USDT. Both issuing/funding hold accounts are zero.
Tenant-scoped ledger reconciliation passed with no balance mismatch.

Failed application/order history is preserved. A corrected attempt creates a fresh
single-use application only after definitive failure; successful orders are skipped,
and an unresolved current attempt resumes the same request instead of creating one.
The sandbox adapter uses token authentication plus the existing RSA body signature,
explicit catalog scheme and exact two-decimal arrival amount. Production adapter
authentication remains unchanged. The final card/PhotonPay automated suite passed
256 tests / 2903 assertions, including existing ledger/idempotency protections.

The 11-card success target remains incomplete for 367218 and 658715 pending upstream
acceptance. Management mutations (reload/return/freeze/cancel) were not performed
on these new cards; this result certifies issuance and read-back only.

## Management acceptance authorization

The user subsequently authorized CVV/transaction/reload/return/cancellation wiring,
30 USD arrival recharge and 25 USD card return on each of the nine sandbox cards,
and explicitly selected cancellation of only one card, retaining the other eight.
The deterministic cancellation target is USMAB / XR2099434953904766976. Existing
holder-edit UI is hidden; its backend ownership protections remain unchanged.
`cards:test-photonpay-management --execute --cancel-one` uses existing persisted
management orders and Ledger actions, stable per-card/kind request IDs, and only
verified sandbox product routing. It does not print or store PAN/CVV. Unknown
operations stop the run and are synchronized using the same order. The ordinary
consumer CVV/password and money-confirmation requirements remain intact.

### Management live results

All nine cards passed CVV format/last-four verification without returning sensitive
values to logs or reports. Each received 30 USD and returned 25 USD gross; all
18 persisted operations are SUCCEEDED. Each card's live transaction list was read
successfully. The eight retained cards each have 25 USD. USMAB (last four 1913)
was cancelled once, confirmed cancelled/zero, and its separate provider-verified
25 USD cancellation return was credited once through the existing Ledger action.

| Flow | Count | Wallet debit / card debit | Arrival | Provider fee |
| --- | --- | --- | --- | --- |
| Recharge | 9 | 284.10 USDT wallet | 270 USD cards | 14.10 USDT |
| Amount return | 9 | 225 USD cards | 210.90 USDT wallet | 14.10 USD |
| Cancellation return | 1 | 25 USD card | 25 USDT wallet | 0 |

Wallet available after acceptance is 9766.84 USDT. Tenant ledger reconciliation
passed. Financial tests used actual quotes and net returns, never an assumed fee.
CVV/transaction/management regression: 122 passed / 1019 assertions; additional
currency-evidence/token-cache tests: 19 passed / 54 assertions. TypeScript/ESLint
passed. User UI verified with live transaction rows; edit is hidden and recharge /
return dialogs default to 30 / 25 respectively while retaining explicit confirmation.

Two adapter corrections were necessary: encrypted short-lived token reuse across
instances (avoiding repeated auth requests), and support for transaction rows omitting
cardCurrency. Missing currency is accepted only after querying the exact card detail;
explicit conflicting currency, foreign card ID, type or form factor still fail closed.
Provider money parameters use exact two-decimal strings; financial storage remains
NUMERIC(20,8). No production credentials, Mock identities or historical orders changed.
