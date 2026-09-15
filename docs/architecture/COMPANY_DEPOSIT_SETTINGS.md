# Company deposit configuration

The user approved SaaS-only configuration on 2026-09-14. Platform company detail
allows an active Platform administrator with `tenant.manage` to configure the
company's security-deposit requirement and refund waiting period. The SaaS company
configuration Business rules page also exposes the same `CompanyDepositSettings`
form and authorized deposit-settings endpoint, using the persisted configuration
company. It does not send deposit fields through the general business-settings
endpoint. Company Admin
business settings show both read-only. Its request rejects deposit amount, asset
and waiting-period input; the legacy application action also rejects non-Platform
deposit writers. The later SaaS-only company-configuration approval also moves
top-up/withdrawal flags and withdrawal fees to Platform; Company Admin remains
read-only for all of these settings.

The target company is the authorized persisted route resource, never a body
`tenant_id`. Asset comes from the persisted company default; clients cannot choose
it. Money is a bounded decimal string with at most eight places. Waiting period is
an integer number of days between 0 and 3650. `tenant_business_settings` gains a
nullable `security_deposit_refund_wait_days` with a database range check. Null means
unconfigured; the migration preserves existing deposit amounts and assigns no
default waiting period. Configuration requires both an amount and an explicit
period; 0 is an explicitly configured no-wait period, not a refund authorization.

Writes lock company then business settings and append a sanitized before/after
audit. They create no Wallet, Ledger, Card or refund events, do not change paid
deposits and never refund excess following a requirement decrease. Existing asset
freeze and financial contracts are preserved.

## Timed workflow integration

The user approved connecting the setting to new refund requests on 2026-09-14.
Requests snapshot the company waiting days and deadline; later settings changes
do not alter that snapshot. New requests block card actions, automatically freeze
cards, display the countdown and automatically refund after the deadline and
confirmed freezing. Zero card balance and card cancellation are no longer required.
Unknown operations retain principal and restrictions. See TIMED_DEPOSIT_REFUNDS.md
for worker, cancellation, accounting and legacy-request safeguards. No existing
request receives an invented deadline or automatic migration.

## Consumer history navigation

The deposit page's upper-right History link opens `/security-deposit/history`.
Existing refund-application history (pending, completed, cancelled) is no longer
embedded in the primary deposit form. The dedicated read query scopes by resolved
company and authenticated user, sorts newest first with an ID tie-breaker and
paginates 20 records per page. It exposes only ID, decimal amount, asset, safe state
and application timestamp, never provider evidence or internal accounting IDs.
This move does not add funding history or change any refund action/state.
