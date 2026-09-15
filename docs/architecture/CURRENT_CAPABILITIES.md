# Current effective capabilities

2026-09-15: [Unified site deployment](UNIFIED_SITE_DEPLOYMENT.md) now supersedes the earlier split-data deployment plan. The user explicitly retains all existing records in one site/database. Directory routing preserves original provider connections across deployment locations; migration 59 removes database-name restrictions only. Exact DNS TXT verification replaces the local whitelist in the deployed runtime. No real financial operation is part of deployment acceptance.

2026-09-14: the user's own pending/successful deposit refund now blocks new
commission-to-Wallet transfers. Commission still accrues; existing Wallet balance
remains withdrawable under ordinary rules. See COMMISSION_REFUND_RESTRICTION.md.

2026-09-14: company security-deposit amount and nullable refund waiting days are
configured only by SaaS Platform (`tenant.manage`); company settings are read-only
for these fields. Existing balances and refunds are unchanged. The subsequently
approved timed workflow now snapshots waiting days for new requests, automatically
freezes cards, exposes only transaction reads and refunds after the deadline and
confirmed freezing. Legacy requests are never replayed or assigned deadlines. See
[Timed deposit refunds](TIMED_DEPOSIT_REFUNDS.md) and
[Company deposit settings](COMPANY_DEPOSIT_SETTINGS.md). Acceptance: 180 related
backend tests plus the expanded 17-test settings suite and 57 frontend tests passed;
typecheck, lint and build passed. The additive migration was applied only to the
isolated local `card_mock` runtime, not the ordinary database.

2026-09-13: approved all-country card address fallback: use known state/city options,
or format-validated manual names only where server reference lists are empty. The
same rule covers new applications and existing-holder edits; provider acceptance
remains required. See PER_CARD_MATERIALS.md.

2026-09-13: the user approved editing READY cardholder materials before any issue
order. Same-application revisions use the existing provider holder and require
confirmed update success; processing/UNKNOWN remain locked. Failed issue orders
permit fresh independent applications, not editing historical ones. See
PER_CARD_MATERIALS.md and migration 2026_09_13_001000. Status-only recovery cannot
confirm uncertain revised documents; real provider acceptance remains unverified.

2026-09-13: the selected `test` card merchant can use the full-contract local Mock
integration with default successful results, only in the explicitly isolated local
simulator. See LOCAL_CARD_SIMULATION.md; no production or real-money acceptance.

2026-09-13: [Card product provider bindings](CARD_PRODUCT_PROVIDER_BINDINGS.md) permits optional card-merchant selection and empty BIN/API configuration. New products remain unavailable for financial operations until an actual adapter is integrated; historical product routing is protected.

Consolidated on 2026-09-11 after the user approved the hardening plan. This index
resolves stale phase exclusions; it does not weaken AGENTS.md safety invariants.
The linked domain contracts govern details. Existing code or passing fixtures are
implementation evidence, not authorization for real financial acceptance tests.

