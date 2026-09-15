# Database Schema Plan

Password recovery (2026-09-11): additive migration 001600 creates
`user_password_resets`, a tenant-scoped, browser-bound, HMAC-OTP-only proof with
encrypted destination and nullable existing-user mapping. See USER_PASSWORD_RECOVERY.md.

Account session hardening (2026-09-11): additive migration 001500 adds nonnegative
server-owned `users.session_version` default 0. It contains no session token or device
data and is hidden from model serialization. See USER_AUTH_RULES.md. No money or
existing identity fields are rewritten.

KYC company-policy extension (2026-09-11): migration 001000 adds AUTOMATIC to the
supported local policy and `kyc_applications.automatically_approved` default false.
Only automatic APPROVED records may omit an administrator; terminal review
provenance is immutable. See KYC_RULES.md. No existing application is auto-approved
or reassigned by the migration, and no financial schema changes are introduced.

## Promotion and guarantee lifecycle (authorized 2026-09-11)

See [Promotion contracts](PROMOTION_REQUIREMENTS.md) for `promotion_levels`,
`promotion_members`, `promotion_company_invitations`, immutable challenge binding,
`promotion_funding_events`, `commission_awards`, `commission_transfers`,
`initial_deposit_intents` and `security_deposit_refund_requests`. The additive
migrations preserve every existing balance and historical record. USER_COMMISSION
is a standalone Tenant/User/USDT Ledger account; TENANT_COMMISSION_CLEARING is a
dedicated negative-permitted company expense counter-account. Exact deferred
Ledger evidence constraints protect funding, commission transfer and refund receipts.
There is no company budget balance table, direct commission payout or manual adjustment.

2026-09-11 authorized extension: [Card management](CARD_MANAGEMENT.md) supersedes historical Phase 10 exclusions only for the existing PhotonPay regular virtual USD product. It adds scoped management orders, a verified notification inbox, and safe transaction read models; all Ledger, tenant and sensitive-data safety rules remain in force.

## Company Proton SMTP configuration

`tenant_email_settings`: unique Tenant FK, enabled, public sender, encrypted SMTP Token,
daily recipient limit and configuration version. `registration_challenges.email_delivery_uncertain`
persists the pre-send boundary. `tenant_email_test_requests` stores idempotent test intent and
PENDING/ACCEPTED/REJECTED/UNKNOWN outcomes with a recipient HMAC, never a raw recipient/token.
No user or financial state is changed by settings/tests. See [TENANT_EMAIL.md](TENANT_EMAIL.md).

## Company Aliyun SMS configuration

`tenant_sms_settings`: UUID id, unique Tenant FK, disabled-by-default switch,
encrypted AccessKey pair, signature/templates, bounded resend interval and OTP TTL.
`registration_challenges.sms_delivery_uncertain` persists an unconfirmed send before
HTTP so a timeout/crash cannot trigger a blind resend of a live challenge.
See [TENANT_SMS.md](TENANT_SMS.md) for the explicit SMS contract extension and recovery rules.

## Public User account IDs

`users.account_id`: immutable, server-assigned `VARCHAR(12) NOT NULL`, shaped
as creation date in the Tenant timezone plus four random digits. Unique within
Tenant; the User UUID primary key and all existing foreign keys are unchanged.
The additive migration backfills retained users and serializes future allocation
on the Tenant row. No new tables or financial states. See [USER_ACCOUNT_IDS.md](USER_ACCOUNT_IDS.md).

## Tenant About articles extension

`tenant_articles`: UUID id; Tenant FK; fixed article_key (terms/privacy/account-closure);
locale (en/zh-CN/ms/es); plain-text body capped at 50,000 characters; timestamps.
Unique tenant_id/article_key/locale, checked keys/locales/length. No publishing
states or financial behavior. See [TENANT_ARTICLES.md](TENANT_ARTICLES.md).

Phase 0 creates Tenant/Admin/Audit foundations. Phase 1 extends Admin authentication without adding future business tables. Phase 2 adds exactly `users`, `user_profiles`, `user_preferences`, and `registration_challenges`. Phase 3 adds exactly `kyc_applications` and `identity_records`. Phase 4 adds exactly `wallets`, `ledger_accounts`, `ledger_entries`, and `ledger_postings`. UUID is the universal business primary-key strategy. Status is uppercase PHP backed enum plus VARCHAR/CHECK. Timestamps are timezone-aware. Core deletion is RESTRICT, not cascading history removal.

PostgreSQL constraints include global unique hostname/slug/admin email/permission/role, one primary domain per tenant, one default locale per tenant, valid state checks, nonnegative deposit requirement, and explicit Platform-null/Tenant-non-null membership scope. `admin_memberships.scope_id` references `tenants.id` when present, and the composite role/scope foreign key guarantees that Platform roles cannot back Tenant memberships or vice versa. The Application layer preserves at least one enabled tenant locale through `UpdateTenantLocalesAction`; this cross-row cardinality rule is intentionally not misrepresented as an ordinary row constraint.

