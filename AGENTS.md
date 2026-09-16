# Virtual Card SaaS Agent Rules

On 2026-09-16 the user simplified multi-asset configuration: built-in public
Ethereum/Bitcoin read endpoints and optional keyless public prices, with SaaS
manual confirmation of fixed deposit orders. Internal balances/exchange remain
Ledger-only and independent of blockchain connections. The platform refreshes
one shared price snapshot per minute; user reads/quotes never fetch upstream data.
Explicit first enablement may choose a start boundary immediately after the current
finalized block; existing cursors/history never reset. Pro/custom credentials remain
optional advanced settings, never forwarded to a different/public endpoint. Preserve
trace completeness, finality, exact receipt matching, password-confirmed manual
credit, immutable quote economics and fail-closed stale pricing. See
`docs/architecture/MULTI_ASSET_CENTER.md`.

On 2026-09-16 the user explicitly requested complete PhotonPay callback diagnostics:
sensitive data encrypted and other business parameters retained. A dedicated private,
rotating webhook log may retain an AES-256-GCM encrypted envelope of exact callback
bytes and the three verification headers, using a separate configured 32-byte key.
Only validated, known non-sensitive fields may also appear in plaintext; unknown,
free-text and sensitive values remain in ciphertext. No plaintext fallback, automatic
decryption, browser exposure or financial replay is permitted. This narrowly extends
the former no-body logging rule for this encrypted diagnostic channel only; ordinary
logs and all other sensitive-data restrictions remain unchanged. See
`docs/architecture/PHOTONPAY_LOGGING.md`.

On 2026-09-16 the user chose unified application/migration ownership for the
dedicated deployed card_platform database. The existing card_platform login may
own the project's public-schema objects and create future migration objects.
The one-time administrator script transfers only postgres-owned, non-extension
project relations/routines in that database/schema. Do not grant SUPERUSER or
postgres membership, use cluster-wide REASSIGN OWNED, disable guards or change
business rows. This ownership model permits application-role DDL by design;
Ledger and all application financial contracts remain unchanged. See
docs/deployment/PRODUCTION_DEPLOYMENT.md.

On 2026-09-16 the user replaced periodic PhotonPay card synchronization with one
inline synchronization attempt after a verified notification is persisted, plus
explicit existing scoped refresh actions. Do not enqueue notification processing
or schedule card polling/recovery. Old notification jobs and cards:recover are
inert compatibility stubs. Failed reads preserve confirmed balances and UNKNOWN
holds; no forced settlement, callback balance deltas or historical replay. See
docs/architecture/CARD_MANAGEMENT.md. This supersedes the earlier scheduled card
recovery requirement only; unrelated business schedules retain their contracts.

On 2026-09-16 the user approved paid annual promotion levels, fee-percentage
differentials, existing repeatable deposit activation rewards, and reviewed annual
fee rebates. USDT Wallet payment alone creates paid qualification; no manual level
assignment. Existing assigned levels become ordinary without rewriting money or
invitation history. Ordinary members earn 20 USDT for direct activations only.
Annual upgrades charge the tariff difference and preserve expiry; rebate claims
count direct funding events twice and indirect events once against twice the target.
Only explicit Platform approval with current-password confirmation may return paid,
not-yet-returned annual fees. No commission clawback. All posting uses LedgerWriter.
This supersedes the fixed-reward-only / no-fee and manual-level-assignment clauses
below solely for this feature. See docs/architecture/PAID_PROMOTION.md.

On 2026-09-16 the user approved the multi-asset center and internal exchange plan.
USDT/USDC/ETH/BTC have separate wallets; new rails default OFF. USDT/USDC use
Ethereum tokens, USDT also retains TRON, ETH is native Ethereum, BTC is Bitcoin.
SaaS manual deposit receipt confirmation extends only to fixed existing orders;
shared-address amount reservations and late-transfer protection remain required.
Withdrawals require external manual sending plus verified chain proof, never forced
success. USDC/ETH/BTC may exchange internally into USDT only, through atomic paired
single-asset LedgerWriter events with immutable quote/fee/rate provenance. No trading
API, signing, private keys or historical conversion is authorized. Generic ledger
storage expands to NUMERIC(38,18); ETH scale is 18, USDC 6, BTC/USDT 8. Preserve
historical USDT/USD values and canonical hashes. Deposit/commission/card rules stay
USDT/USD as before. This supersedes only the single-asset/8-decimal restrictions
needed by this plan. See docs/architecture/MULTI_ASSET_CENTER.md.

On 2026-09-15 the user explicitly removed custom-domain DNS ownership verification.
New SaaS-managed custom domains are ACTIVE and unassigned; assignment remains
explicit and Platform-only. Historical PENDING_VERIFICATION/VERIFIED domains may
be explicitly activated without DNS checks, never bulk-activated by reads/deploys.
Do not fabricate verified_at, verification events or SSL readiness. Preserve
hostname validation/uniqueness, reserved hosts, active Platform tenant.manage,
company scoping, system immutability and primary protection. This supersedes only
the DNS verification/state-transition requirements below. See
`docs/architecture/PLATFORM_DOMAIN_CONFIGURATION.md`.

