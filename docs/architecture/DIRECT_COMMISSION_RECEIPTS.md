# Direct USDT commission receipts (approved 2026-09-17)

## Effective contract

Activation and annual commission awards debit TENANT_COMMISSION_CLEARING and
credit the beneficiary's USDT USER_AVAILABLE through LedgerWriter in the source
business transaction. Amounts, differential rates, immutable reward snapshots,
first-ever activation deduplication and automatic annual returns are unchanged.
Refund requests, cancellation, completed refunds and re-funding do not restrict
receipt or ordinary use of earned commissions. No commission freeze or clawback.

CommissionAccounts coordinates missing-wallet provisioning under Tenant then User
locks. A positive first receipt may create the USDT Wallet and its normal accounts
without KYC, with SYSTEM/COMMISSION_RECEIPT_WALLET_CREATED audit. Reads and zero
shares never create wallets. Existing wallet/user statuses are never changed.
Withdrawal, transfer, exchange, card and promotion purchases retain their own
KYC, lifecycle, balance and operational checks. Receipt is not spending eligibility.

Source idempotency/locking plus immutable award evidence prevent duplicate awards.
Provisioning, source funding, reward postings and audits roll back atomically on
failure. Database evidence validates recipient, tenant, USDT asset, amount and wallet
ownership. New credits to USER_COMMISSION are prohibited at the database boundary.
There is only one receipt mode; no per-user or per-period compatibility branch.

## Read models and surfaces

- Invitation data shows lifetime personal income and automatic USDT credit copy;
  there is no commission balance, transfer button or refund restriction notice.
- The asset cumulative-commission tile still links to invitation data. Valuation
  uses available, holds and deposit funds only; cumulative income is never added.
- Income totals combine annual/activation shares with unlinked historical awards,
  deduplicating linked activation evidence. Rebates and consolidation are not income.
- Commission details show only income. `tab` query is prohibited; POST `/promotion`
  no longer exposes a transfer operation. Date/type/source filters are preserved.
- Wallet activity labels activation and annual receipts separately. The development
  consolidation is labelled as commission credited to USDT, not a new reward.
- SaaS users show cumulative commission separately from available USDT. Company
  commission expense is derived from the clearing postings of award events; it
  never counts the same reward twice or treats consolidation as another expense.
- Consumer copy uses the shared en/zh-CN/ms/es catalogs. Refund copy still explains
  card freezing/restoration and normal timed repayment.

## Development consolidation and deployment

Migration `2026_09_17_000300_direct_commission_receipts` changes receipt evidence,
prohibits new credits to retired accounts, and creates immutable
`commission_balance_consolidations`. It performs **no financial transfers**. Old
accounts, entries, postings and transfer receipts remain immutable evidence and
have no new manual-transfer workflow.

The user separately authorized consolidating existing development balances.
`promotion:consolidate-development-commissions` rejects all environments/databases
except local/testing with `card_mock` or `card_ui_test`. It has no web endpoint.
Preview defaults to count, exact total and per-user amounts; optional `--tenant`
limits scope. Run only against the explicitly authorized isolated database:

```sh
php artisan migrate --force
php artisan promotion:consolidate-development-commissions
php artisan promotion:consolidate-development-commissions --execute
php artisan promotion:consolidate-development-commissions
```

Each source account is transferred in its own atomic business transaction using
`commission_consolidation:{source_account_uuid}`. LedgerWriter posts the exact
positive source balance to the owned USDT wallet. The immutable receipt stores
amount, asset, source/destination, entry and processing time; SYSTEM audit is in the
same transaction. Source identity and Ledger entry are unique. Repeated execution
returns the existing receipt or finds no positive accounts; failures leave no
partially provisioned wallet or money movement. Database evidence requires exactly
two matching sealed postings and a zero remaining source balance.

Before execution save per-user owned balances, cumulative income and immutable
Ledger row hashes. Afterwards verify owned funds and cumulative income unchanged,
source balances zero, wallet increase equals receipts, history unchanged, and
repeat execution has no side effects. This command is not a production migration,
not a generic credit endpoint and not authorization for replaying rewards.

Normal deployment runs migrations and the frontend build; no new queue dependency
or scheduler task is required. Existing `promotion:recover` remains for automatic
annual returns. No external provider or real funds operation is used for acceptance.

## Acceptance evidence (2026-09-17)

- PaidPromotion, Promotion, navigation, wallet database constraints and asset
  ownership suite: 70 passed / 662 assertions, including receipt provisioning,
  refund states, concurrent first receipt/consolidation, audit failure rollback,
  retirement guard, tenant scope, wallet status and company/platform read models.
- Wallet activation, transfer and withdrawal regressions passed. Platform finance
  assertions now distinguish wallet funding from actual cumulative award income.
- Four-language frontend suite: 73 passed; TypeScript, affected-file ESLint,
  production build and diff whitespace checks passed.
- Browser: dashboard, invitations and commission details checked at 375/768/1440
  widths with no document overflow; tile navigation and amount hiding verified.
  Invitations show automatic credit copy and commission details have no transfer tab.
- Local card_mock migration applied, then previewed and consolidated 61 accounts,
  exactly 36,000.00000000 USDT. Per-user owned funds and cumulative income matched
  the pre-execution snapshot. All 1,287 prior entries and 2,574 prior postings kept
  their exact row hashes; 61 entries/122 postings and matching SYSTEM receipts were
  added. Every source is zero; a repeated execute reports zero accounts.
- Current test account: cumulative 16,880.00 USDT unchanged; available USDT
  25,649.84 and total asset valuation 25,949.84 (including 300 deposit).
- No server deployment, external provider request or real financial transaction.

## Assets placeholder (2026-09-17)

The user replaced the Assets cumulative-commission tile with a non-interactive
Wealth management (理财) entry. The approved 2026-09-18 wealth feature replaces the placeholder with a real link; see WEALTH_MANAGEMENT.md.
It precedes Security deposit and shows only its icon and label, without an amount or currency row.
Commission reporting remains in Promotion; wallet funds and total valuation are unchanged.
This supersedes the earlier Assets commission-tile presentation only.
