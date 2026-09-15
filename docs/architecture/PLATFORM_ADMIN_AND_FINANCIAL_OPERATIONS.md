# Platform administrator creation and manual financial provenance

Approved by the user on 2026-09-14. SaaS offers a platform administrator directory
under admin_team.read and account creation under admin_team.manage. Creation
requires an active Platform actor and membership, current-password confirmation,
and a strong confirmed password for the new account. Only PLATFORM_ADMIN and
PLATFORM_AUDITOR can be assigned; existing identities cannot be attached, reset
or elevated and PLATFORM_OWNER cannot be created here. Account and membership
creation are atomic and audited with actor ID and time, without credentials.
Company administration remains read-only for configuration. No company scope is
accepted for platform account creation.

Manual financial operations retain append-only audit records with trusted actor
ID, tenant, resource ID, operation and timestamp. Top-up manual confirmation also
retains the existing immutable manual_confirmed_by/manual_confirmed_at/request
fields in the same transaction as its one deterministic Ledger credit. SaaS
top-up lists now display that original actor and time; replays never replace them.

The audit.read-protected SaaS financial operation page reads ADMIN audit events
for wallet_topup_order and withdrawal_order with company and actor/order filters.
Company order details read only events matching both their resolved company and
resource ID. DTOs expose operation, operator ID/name and time, never arbitrary
audit payloads, addresses, credentials or provider secrets. Actor IDs remain the
historical identity if names change. Absent historical evidence is not fabricated.

Withdrawal approvals, rejections and verified settlements already record actors.
Verification request, pending, rejected and unavailable outcomes now also append
records, including rechecks. Intent commits before external calls, so a timeout
or interruption retains its submitter. No audit failure bypasses accounting;
settlement/release records remain atomic with the financial transaction. No
financial states, authorization grants, balances or provider outcomes are changed.

No existing financial request is replayed to populate the history. No new table,
manual balance API or privileged money override is introduced.

## Verification

PlatformAdministratorTest, FinancialOperationHistoryTest, Trc20SharedTopupTest and
WithdrawalTest passed their 107 cases across isolated card_ui_test runs. The
withdrawal fee tests now use the approved SaaS-only configuration route and also
assert company configuration mutation is forbidden. Coverage includes account
scope/password/role restrictions, immutable confirmation replay, company-filtered
safe history, pending and rejected checks, and preserved audit intent on timeout.
TypeScript and targeted ESLint passed. The port 8001 browser displayed the new
administrator form, the original manual top-up confirmer/time, and its financial
audit record. No administrator or money operation was created in the live sandbox
for this feature verification.