On 2026-09-15 the user approved compact Me navigation: profile with verification
status, Account and security/Promotion/Support grid, and a Settings subpage for
language/About/logout. Wallet and Deposit shortcuts remain on Assets instead.
This supersedes the previous Me Wallet shortcut only; the icon-grid baseline,
restricted security access and all financial/authentication contracts remain.
See docs/architecture/USER_ACCOUNT_INFORMATION.md.

On 2026-09-15 the user approved system-record-only consumer card transactions:
remove issuing/provider wording from consumer copy; show exact matched system
settlement time or immutable first-recorded time in the company timezone. A new
scoped POST transaction sync may persist validated read-model rows, while GET
reads only saved rows. This narrowly supersedes the old no-persistence transaction
read extension; it never permits balance, Ledger, order-result or provider writes,
automatic full-history replay, or guessing unzoned transaction times. Preserve
first-recorded timestamps across retries and notification refreshes. See
`docs/architecture/CARD_TRANSACTION_READS.md`.

On 2026-09-15 the user authorized a local Promotion fixture batch: eight levels
50–120 and 500 new descendants with mixed guarantee funding and newly created
historical timestamps. Only isolated card_mock/card_ui_test may run the explicit
fixture script. Use existing mock top-up, deposit, commission and LedgerWriter
flows; never edit historical Ledger or replay real providers. See
`docs/architecture/LOCAL_PROMOTION_FIXTURES.md`.

On 2026-09-14 the user explicitly authorized testing the current PhotonPay
cardholder materials and retaining them for future tests. Explicit encrypted
private save/load of test drafts is local/testing plus card_mock/card_ui_test
only, scoped to the authenticated Tenant/User/product, and never submits or
changes provider/financial/UNKNOWN state. See PER_CARD_MATERIALS.md.

On 2026-09-14 the user moved custom-domain creation and lifecycle UI to SaaS domain
configuration. The user subsequently moved multiple-domain company assignment
to that same global catalog; company pages show assigned domains only. An
unassigned custom domain has nullable tenant_id and never resolves to a company.
Each complete hostname remains globally unique and binds at most one company.
Active Platform tenant.manage is required; preserve system domains, primary-domain
protection, verification/activation and company-scoped resource checks. Existing
bindings are preserved. See docs/architecture/PLATFORM_DOMAIN_CONFIGURATION.md.

On 2026-09-14 the user moved Aliyun SMS and Proton email credentials/settings to
multiple named SaaS-owned profiles. Active Platform tenant.manage maintains profiles
and selects a profile per company/channel; Company Admin remains read-only. Explicit
bindings may share a profile across companies. Missing/disabled profiles fail closed,
with no legacy or other-company fallback. Credentials remain encrypted/hidden and
writes require the current Platform password; tenant-scoped OTPs, quotas, test-send
idempotency and UNKNOWN protections remain unchanged. See
docs/architecture/PLATFORM_NOTIFICATION_PROFILES.md.

On 2026-09-14 the user removed company wallet top-up and withdrawal policy toggles:
both are always enabled in company configuration for existing and future companies.
Configuration UI no longer exposes these switches; stale requests cannot disable
them. This does not waive Tenant/User availability, KYC, Wallet status, balances,
fees, provider configuration, review, verification or idempotent financial rules.
See docs/architecture/SAAS_COMPANY_CONFIGURATION.md.

On 2026-09-14 the user moved identity verification policy to one SaaS-wide
configuration and selected AUTOMATIC review, enabled, one account per identity.
Only active Platform membership with tenant.manage may change the singleton
platform_kyc_settings policy. All current and future companies use it; company
configuration endpoints must not mutate it. This supersedes per-company policy
selection only. Identity matching and account limits remain tenant-scoped;
existing applications/identities are never replayed or rewritten. Automatic approval
is local acceptance of new valid materials, never OCR/PhotonPay verification.
See docs/architecture/PLATFORM_KYC_SETTINGS.md.

On 2026-09-14 the user explicitly confirmed cancelling all ten snapshot cards of
Tenant A account 202609131303, settling their provider-confirmed cancellation
returns, then removing them from the consumer list. This supersedes the earlier
one-card sandbox cancellation limit for that exact snapshot only. Use existing
provider/Ledger flows and stable requests, preserve UNKNOWN recovery and history,
and archive only after confirmed cancellation, zero balance and settled returns.
See docs/architecture/USER_CARD_CLEANUP.md. New cards and other users are excluded.

On 2026-09-14 the user required SaaS administrator creation and provenance for all
manual financial operations. Platform account creation requires active Platform
membership, admin_team.manage and current-password confirmation; only
PLATFORM_ADMIN/PLATFORM_AUDITOR are assignable through this flow. Every manual
financial operation must retain trusted operator ID and server operation time in
append-only audit history, including provider/chain verification requests and
unconfirmed outcomes. Financial success/release provenance is atomic with its
business transaction. Admin views show the recorded actor and time; never invent
missing historical actors or replay money operations to populate history. See
docs/architecture/PLATFORM_ADMIN_AND_FINANCIAL_OPERATIONS.md.

These rules are mandatory for every future change. If a requested feature requires changing a locked rule, report an `ARCHITECTURE CONFLICT` with the current rule, required change, reason, and affected modules; pause only the conflicting work.