| Capability | Effective scope | Implementation / remaining acceptance |
| --- | --- | --- |
| SaaS card provider references | User-confirmed manual name/reference-balance metadata; empty by default, Platform-only create/edit | PLATFORM_ACCOUNT_READS.md; reference values only, exact decimals, audit and separate manage permission; never actual funds/provider balances/runtime configuration |
| SaaS operational lists | All-company user, KYC, wallet, top-up, card issue and card records; optional company filter; independent Payment orders menu | PLATFORM_ACCOUNT_READS.md; existing Platform read permissions, pagination and company labels; no new review or money operations |
| Single currency | Approved 2026-09-13: USDT wallet/deposit/commission, USD provider cards, fixed 1:1 principal | SINGLE_CURRENCY.md; new defaults and guarded unused-USD normalization, no history rewriting or general FX |
| Company domains | SaaS-only, exact company/domain ownership; immutable system domains | Platform controls exist; production DNS adapter and certificate lifecycle still required |
| Consumer account | Left-aligned card, immutable copied account ID, display name, verified phone/email change, password change | USER_ACCOUNT_INFORMATION.md; tested with isolated transports; real per-company delivery acceptance pending |
| Authentication hardening | Verified-contact password recovery and other-session revocation implemented; device list/security records remain pending | USER_PASSWORD_RECOVERY.md and HARDENING_PLAN.md; isolated tests, no unverified contact override; real delivery acceptance pending |
| KYC | Company MANUAL/AUTOMATIC for new valid applications, locked identity limit | KYC_RULES.md; local approval is not third-party identity proof |
| Wallet top-up | Exact USDT/TRC20 shared-address allocation, full expected amount credited | Phase 8 least-used available suffix remains; not independent random selection; live scan/settlement acceptance pending |
| SaaS manual top-up confirmation | Approved 2026-09-13: explicit Platform receipt acknowledgement, no online check, fixed full Order amount | PLATFORM_MANUAL_TOPUP_CONFIRMATION.md; separate permission, immutable provenance, exact once-only Ledger credit; Company read-only; manual amount reservations retained |
| Live incoming verification / SaaS recheck | Approved 2026-09-13: read-only TronGrid receipt verification, opt-in bounded scan checkpoint, company/order-scoped SaaS recheck; company read-only | TRC20_LIVE_VERIFICATION.md; real credentials, USDT company setup, explicit scan start, monitoring and live acceptance still required |
| Wallet activation entry | Direct Deposit page with server-minimum, upward-editable top-up; no separate empty-Wallet step | SECURITY_DEPOSIT_RULES.md and SINGLE_CURRENCY.md; exact credit and first-time allocation |
| Withdrawal | Direct address+amount entry with explicit confirmation and paginated own history; company external manual payout, independent chain proof | WITHDRAWAL_RULES.md; atomic destination/order/hold, no automatic signing, custody or manual settle override |
| Fixed withdrawal fee | Approved 2026-09-13: company-configured fixed USDT fee, default 0; immutable gross/fee/net quote per order | WITHDRAWAL_RULES.md; gross hold/release, exact net chain verification, success-only fee revenue; no live payout acceptance implied |
| Transfer | Same-company same-asset available Wallet by account ID | WALLET_TRANSFERS.md; no cross-company, FX, commission or Deposit transfer |
| Promotion | Mandatory invitation, integer-USDT company-funded differential commission, real Deposit funding only | PROMOTION_REQUIREMENTS.md; refund does not freeze commission; cancellation is not funding |
| Invitation code | Global committed counter 523612–999999 with preserved legacy aliases | PROMOTION_INVITATION_CODES.md; exhaustion is explicit, no recycle/wrap |
| Cards | Existing regular virtual USD PhotonPay issue and management | CARD_MANAGEMENT.md; reveal/recharge/return/freeze/unfreeze/cancel/existing-holder edits/verified notifications approved; live merchant acceptance pending |
| Local card debug | Explicit local Mock on isolated PostgreSQL only, approved 2026-09-13 | LOCAL_CARD_SIMULATION.md; persistent full-contract simulator, failure modes and signed notification tests; never real-provider acceptance |
| Card history | Read-only owned-card pagination and aggregate | CARD_TRANSACTION_READS.md; this query never changes money and does not pause other approved flows |
| Deposit refund | New user-requested timed flow: company deadline, automatic freezing, transaction-only access, confirmed frozen/cancelled cards and resolved operations | TIMED_DEPOSIT_REFUNDS.md; no admin direct refund, historical replay or commission change |
| Support | Company-owned text/images, permission-scoped inbox, private encrypted storage | SUPPORT_CHAT.md; unread notifications/assignment/status remain hardening work |
| Marketing / languages | Own brand, verified claims, homepage-only style exception; consumer four locales, admin two | UI_RULES.md and I18N.md; no borrowed licenses or promises |

## Effective restrictions

Always preserve company isolation, explicit permissions, immutable accounting,
idempotency, exact money, nonnegative user balances, verified external evidence,
UNKNOWN recovery, private materials and absence of secrets in logs/responses.
No generic balance editing, manual mark-paid/force-success, or historical Ledger edits.

This approval does not add physical cards, holder identity reassignment, cross-company
transfers, local FX, automatic custodial payout, arbitrary customer scripts or new
fee engines. The Phase 9 product-settings boundary remains platform-owned provider
identity plus company sales configuration; any different product ownership design
requires its own explicit authorization, not inference from an old design paragraph.

## Deployment is not acceptance

Worker/scheduler definitions alone do not establish that they are running. Audit
historical jobs before enabling consumers. Tests must not send real SMS, upload real
identity materials, issue/reload/return/cancel real cards or replay unsettled history.
Live acceptance requires a named company/account, exact operation, cost/amount cap,
public callback reachability and user confirmation at the action boundary.