Phase 1 adds a case-insensitive unique Admin email index, an Admin status CHECK for ACTIVE/SUSPENDED, and a partial unique index permitting at most one PENDING invitation per Tenant and case-insensitive email. Invitation tokens remain hashes in `token_hash`; `accepted_by`, `accepted_at`, and `cancelled_at` preserve lifecycle attribution. The existing unique hostname and partial primary-domain/default-locale indexes remain authoritative.

Phase 2 constraints include Tenant-scoped nullable email/phone uniqueness, at least one User contact, E.164 phone shape, User/challenge enum checks, and composite `(user_id, tenant_id)` foreign keys preventing profile/preference cross-Tenant relationships. Registration challenges store a 64-character HMAC digest, verification attempts, expiry, lifecycle timestamps, and consumption separately from VERIFIED status. A partial unique index permits at most one PENDING row per Tenant and destination; application locking classifies expired PENDING rows before replacement and reuses valid VERIFIED/unconsumed state.

Phase 3 KYC applications store explicit NATIONAL_ID/country, dedicated-key encrypted identity, a canonical Tenant+document-type+country+number 64-character HMAC, private object keys, independent OCR/review states, review attribution, and immutable timestamps. Composite foreign keys bind applications and direct-predecessor resubmission links to the same Tenant/User. A partial unique index permits one PENDING application per User. Identity Records bind to the same Tenant/User/source application, are unique per `(tenant_id,user_id)`, and index—but never uniquely constrain—`(tenant_id,identity_hash)`. Phase 3.1 data migrations re-protect existing APP_KEY ciphertext/recompute hashes and constrain Tenant identity limits to `1..100`; the original Phase 3 migration remains unchanged.

Phase 4 Wallets are unique by `(tenant_id,user_id,asset_code)` and use a composite foreign key to prevent cross-Tenant User ownership. Ledger Accounts use `NUMERIC(20,8)` cached balances, exact User Wallet ownership FKs, partial Tenant-system uniqueness, fixed account-type ownership, status, asset, and negative-policy checks. Ledger Entries are unique by Tenant/Event Key and contain canonical hashes, business references, optional same-Tenant/same-asset non-self reversal references, `sealed_at`, and no business status. Posting composite FKs force Tenant/Asset agreement with both Entry and Account; deltas are non-zero and account duplication within an Entry is forbidden.

Phase 4.1 adds only `2026_09_09_000510_harden_wallet_ledger_integrity`: it safely aligns existing development Deposit assets, backfills existing valid Entries as sealed, and adds no table. A Posting can be inserted only for an unsealed parent. The only Entry update is initial sealing; later inserts/updates/deletes are rejected. Deferred triggers require sealing, at least two Postings, exact zero sum, and Account cache equality with sealed Posting sums. Wallet and Account financial identity columns are immutable, Tenant default asset freezes after Wallet/Account creation, and Deposit asset must equal it.

Phase 5 adds exactly `wallet_topup_orders`, `payment_provider_transactions`, and `payment_provider_events`. Top-up Orders bind Tenant, User, Wallet, and asset through composite foreign keys; `(tenant_id,request_id)` is unique and immutable financial identity includes the canonical request hash and positive `NUMERIC(20,8)` amount. Provider Transactions are one-per-Order with stable unique Provider request identity and normalized states. Provider Events have unique Provider event identity, a same-Tenant transaction mapping, digest-only payload evidence, immutable normalized facts, and independent processing status. A credited Order may reference its exact same-Tenant/same-asset sealed Ledger Entry.

Phase 5.1 adds no table. `2026_09_10_000610_harden_payment_settlement_integrity` adds initiation attempt/lease timestamps, a partial unique Ledger-link index, and a financial-state CHECK. CREDITED requires paid/credited times plus its Ledger reference and cannot transition away or be relinked; PAID/REFUNDED require paid time and every non-CREDITED state has no credit time/link. Existing composite foreign keys enforce same Tenant/asset, while Provider request ids, non-null transaction ids, and Event ids remain unique within Provider scope.

Phase 7 adds exactly `withdrawal_destinations`, `withdrawal_orders`, and `withdrawal_transaction_attempts`. Destinations bind Tenant/User through composite keys, fix asset/network to USDT/TRON, store ciphertext plus keyed HMAC and a safe mask, and reject address identity mutation. Orders bind the exact Tenant/User/Wallet/destination/asset/network, use positive `NUMERIC(20,8)`, unique Tenant request ids, canonical request hashes, and same-Tenant/same-asset Ledger links. Successful transaction and financial identity fields are immutable. Attempts permanently and globally reserve `(TRON, tx_hash)` so one chain transfer cannot be reused across Orders; composite foreign keys prevent cross-Tenant attachment.