On 2026-09-15 the user removed the second card-reload confirmation: one explicit
reload submission obtains the provider quote and confirms that same order without
another password or confirmation checkbox. Only LOAD confirmation is exempt;
other management/reveal authentication is unchanged. Preserve exact quote economics,
expiry, ownership, Ledger holds, stable request IDs and UNKNOWN recovery. See
`docs/architecture/CARD_MANAGEMENT.md`.

## Current scope and historical phases

On 2026-09-15 the user explicitly approved one deployed site/database retaining ALL
current users, card records, balances, commissions, configurations and history,
including existing LOCAL_MOCK merchants and PhotonPay sandbox connections. The
`directory` card driver may operate these persisted integrations in the deployed
site without local-environment/database-name restrictions. This supersedes the
historic production-Mock prohibition only for explicit persisted provider routing;
it never converts identifiers, invents provider success, rewrites Ledger, reroutes
used products, or enables fixture/financial replay commands on the server. Real
PhotonPay requests still reject Mock identities; missing credentials fail closed.
See docs/architecture/UNIFIED_SITE_DEPLOYMENT.md.



On 2026-09-14 the user authorized PhotonPay sandbox acceptance testing and reuse
of existing sandbox holder CH2096144404451819520 for Tenant A account 202609131303,
including one USD/recharge/virtual card per eligible BIN with 20 USD initial load.
This permits a scoped existing-holder binding workflow instead of fresh materials
for this sandbox run only; it does not authorize fabricated READY records, arbitrary
balance changes, production calls or conversion of Mock identities. Provider account
ownership/status must be verified, and orders, Ledger holds and UNKNOWN recovery
remain required. Approval is not proof of implementation or successful live testing.
See docs/architecture/PHOTONPAY_SANDBOX_ACCEPTANCE.md.

On 2026-09-14 the user moved ALL company configuration to SaaS Platform. Company
Admin configuration views are read-only; all configuration mutation routes/actions
require active Platform membership and tenant.manage. This includes product offerings,
branding, locales, business/KYC policy, articles, notification settings/tests,
promotion levels/member assignment, team/invitations, and foundation activation.
Platform selects the company via a persisted route model; nested resources remain
company-scoped. This supersedes earlier Company Admin configuration grants only;
existing operational review workflows and personal authentication/locale remain.
See docs/architecture/SAAS_COMPANY_CONFIGURATION.md.


On 2026-09-14 the user moved card opening-fee configuration to SaaS Platform only.
Company administrators may read but cannot change it. New card order quotes/holds
use the product fee; existing orders retain their snapshots. Null means unconfigured.
This supersedes historical tenant-only opening-fee settings, not Ledger contracts.
See docs/architecture/PLATFORM_CARD_OPENING_FEES.md.


On 2026-09-14 the user explicitly revised commission transfer eligibility: the
user's own pending (CHECKING, including cancellation restoration) or successful
(COMPLETED) deposit refund blocks new commission-to-Wallet transfers. Commission
continues to accrue; no balance freeze account, clawback or reward reversal is
introduced. Existing Wallet balance remains withdrawable under normal withdrawal
rules. A CANCELLED request alone does not block transfers; a prior COMPLETED refund
continues to block them. No automatic unlock on re-funding was authorized. Preserve
completed transfer replay without posting again. This supersedes earlier statements
that refunds do not affect commission only for transfer eligibility, not earnings
or Ledger balances. See docs/architecture/COMMISSION_REFUND_RESTRICTION.md.

On 2026-09-14 the user moved company security-deposit amount and refund-wait
configuration exclusively to SaaS Platform with active Platform membership and
`tenant.manage`. Company Admin may read but never mutate either field, including
through its business-settings endpoint. Each company has its own optional integer
waiting period in days; null means unconfigured, never an invented default. These
settings never change balances or existing refund requests. The user subsequently
approved the timed refund/card-freeze workflow: new confirmed user requests snapshot
the company waiting days and deadline, immediately block card actions except
transaction reads, and automatically freeze cards through the provider contract.
After the deadline, confirmed frozen/cancelled cards and resolved prior operations
permit one LedgerWriter refund; cancelled cards and zero card balance are no longer
required for these new requests. Provider UNKNOWN still fails closed. Cancelling a
request restores only cards frozen by that request, with confirmation before unlocking.
Completed refunded cards remain read-only/frozen; no automatic re-funding or commission
change. Legacy requests receive no retrospective deadline or replay; cancel/reapply.
This narrowly supersedes historical cancelled-card/zero-balance requirements below.
See docs/architecture/TIMED_DEPOSIT_REFUNDS.md and COMPANY_DEPOSIT_SETTINGS.md.

On 2026-09-13 the user approved manual locality fallback for all countries/regions
in both card application and existing-holder editing. Country remains allowlisted;
state/city use strict membership when their server-side list is nonempty. Only an
actually empty list permits a real user-entered locality with Unicode-safe format
and 50-character validation. Invalid parents, failed loads and client flags never
enable fallback. Provider acceptance, identity and financial gates are unchanged.
This supersedes the previous no-free-text rule only for missing locality data;
never fabricate location options. See PER_CARD_MATERIALS.md.

