# Local promotion centre fixtures

2026-09-16: This legacy assignment fixture runner is retired after the paid-promotion migration. Existing fixtures and their financial history remain intact. The runner now fails before writing; new payment scenarios use isolated PaidPromotionTest fixtures and browser-only preview data. See PAID_PROMOTION.md.

On 2026-09-15 the user requested eight reward levels (50 through 120 in steps of
10), 500 distinct descendants of local account 202609131303, a mixture of paid and
unpaid guarantees and historical dates for checking Promotion. This explicitly
permits creating dated NEW synthetic fixtures in the isolated card_mock database;
it never permits rewriting existing users, referral links or sealed Ledger history.

`LocalPromotionFixtureSeeder` is explicitly invoked by
`scripts/seed-local-promotion.php TENANT_UUID USER_UUID PLATFORM_ACTOR_UUID`.
It is absent from ordinary DatabaseSeeder and has no web route. It requires
local/testing, card_mock/card_ui_test, the mock payment driver and an active
Platform actor with tenant.manage. A session advisory lock prevents concurrent runs.
The stable batch is recorded in metadata-only STARTED/COMPLETED audits; replay of
a completed batch is read-only. Count and deposit requirements must match on resume.

The target is assigned level 120; 100 direct and 400 second-level descendants use
lower ranks, including unranked second-level users. Unique fixture.invalid contacts,
random inaccessible credentials and clearly marked synthetic KYC image fixtures
never reuse real identity documents. Three of every five members (300/500) receive
exactly the current server deposit requirement; one has a verified empty wallet;
one has no KYC/wallet. New join times span the preceding 60 days, with parents
created before children and funding after joining. A process-local clock sets new
records' original timestamps. No existing history is edited.

Funding uses the existing isolated MockPaymentProvider -> create/query/credit
Wallet top-up -> initial deposit allocation -> EarnDepositCommission flow. Every
credit, guarantee and commission still goes through LedgerWriter and stable
business IDs. No generic balance setters, direct Ledger writes, admin adjustments,
real payment/card provider calls or commission transfers are introduced. Queue jobs
are suppressed ONLY in this fixture process and equivalent actions executed for
these exact new records; no historical job is replayed. Existing unresolved mock
payment rows are not fabricated successful on resume. Runtime provider bindings,
credentials and environment files are unchanged. Configured levels and the
resulting synthetic local commissions are deliberately visible in Promotion.

The historical run exposed a reporting mismatch: commission_awards.created_at uses
the database insertion clock, while sealed commission entries retain their actual
posted_at. Promotion daily totals/movements and commission earned history now use
the owned, tenant-matched commission Ledger entry's posted_at. This aligns them
with the company cost book and never rewrites any award or Ledger timestamp.

Verified local run: Tenant A account 202609131303 has 500 fixture descendants,
100 direct / 400 indirect, 300 funded / 200 unfunded. Joins span 2026-07-17 through
2026-09-13 (company timezone). Each funded user deposited the configured 300 USDT:
90,000 total principal, 36,000 total company commission expense, and 16,880 earned
by the target account. All five direct-member pages contain 20 records. On
2026-09-13 the UI shows 9 invitations, 5 activations, 1,500 deposits and 600 team
commission. Today has zero fixture events. Ledger and payment reconciliation pass;
13 related tests pass with 158 assertions. Legacy promotion tests now use an active
Platform actor for configuration, retaining the separate Tenant operational actor.
Re-running this exact completed batch returns the report without adding records.