Phase 9 adds exactly `card_products` and `tenant_card_product_configs`. Platform products have unique `(provider,provider_product_ref)` identity and are constrained to PHOTONPAY, USD, REGULAR, positive provider minimums of at least 20, and DRAFT/ACTIVE/INACTIVE. Tenant configurations are unique by `(tenant_id,card_product_id)`, use `NUMERIC(20,8)` nonnegative opening fees, a positive bounded card limit, ACTIVE/INACTIVE status, and RESTRICT foreign keys. No load-fee, User Card, provider credential, price-version, or balance table is introduced.

Phase 10 extends `user_profiles` with the smallest complete-or-null Cardholder name, birth-date, nationality, and residential-address group and adds exactly `provider_cardholders`, `card_issue_orders`, and `user_cards`. Provider Cardholders are unique by Tenant/User/Provider and external Provider identity. Issue Orders bind Tenant/User/USDT Wallet/product/Tenant config/Cardholder through composite ownership keys, snapshot fixed PHOTONPAY/USD identity and `NUMERIC(20,8)` amounts, and uniquely reserve both browser and Provider request identities. Safe User Cards are one-per-Issue-Order with unique Provider Card identity, masked PAN/last4 checks, and an optional nonnegative Provider-balance cache. Deferred financial-state validation requires holds for every Order, settlement references only for SUCCEEDED, and release references only for FAILED. No PAN, CVV, Provider payload, identity number, KYC object key, Card reload, Card transaction, or Provider credential table is added.

Approved per-card revision: `2026_09_10_001100_scope_cardholder_materials_to_each_card` removes account-level holder uniqueness and adds a Tenant/User-scoped request UUID, product binding, HMAC request fingerprint, encrypted material envelope and submission revision to `provider_cardholders`. `card_issue_orders.cardholder_request_id` is composite-FK-bound to the same Tenant/User/product/holder request. A partial unique index permits one Order per new application. Insert/update guards prevent new legacy-style issues or reassignment of an application's immutable identity. Legacy rows and their original financial links are retained unchanged. Documents are encrypted on the private disk; their keys and personal details are inside hidden encrypted envelopes. This supersedes the Phase 10 account-profile/KYC-reuse assumption; see `PER_CARD_MATERIALS.md`.

Future migrations are added only with their owning phase:

- Payment: completed in Phase 5; future migrations extend it rather than recreating these tables.
- Withdrawal: completed in Phase 7; future migrations extend the three Phase 7 tables rather than recreating them.
- Security Deposit: `security_deposit_refund_requests`; never a mutable `security_deposits` balance table.
- Card Product: Phase 9 creates `card_products` and `tenant_card_product_configs`; availability and user limits are represented by this lean pair, with no extra tables.
- Provider: Phase 10 creates `provider_cardholders`; future connections, events, and wider operation storage require their owning phases.
- Card: Phase 10 creates `card_issue_orders` and `user_cards`; `card_load_orders` and optional transaction snapshots remain future work.
- Later only: agents/relations, commission rules/records, refund orders, risk cases/rules, tenant subscriptions/invoices, FX orders.

Core tenant business tables carry `tenant_id NOT NULL`, including future users, wallets, all Ledger rows, KYC/identity, top-up/withdrawal, issue/card/load, and deposit refund requests. Platform-owned resources are the explicit exception via owner_scope and nullable owner_tenant_id. JSONB is limited to audit/provider/unpredictable metadata and is not a universal settings substitute.

Once later phases accumulate data, existing migrations are immutable historical artifacts; schema changes use new migrations and update this document.

Production has no `card_inventory` or `card_inventory_import_batches` core tables. Any local card-pool fixture belongs only to Mock/development infrastructure and cannot become a production issuance source.
# Sequential promotion invitation extension (2026-09-11)

Migration 001100 replaces company/member code format with six digits and adds
`promotion_invitation_counter` (singleton transactional next value) and
`promotion_invitation_aliases` (immutable tenant-scoped legacy owner mapping).
Existing UUID relationships and financial rows are preserved. See
`PROMOTION_INVITATION_CODES.md` for allocation, capacity and migration contracts.
# Wallet transfer extension (2026-09-11)

Migration 001200 adds immutable `wallet_transfers` receipts with same-company,
same-asset sender/recipient wallet ownership FKs and bidirectional sealed entry
evidence. No new balance store. See `WALLET_TRANSFERS.md`.
## Support extension (2026-09-11)

`support_conversations` and `support_messages` add company/user-scoped text and
private-image chat with stable send request IDs and per-conversation sequences.
See [SUPPORT_CHAT.md](SUPPORT_CHAT.md) for schema, encryption and access constraints.
# Account information extension (2026-09-11)

`user_contact_changes` stores tenant/user-owned, browser-bound, encrypted-destination
OTP intents for verified contact replacement. See [USER_ACCOUNT_INFORMATION.md](USER_ACCOUNT_INFORMATION.md)
for columns, expiry/consumption semantics, unique constraints and restricted access.