The user explicitly approved unissued cardholder-material revisions on 2026-09-13:
READY or ACTION_REQUIRED applications with no CardIssueOrder may update the same
provider holder and increment the submission revision, returning to SUBMITTING until
the provider confirms the update. Any issue order permanently locks that application;
FAILED issue orders allow a fresh application, never reuse of the old one. Processing
or UNKNOWN submissions/issues cannot be bypassed with a new UUID. Status-only reads
of a previously READY holder cannot confirm an uncertain material/document revision.
See docs/architecture/PER_CARD_MATERIALS.md. This supersedes only the old
ACTION_REQUIRED-only material-update rule, not ownership, financial or private-data gates.

The user approved the hardening plan on 2026-09-11. `docs/architecture/CURRENT_CAPABILITIES.md`
is the consolidated index of effective approvals, implementation evidence and outstanding
acceptance work; `docs/architecture/HARDENING_PLAN.md` tracks delivery. Phase numbers describe
the original delivery increments, not new prohibitions of explicitly approved extensions.
Card management and Promotion contracts below supersede their corresponding historic
exclusions. Safety rules remain mandatory. Approval or passing tests is not proof of live
provider acceptance. Never replay historical jobs or execute live financial tests implicitly.

## Tenant safety

- Domain configuration is SaaS Platform-only (user-confirmed 2026-09-11), protected by active Platform membership and `tenant.manage`. Company Admin has no domain routes or controls. Platform selects the persisted company through its authorized company route; every domain mutation still matches company id plus domain id. Preserve system-domain immutability, DNS verification and primary-domain lifecycle rules.

- Never trust `tenant_id` from client input. Tenant web requests resolve `Host -> tenant_domains -> TenantContext`.
- Tenant resolution requires an ACTIVE domain but is independent of Tenant lifecycle status. Apply DRAFT/ACTIVE/SUSPENDED/CLOSED availability in a separate surface policy.
- Keep domain lifecycle status separate from `is_primary`; never add a `PRIMARY` domain status. Reject Unicode/IDN domains until an explicit IDNA design is approved.
- Every tenant business query must be tenant-scoped by both `tenant_id` and resource id.
- Tenant Admin must never access another tenant. A membership and the resolved tenant must match.
- Platform roles cannot back Tenant memberships and Tenant roles cannot back Platform memberships; preserve the database-level scope match.
- Queue jobs must explicitly carry trusted `tenant_id` and `resource_id`; never depend on session, host, or request context.
- Webhook tenant resolution must use trusted provider connection/resource/order mappings, never a body tenant id.
- Scheduler work must receive an explicit tenant id or deliberately iterate tenants.

## Money safety

- On 2026-09-13 the user approved one product currency: USDT wallet/deposit/commission and USD PhotonPay cards at fixed 1:1 principal, with no FX spread or currency choice. New companies/seeds use USDT. The dedicated unused-USD migration may normalize only zero-balance accounts with no financial history/orders under exclusive maintenance locks, preserving IDs and all Ledger history and restoring runtime identity guards. Nonempty/history-bearing accounts fail closed, never relabel or rewrite them. See `docs/architecture/SINGLE_CURRENCY.md`. This narrowly supersedes the old denomination freeze/local-FX exclusion; it does not permit arbitrary currency changes, balance setters or a multi-currency engine.

- Never modify money outside the Ledger architecture. PostgreSQL is authoritative; Redis is not.
- Never create generic balance setters or helpers named `setBalance`, `updateBalance`, `adjustBalance`, `creditBalance`, `debitBalance`, `manualCredit`, or `manualDebit`.
- Never manually modify wallet, security-deposit, or commission balances—not even for Platform Owner.
- Never edit or delete Ledger history. Correct errors through a new reversal/refund business flow.
- Never write `LedgerPosting` directly from business code; every committed event goes through `LedgerWriter`.
- Never update `LedgerAccount.balance` outside `LedgerWriter`, and never expose a generic balance adjustment endpoint.
- Never create manual-adjustment, correction, or administrator-adjustment accounts.
- Every Ledger event must be idempotent, contain one asset only, and sum exactly to zero with at least two non-zero postings.
- User-owned accounts and Tenant fee revenue must never become negative; only the defined Tenant clearing account types permit negative balances.
- Ledger Entries and Postings are immutable. Reversal is always a new business-authorized Entry, never an edit.
- Every completed Ledger Entry is permanently sealed. Postings may be inserted only while its parent Entry is unsealed inside `LedgerWriter`; late Postings are forbidden even when they preserve a zero sum.
- The database must validate at transaction completion that every cached Ledger Account balance equals the sum of its sealed Postings. Triggers validate but never calculate or repair balances.
- Follow the global financial lock order: business aggregate/order row, business advisory lock, Ledger event transaction lock, Ledger Accounts sorted by UUID, then derived rows. Only `LedgerWriter` locks Ledger Accounts.
- `LedgerWriter` must remain safe inside an outer business transaction and must never commit or release locks independently of that outer boundary.
- Wallet activation and User lifecycle mutations lock Tenant before User so they cannot invert the shared ownership lock order through Audit foreign keys.
- Reconciliation reports posting/cache mismatches and must never silently repair them.
- Money must use decimal strings and `NUMERIC(20,8)`; never float/double/real or JavaScript numbers.
- Every future money-changing request must be idempotent. Persist intent and hold, commit, call external providers, then settle/release in a new transaction.
- Never accept a client-selected Security Deposit amount, asset, Wallet, User, or Tenant. Funding transfers exactly the current server-calculated remaining requirement.
- Never mutate `USER_SECURITY_DEPOSIT` outside `LedgerWriter`, create a separate Deposit balance table, or automatically refund excess after a requirement decrease.
- Tenant and Platform administrators cannot fund, adjust, release, or refund Security Deposit. Refund remains forbidden until the Card cancellation and Provider balance-check workflow exists.

