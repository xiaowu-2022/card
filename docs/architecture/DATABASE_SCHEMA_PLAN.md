# Database Schema Plan

Phase 0 creates only `tenants`, `tenant_domains`, `tenant_branding`, `tenant_locales`, `tenant_business_settings`, `tenant_kyc_settings`, `admin_users`, `admin_memberships`, `admin_invitations`, `roles`, `permissions`, `role_permissions`, and append-only `audit_logs`. Phase 1 reuses these tables and adds only a new migration: `admin_users.status`, `admin_users.last_login_at`, and invitation acceptance/cancellation metadata. No future business table is introduced. UUID is the universal business primary-key strategy. Status is uppercase PHP backed enum plus VARCHAR/CHECK. Timestamps are timezone-aware. Core deletion is RESTRICT, not cascading history removal.

PostgreSQL constraints include global unique hostname/slug/admin email/permission/role, one primary domain per tenant, one default locale per tenant, valid state checks, nonnegative deposit requirement, and explicit Platform-null/Tenant-non-null membership scope. `admin_memberships.scope_id` references `tenants.id` when present, and the composite role/scope foreign key guarantees that Platform roles cannot back Tenant memberships or vice versa. The Application layer preserves at least one enabled tenant locale through `UpdateTenantLocalesAction`; this cross-row cardinality rule is intentionally not misrepresented as an ordinary row constraint.

Phase 1 adds a case-insensitive unique Admin email index, an Admin status CHECK for ACTIVE/SUSPENDED, and a partial unique index permitting at most one PENDING invitation per Tenant and case-insensitive email. Invitation tokens remain hashes in `token_hash`; `accepted_by`, `accepted_at`, and `cancelled_at` preserve lifecycle attribution. The existing unique hostname and partial primary-domain/default-locale indexes remain authoritative.

Future migrations are added only with their owning phase:

- User: `users`, `user_profiles`, `user_preferences`, `registration_challenges`.
- KYC: `kyc_applications`, `identity_records`.
- Wallet/Ledger: `wallets`, `ledger_accounts`, `ledger_entries`, `ledger_postings`.
- Payment: `wallet_topup_orders`, `payment_provider_transactions`, `payment_provider_events`.
- Withdrawal: `withdrawal_orders`, `withdrawal_destinations`.
- Security Deposit: `security_deposit_refund_requests`; never a mutable `security_deposits` balance table.
- Card Product: `card_products`, `card_product_availability`, `tenant_card_product_configs`, `user_card_limits`.
- Provider: `card_provider_connections`, `provider_card_products`, `card_provider_operations`, `card_provider_events`.
- Card: `card_issue_orders`, `user_cards`, `card_load_orders`; optional later snapshots/transactions.
- Later only: agents/relations, commission rules/records, refund orders, risk cases/rules, tenant subscriptions/invoices, FX orders.

Core tenant business tables carry `tenant_id NOT NULL`, including future users, wallets, all Ledger rows, KYC/identity, top-up/withdrawal, issue/card/load, and deposit refund requests. Platform-owned resources are the explicit exception via owner_scope and nullable owner_tenant_id. JSONB is limited to audit/provider/unpredictable metadata and is not a universal settings substitute.

Once later phases accumulate data, existing migrations are immutable historical artifacts; schema changes use new migrations and update this document.

Production has no `card_inventory` or `card_inventory_import_batches` core tables. Any local card-pool fixture belongs only to Mock/development infrastructure and cannot become a production issuance source.
