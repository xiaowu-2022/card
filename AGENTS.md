# Virtual Card SaaS Agent Rules

These rules are mandatory for every future change. If a requested feature requires changing a locked rule, report an `ARCHITECTURE CONFLICT` with the current rule, required change, reason, and affected modules; pause only the conflicting work.

## Tenant safety

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

- Mock and real Card Providers must implement `CardProviderInterface`; business code depends only on that contract.
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
- KYC review transitions only from PENDING, approval enforces the Tenant identity limit under a database lock, and no KYC action creates Wallet/Ledger/Deposit/Card state.
- V1 requires the Security Deposit asset to equal the Tenant default asset. Once a Wallet or Ledger Account exists, the Tenant default asset is frozen until a separately designed migration/multi-asset workflow.
- KYC document access requires separate permission, current Tenant scope, recent Admin password confirmation, short-lived signed private access, and a sanitized audit event.

## UI

- Use shadcn/ui primitives and approved project components; do not introduce another design system.
- User surfaces are mobile-first. Admin surfaces are desktop-first but must remain usable at 375, 768, and 1440 px.
- Do not expose Ledger/clearing/posting terms or raw provider errors to end users.
- Do not add arbitrary tenant CSS/JS, custom React uploads, dashboard builders, or page builders.
- Avoid glassmorphism, large gradients, neon/crypto styling, decorative 3D cards, and complex animation.
- User mobile UI follows the shared PokePay-inspired baseline for information density and navigation behavior without copying its brand, assets, or visual identity; this is a design contract, not a second UI framework.
- End User pages are mobile-first, with 375 px as the primary User UI acceptance width.
- Never expose raw business, Ledger, KYC provider, or Card Provider states to End Users.
- Never create fake feature buttons for unfinished financial capabilities; reveal actions only when their business flow exists.
- Do not wrap every User section or activity row in a Card. User financial UI prioritizes balance, the primary next action, and activity.
- Admin UI remains separate from the consumer mobile design language; User theme tokens must not leak into Tenant Admin or Platform Admin.
- Critical actions require explicit warning and confirmation; a toast is insufficient.

## Phase boundaries

Phase 7 adds only fixed USDT-TRC20 manual withdrawal: immediate exact hold, Tenant Admin manual external send, independent exact blockchain verification, and exact settle/release. Never let the client select the rail, Wallet, Tenant, User, fee, or hold amount. Full withdrawal addresses require dedicated encryption/HMAC, masked ordinary output, and recent-auth Admin reveal without address content in logs or audit. A transaction hash can belong to only one Order; pending/timeout/unavailable verification never means failed or settled. Mock chain verification is local/testing only. This phase does not contain automated/custodial payout, private keys, monitored crypto deposit, bank/fiat or multi-chain rails, Security Deposit refund, cards, real providers, agents, commissions, manual adjustments, or billing.

Phase 8 top-up is fixed to USDT on TRON/TRC20 using one configured shared public address. The server alone allocates `0.01..0.99` through a PostgreSQL-locked least-used strategy; PENDING, PROCESSING, UNKNOWN, PAID, and REQUIRES_REVIEW allocations are never reused, historical closed allocations are reusable, and slot exhaustion must fail explicitly. Browser input is only request UUID plus a requested decimal with at most two places. Never round or approximately match inbound transfers: destination, official token contract, amount, transaction hash, and event index are exact trusted facts. The full expected amount is credited with no fee only through Phase 5 PAID then `CreditWalletTopupAction`; scanner code never posts Ledger directly. Detection before confirmation preserves the reservation past nominal expiry. Never add private-key custody, signing, individual addresses, a real vendor, multi-chain, a suffix pool table, manual payment assignment, or production Mock routes in this phase.

Phase 9 Card Product is platform-owned PHOTONPAY/USD/REGULAR configuration with Tenant-only display name, opening fee, availability, maximum-card count, and sort order. Opening fee and provider minimums are decimal configuration only and never move money. Do not add load-fee fields, local transaction-fee calculation, Tenant-created provider products, User Cards, Cardholder, issue/load, Provider credentials, or PhotonPay API calls. User views never expose CardBin/provider reference or claim a card can be issued. Once future cards reference a product, changing provider reference, currency, or type requires a new product.

Phase 10 adds only PhotonPay Cardholder setup and initial issue of the locked regular virtual USD product. Our KYC approval and Provider Cardholder READY remain separate. Reuse encrypted identity/private KYC documents in memory and backend-to-backend uploads; never duplicate identity data or expose document URLs. Cardholder must be READY before any hold. Card issue atomically creates separate opening-fee and initial-funding holds, calls PhotonPay outside the transaction with the Order UUID as the stable request ID, settles only trusted success, releases only definitive failure, and keeps holds for PROCESSING/UNKNOWN. Provider Card balance is truth; local balance is a timestamped cache. Normalize and discard full PAN/CVV immediately. Production never selects Mock or falls back to it. Phase 10 has no existing-card reload, reveal, freeze, unfreeze, cancel, transactions, Security Deposit refund, local FX/provider-fee engine, or manual issue/settle/release/balance controls.