## Provider safety

- On 2026-09-13 the user approved the `test` merchant as a local Mock integration, default SUCCESS/READY. Its persisted LOCAL_MOCK runtime routes newly bound products through the existing full-contract simulator only under the locked isolated local environment/driver/database checks. Names and reference balances never route existing cards; renaming does not change the persisted runtime. No production Mock, historical migration/replay, eligibility bypass or forced financial success. See docs/architecture/LOCAL_CARD_SIMULATION.md.

- On 2026-09-13 the user approved platform product binding to the card-merchant directory. New products use UNCONFIGURED routing with an optional directory binding/BIN until a separately implemented API integration exists; saving configuration never calls providers or moves funds. Unconfigured products cannot create cardholders, issue cards or hold money and never fall back to the legacy provider. Existing PHOTONPAY products/cards/orders retain their original routing; once a holder, order or card references a product, provider binding and BIN are immutable. Directory reference balances remain informational and never participate in financial operations. This narrowly supersedes Phase 9's fixed-provider configuration rule, not USD/REGULAR, runtime safety or financial contracts. See docs/architecture/CARD_PRODUCT_PROVIDER_BINDINGS.md.

- Explicit exception approved 2026-09-13: SaaS Platform may manually confirm an existing TRC20_SHARED/USDT top-up in PENDING/PROCESSING/UNKNOWN without chain verification, using `wallet_topups.confirm`, explicit second confirmation, immutable actor/time/request provenance and fixed full order amount. Company Admin remains read-only. The atomic action uses PAID then existing CreditWalletTopupAction/LedgerWriter with the deterministic credit key; it never fabricates provider success or chain evidence, changes historical Ledger, accepts an amount override, confirms expired/review/failed/cancelled/refunded orders, or exposes a generic credit API. Manual confirmations retain exact-amount reservations against late transfers. This supersedes only the matching no-manual-confirmation rules below. See PLATFORM_MANUAL_TOPUP_CONFIRMATION.md.

- On 2026-09-13 the user approved real read-only incoming TRON/USDT verification and SaaS-only rechecks of one selected company/order using the same chain proof. Company Admin remains read-only. This supersedes only Phase 8's absence of a real chain adapter, never its force-success/manual-assignment ban. Live scanning is opt-in with an explicit persisted start boundary; never silently replay historical orders. See `docs/architecture/TRC20_LIVE_VERIFICATION.md`.

- Mock and real Card Providers must implement `CardProviderInterface`; business code depends only on that contract.
- Real Card runtimes use PhotonPay with no fallback. On 2026-09-13 the user explicitly approved local debug Mock: only APP_ENV=local plus explicit CARD_PROVIDER_DRIVER=mock and an isolated card_mock/card_ui_test PostgreSQL database may use the persistent local simulator. APP_DEBUG alone never enables it. Automated testing retains its offline fake. Staging/production and the ordinary card_platform database must never select Mock. Reject historical MOCK/TEST/DEMO references before live Provider calls or holds. Never import real/historical cards into the simulator, convert Mock identities into real cards, or automatically replay/refund historical orders. Missing real credentials fail closed. See `docs/architecture/LOCAL_CARD_SIMULATION.md` and `PHOTONPAY_INTEGRATION_RULES.md`.
- Never call an external provider API inside a long database transaction.
- Never implement domain verification as an unrestricted HTTP fetch; production verification must be DNS-only or explicitly SSRF-hardened.
- Provider timeout or an unconfirmed response is `UNKNOWN`, not `FAILED`; reconcile before retry/refund.
- Provider adapters handle HTTP, authentication, and mapping only. They must never modify Wallet, Ledger, Deposit, or Commission.
- Never store Provider credentials in plaintext. Existing secrets are replace/test/disable only, never revealed.
- Never credit a wallet from a browser redirect and never treat Provider `PAID` as internal `CREDITED`.
- Never let Payment Provider adapters mutate Ledger directly, and never trust webhook `tenant_id`; derive Tenant only from a verified provider resource mapping.
- Never retry an UNKNOWN provider request with a new business request id. Query or await a verified webhook using the stable provider request id.
- Never credit a mismatched amount or asset, and never expose manual mark-paid, mark-credited, force-success, or generic wallet-credit endpoints.
- Already-paid external settlements continue after later User or Tenant suspension. Every top-up credit uses `wallet_topup:{order_uuid}:credit` as its deterministic Ledger event key.
- `CREDITED` is an immutable financial fact. Post-credit refunds, chargebacks, reversals, and disputes mark the Provider Event `REQUIRES_REVIEW`; they never overwrite the Order or debit Ledger in Phase 5.
- Payment initiation recovery must reuse the same Provider request id. Query/webhook transitions are monotonic, and `payments:reconcile` remains read-only while validating each credited Order's exact accounting path.

## Sensitive data

