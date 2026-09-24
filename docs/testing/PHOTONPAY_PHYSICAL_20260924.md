# PhotonPay physical-card implementation acceptance — 2026-09-24

## Scope

Sandbox only: `https://x-api.sandbox.photontech.cc`, BIN `53493435`, USD recharge,
physical form, 20.00 USD initial arrival. Direct HTTP uses the current local `.env`
credentials after the user's key update. Synthetic recipient and previously approved
sandbox holder only. No production requests, historical replay, manual card rebinding,
application Ledger postings or card cancellation were performed by this direct test.

API contract: [PhotonPay sandbox documentation](https://api-doc.sandbox.photontech.cc/),
2026-08-06 Chinese OpenAPI document, recipient/openCard/activateCard operations.

## Direct sandbox result (04:24–04:29 UTC)

| Operation | Result |
| --- | --- |
| Token / USD funding account / BIN lookup | Success. BIN explicitly returns `virtual_card,physical_card`, USD, recharge. |
| Exact existing holder lookup | Holder `CH…9917312`, normal, review approved. |
| Initial signed edit attempt | 401 / 1001 / invalid sign; user updated local configuration; subsequent signature accepted. |
| Add card-face abbreviation to old holder | `VCC3041`: issuer permits billing edits but refuses this holder-info change. No change claimed. |
| `addRecipient` | `0000`; synthetic recipient `RI…2387840` created and retained. |
| `pagingRecipient` by exact ID and merchant | `0000`, exactly one matching recipient, status normal. |
| `openCard` physical / 20 USD | `VCC1002`, no card ID returned. Request reference `759c3bfb…3250`. |
| `getRequestResult` for that request | `0000`, status **failed**, no card detail. A second read also confirmed failed. |
| Funding account balance | 99,990.00 USD before and after; change 0.00 USD. |
| Activation | Not attempted: no created card, eligible card status or expiry. No test PIN was generated. |

The sandbox physical card was **not created**. The retained recipient can be used for
follow-up; do not blindly create another recipient. VCC1002's specific business reason
was not established by the result query (only failed was returned). The separate holder
edit rejection is confirmed, but is not asserted to be the cause of VCC1002. Resolving
this issuing rejection requires PhotonPay diagnosis or an explicitly revised test scope.
No successful creation, activation, system Ledger or live callback acceptance is claimed.
Private local attempt logs retain the complete request/recipient identifiers for exact
follow-up; this report intentionally contains shortened identifiers and no credentials,
PAN, CVV, PIN, or recipient address.

## Offline checks

- PHP: **297 tests passed, 2,097 assertions** across issue/manage, products/BINs,
  PhotonPay adapters, physical cards, transactions, notification verification and logging.
- Covered physical recipient scope/snapshot immutability, UNKNOWN duplicate prevention,
  rejected/unknown issue holds, sensitive authentication, activation rejection/timeout/
  duplicate suppression, authoritative completion, physical callback refresh without
  Ledger mutation, physical transaction form checks and saved capability reads.
- TypeScript type check, changed-file ESLint, PHP Pint and Vite production build passed.
  Vite reports its existing large-bundle advisory.
- Frontend localization suite: **72 passed, 3 failed**. The three promotion fixture
  failures (`rankOptions`, membership actions and highest-enabled-rank props) also fail
  against HEAD source before these changes. Physical-card translations pass.
- Browser checks used real built React components with synthetic Inertia props and
  mocked HTTP, not production user sessions: virtual/physical selection, recipient
  fields, confirmation with shipping summary/fees, awaiting-activation display and
  activation form, PIN clearing on close, and no horizontal overflow at 390 px.
  Screenshots were visually inspected. This is UI acceptance, not end-to-end live issuing.

## Deployment status

Code and migration are prepared locally; production was not deployed or restarted.
See [physical-card architecture and release order](../architecture/PHOTONPAY_PHYSICAL_CARDS.md).
Migrate before switching code/assets; reload the serving PHP processes after deployment;
explicitly refresh saved BIN capabilities in SaaS. Migration never calls PhotonPay or
moves funds. Old omitted form-factor inputs default to virtual.