- Never store PAN/CVV in ordinary application tables and never log PAN/CVV.
- Never log passwords, password confirmation, OTP, identity numbers/documents, API keys, tokens, or Provider secrets.
- Never dump an entire request in a sensitive flow.
- Never expose unrestricted Eloquent models as API JSON; use explicit DTOs/Resources and allowlisted fields.
- KYC documents use private storage. Identity lookup uses encrypted values plus keyed HMAC, never plaintext indexes.
- Never expose KYC identity ciphertext/HMAC, document object keys, signed URLs, or encrypted OCR results in ordinary model serialization, Inertia props, logs, or audit.
- `KYC_DATA_ENCRYPTION_KEY` and `KYC_IDENTITY_HASH_KEY` are stable persistent-data keys; rotation requires a dedicated verified re-encryption/re-hash migration and must never be treated as routine API-key rotation. KYC encryption must never silently fall back to `APP_KEY` or plaintext.
- Canonical identity matching includes Tenant, document type, country, and normalized number through unambiguous serialization. Never put raw identity data in advisory-lock, cache, rate-limit, audit, or log keys.

## Architecture

- Controllers remain thin: Controller -> Application Action -> Domain Services.
- Complex use cases use explicit Application Actions and DTOs; Domain code receives explicit TenantId/UserId and never calls `auth()`.
- Do not introduce circular Domain dependencies or modify another Domain merely to finish a feature.
- Ledger does not know Card/KYC/Agent/Commission. CardProvider does not depend on Wallet. KYC does not change Wallet. Tenant settings do not change money.
- Do not add tables or states without updating `docs/architecture/`.
- Do not silently modify completed Domain contracts.
- Preserve existing migrations after formal phases begin; use new migrations for changes.
- Audit history is append-only by Application contract. Eloquent guards are defense in depth, not a claim that privileged direct database access is impossible.
- Admin authentication must always validate AdminUser status plus an ACTIVE membership for the exact Platform or resolved Tenant scope; a password or role name alone is insufficient.
- Admin invitation tokens must remain cryptographically random, hash-only at rest, expiring, single-use, Tenant-host scoped, and absent from logs/audit data. Resend must invalidate the previous token.
- Tenant foundation activation must remain computed from persisted requirements. Never add a writable onboarding-complete override or equate Tenant ACTIVE with Card/Provider/money readiness.
- System domains are immutable. Custom domains must pass PENDING_VERIFICATION -> VERIFIED -> ACTIVE, and only ACTIVE domains can become primary.
- End User and Admin identities/guards remain separate. Every End User credential lookup starts with resolved `tenant_id`; never perform a global email/phone lookup and check Tenant afterward.
- Registration creates a User only from a locked, VERIFIED, unexpired, unconsumed Tenant-scoped challenge. OTPs are HMAC-hashed, never persisted or logged raw, and PostgreSQL remains authoritative.
- Expired PENDING challenges become EXPIRED before replacement. A still-valid VERIFIED/unconsumed challenge is reused only for its initiating browser session; another session must prove contact ownership with a new OTP, whose verification expires the older verified state.
- Tenant User session restoration must query by resolved Tenant and user id. User/Tenant status is re-evaluated on every request; restricted access is deny-by-default with an explicit safe-route allowlist.
- Rate-limit keys must hash normalized email/phone and never contain raw contact data, OTPs, tokens, or passwords.
- User `ACTIVE` means account access only; it never means KYC approval, Wallet readiness, deposit qualification, or card eligibility. Suspending a User restricts access and never changes money or cards.
- KYC status is derived from immutable applications/current Identity Record, never stored on `users`. OCR is untrusted assistance and never automatic approval.
- The user explicitly approved company-policy automatic KYC on 2026-09-11. MANUAL and AUTOMATIC are selectable per company; AUTOMATIC approves only new valid submissions under the existing locked identity-account limit, with a SYSTEM audit and explicit automatic-approval provenance. It is not OCR/provider verification. No retrospective bulk approval, fabricated administrator, or Wallet/Ledger/Card mutation. See docs/architecture/KYC_RULES.md.
- KYC review transitions only from PENDING, approval enforces the Tenant identity limit under a database lock, and no KYC action creates Wallet/Ledger/Deposit/Card state.
- V1 requires the Security Deposit asset to equal the Tenant default asset. Once a Wallet or Ledger Account exists, the Tenant default asset is frozen until a separately designed migration/multi-asset workflow.
- KYC document access requires separate permission, current Tenant scope, recent Admin password confirmation, short-lived signed private access, and a sanitized audit event.

## UI

- The user approved a homepage-only high-fidelity reference layout exception on 2026-09-11: oversized typography, three-card artwork, gradients, spacious product sections and responsive marketing navigation are allowed exclusively under `.marketing-home`. Preserve our brand, real routes and eligibility gates. Do not attribute another company's licenses, testimonials, merchant acceptance or unverified fee/approval/custody promises to this business. Keep authenticated User/Admin styling unchanged.

- About articles are fixed Tenant-owned terms/privacy/account-closure plain text per locale, protected by `tenant_settings.manage`. Render text escaped, with no cross-Tenant/cross-language fallback or custom HTML/CSS/JS. Account closure here is informational only; never implement account/card deletion or funds movement through article settings. See `docs/architecture/TENANT_ARTICLES.md`.

- Platform Admin and Tenant Admin support only `zh-CN` and `en`, defaulting to Chinese independently of consumer preferences. Use the shared i18next Admin namespace and isolated Admin locale cookies. Translate presentation only; never translate submitted enum values, permission keys, money, routes or provider IDs. Preserve unsaved forms when switching languages.

- Use shadcn/ui primitives and approved project components; do not introduce another design system.
- User surfaces are mobile-first. Admin surfaces are desktop-first but must remain usable at 375, 768, and 1440 px.
- Do not expose Ledger/clearing/posting terms or raw provider errors to end users.
- Do not add arbitrary tenant CSS/JS, custom React uploads, dashboard builders, or page builders.
- Avoid glassmorphism, neon/crypto styling, decorative 3D cards, and complex animation. The user's explicit live-reference UI revision permits the soft PokePay-style background blend on client asset/auth screens only; it never applies to Admin surfaces.
- User mobile UI follows the shared PokePay-inspired baseline for information density and navigation behavior without copying its brand, assets, or visual identity; this is a design contract, not a second UI framework.
- End User pages are mobile-first, with 375 px as the primary User UI acceptance width.
- Never expose raw business, Ledger, KYC provider, or Card Provider states to End Users.
- Never create fake feature buttons for unfinished financial capabilities; reveal actions only when their business flow exists.
- Do not wrap every User section or activity row in a Card. User financial UI prioritizes balance, the primary next action, and activity.
- Admin UI remains separate from the consumer mobile design language; User theme tokens must not leak into Tenant Admin or Platform Admin.
- Critical actions require explicit warning and confirmation; a toast is insufficient.
- The user-confirmed per-card form omits identity-number and issuing-country inputs. Derive issuing country from the cardholder's nationality; absent identity numbers stay null and are omitted from Provider requests. Never fabricate them or reuse account KYC. Keep document upload, confirmed Provider addition and financial gates intact; see `docs/architecture/PER_CARD_MATERIALS.md`.
- Consumer/public system copy must use the shared i18next catalog with complete en/zh-CN/ms/es resources. Never hardcode mixed-language JSX. Locale choices must respect Tenant enabled locales and scoped User preference > host-only cookie > browser language > Tenant default. Preserve decimal strings, business identifiers, brands and user-authored content; language switching must not reset financial forms or request IDs. See docs/architecture/I18N.md.
- The user explicitly replaced the initial four-tab consumer shell with the live reference's three destinations: Assets, Cards, Me. Wallet activity, top-up, withdrawal, and Security Deposit remain within Assets. Use the live reference's 750px canvas, scaled circular actions, icon-grid Me page, and bottom navigation proportions; tenant branding and real business data remain our own. Promotion and same-company transfers use their approved real flows. Unimplemented capabilities must not gain fake business flows. Me uses a left-aligned profile card and Account information per USER_ACCOUNT_INFORMATION.md.

## Phase boundaries

The user approved company-configured fixed withdrawal fees on 2026-09-13. This supersedes the historical zero-fee exclusion only: `tenant_settings.manage` configures a non-negative two-decimal USDT fee (default 0). The server snapshots it per new Order; entered amount is the gross hold, exact payout is gross minus fee and must be positive. A client fee quote is confirmation only, never authority. Verified success settles gross to net withdrawal clearing plus Tenant fee revenue through LedgerWriter; cancellation/rejection releases all gross without fee income. Never change existing Order economics when settings change. See WITHDRAWAL_RULES.md.

The user explicitly approved same-company same-asset available-wallet transfers by account ID on 2026-09-11. See docs/architecture/WALLET_TRANSFERS.md. This narrowly permits one atomic internal LedgerWriter event and immutable receipt (no external provider or artificial hold), with password/explicit confirmation, idempotency, exact decimal money, active verified wallets and tenant ownership checks. Never touch deposits/commissions or trigger rewards/automatic deposit funding; no cross-company/FX/admin balance operations.

The user explicitly approved replacing and migrating all company/member promotion invitation codes on 2026-09-11. Codes share one global transactional six-digit counter starting at 523612, advancing by one per committed allocation. Only the one-time migration may replace existing codes; inviter/member/company UUIDs, levels and financial history remain unchanged. Preserve tenant-scoped legacy link aliases. New codes remain immutable, never recycle or wrap after 999999. This does not change cryptographically random Admin invitation tokens. See docs/architecture/PROMOTION_INVITATION_CODES.md.

The user explicitly approved the Promotion / Commission / Security Deposit lifecycle stage on 2026-09-11. See `docs/architecture/PROMOTION_REQUIREMENTS.md`. This supersedes the older exclusions only for tenant-owned invitation relationships and levels, fixed integer-USDT differential commissions funded by the company, user-initiated commission-to-Wallet transfers, first-time automatic Wallet-to-Deposit allocation after verified Wallet credit, and a separate user guarantee-refund/request-cancellation workflow with Card cancellation, pending-operation and authoritative Provider balance checks. Only a committed positive Wallet-to-Deposit funding event earns commission, exactly once per event/beneficiary; a genuine later re-funding can earn again. Refunds do not freeze or claw back commission; cancelling a refund request earns nothing. Ordinary top-ups and card reloads never earn commission. Keep all tenant, Ledger, OTP, UNKNOWN and private-data rules. The user confirmed the company total fund book is consolidated cost accounting, not a budget gate: TENANT_COMMISSION_CLEARING records company commission expense and may go negative; USER_COMMISSION must remain nonnegative and transfers only to its owner's verified Wallet. Customer principal is not company revenue. Never invent balances, expose a writable pool or repurpose unrelated clearing accounts. Authorization of the stage does not permit fake UI actions.

The user explicitly approved the post-Phase-10 Card management stage: real PhotonPay CVV reveal, existing regular virtual USD Card recharge/amount return, cancellation, freeze/unfreeze, holder information updates, and authenticated issuing notifications. See `docs/architecture/CARD_MANAGEMENT.md`. This supersedes only the corresponding older exclusions below. Keep tenant isolation, immutable Ledger, UNKNOWN recovery, private materials, and no production Mock or real-provider fallback (the isolated local exception is defined above). Card amount return is not Security Deposit refund; do not unlock deposit refunds, commissions, new card products, local FX, or manual financial overrides. Sensitive reveal is short-lived/no-store/recent-password only. Notifications never directly debit a wallet or subtract transaction amounts from the Card cache: verify, resolve trusted mappings, and query Provider truth.

The all-owned-card transaction history below the cards is a read-only subflow of the approved Card management stage, not a pause on its other operations. Its query and aggregation never write Wallet/Ledger or reveal PAN/CVV. Sensitive reveal and management mutations use only their separate authorized flows. See `docs/architecture/CARD_TRANSACTION_READS.md` and `docs/architecture/CARD_MANAGEMENT.md`.

Phase 7 adds only fixed USDT-TRC20 manual withdrawal: immediate exact hold, Tenant Admin manual external send, independent exact blockchain verification, and exact settle/release. Never let the client select the rail, Wallet, Tenant, User, fee, or hold amount. Full withdrawal addresses require dedicated encryption/HMAC, masked ordinary output, and recent-auth Admin reveal without address content in logs or audit. A transaction hash can belong to only one Order; pending/timeout/unavailable verification never means failed or settled. Mock chain verification is local/testing only. This phase does not contain automated/custodial payout, private keys, monitored crypto deposit, bank/fiat or multi-chain rails, Security Deposit refund, cards, real providers, agents, commissions, manual adjustments, or billing.

Phase 8 top-up is fixed to USDT on TRON/TRC20 using one configured shared public address. The server alone allocates `0.01..0.99` through a PostgreSQL-locked least-used strategy; PENDING, PROCESSING, UNKNOWN, PAID, and REQUIRES_REVIEW allocations are never reused, historical closed allocations are reusable, and slot exhaustion must fail explicitly. Browser input is only request UUID plus a requested decimal with at most two places. Never round or approximately match inbound transfers: destination, official token contract, amount, transaction hash, and event index are exact trusted facts. The full expected amount is credited with no fee only through Phase 5 PAID then `CreditWalletTopupAction`; scanner code never posts Ledger directly. Detection before confirmation preserves the reservation past nominal expiry. Never add private-key custody, signing, individual addresses, a real vendor, multi-chain, a suffix pool table, manual payment assignment, or production Mock routes in this phase.

Phase 9 Card Product is platform-owned PHOTONPAY/USD/REGULAR configuration with Tenant-only display name, opening fee, availability, maximum-card count, and sort order. Product settings are decimal configuration only and never move money. Do not add load-fee fields, local transaction-fee calculation or Tenant-created provider products through settings. Cardholder, issue, management and Provider calls belong to the separately authorized Card flows, never configuration saves. User views never expose CardBin/provider reference or claim guaranteed issuance. Once cards reference a product, changing provider reference, currency, or type requires a new product.

Phase 10 adds only PhotonPay Cardholder setup and initial issue of the locked regular virtual USD product. Account KYC eligibility and Provider addition remain separate; READY means addCardholder succeeded with a usable returned ID, not a separate holder review approval. The user-approved per-card revision requires independent holder materials for every new card, and permits holders different from the account owner. Never reuse account KYC documents or overwrite account identity/profile for a card. Account KYC remains an eligibility check only. For each new card application call addCardholder, persist its exact returned ID, and continue in the same opening dialog to explicit amount confirmation and openCard. Do not add a separate holder-review wait or automatically issue without financial confirmation. Show sanitized addition errors in the form; only definitive failure permits a corrected new attempt, never UNKNOWN. One new application can authorize only one issue; UNKNOWN cannot be bypassed with a new UUID. See docs/architecture/PER_CARD_MATERIALS.md. Encrypt private materials and never expose document URLs. Cardholder must be READY before any hold. Card issue atomically creates separate opening-fee and initial-funding holds, calls PhotonPay outside the transaction with the Order UUID as the stable request ID, settles only trusted success, releases only definitive failure, and keeps holds for PROCESSING/UNKNOWN. Provider Card balance is truth; local balance is a timestamped cache. Normalize and discard full PAN/CVV immediately. Production never selects Mock or falls back to it. Historically Phase 10 covered initial issue only. The approved Card management and Promotion/guarantee extensions now govern existing-card operations, transactions and user Deposit refunds. Local FX/provider-fee engines and manual issue/settle/release/balance overrides remain excluded.
