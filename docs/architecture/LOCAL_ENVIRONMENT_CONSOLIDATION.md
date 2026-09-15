# Unified local acceptance runtime (2026-09-15)

The user selected the existing port 8001 data as authoritative for final local acceptance, served through port 8000. This changes local startup and entry points only. It does not merge accounting databases, convert provider identities or authorize live financial testing.

## Runtime

- `compose.yaml` now defines one loopback application, `app`, at port 8000.
- Both `app` and `deposit-refunds` use the existing `card_mock` PostgreSQL database, explicit local environment, mock card driver, existing session cookie, blank real card-provider credentials, and preserved persistent encryption keys.
- The old `card-mock` and `card-mock-refunds` containers are stopped. No service binds port 8001. `compose.card-mock.yaml` is a compatibility include with no services.
- The original `card_platform` database is retained offline. No rows or financial histories are copied between databases. PostgreSQL volume and private storage remain intact.
- Tenant host resolution and membership guards still separate SaaS, company operations and users; using one port never combines company scopes.
- Only the already-authorized deposit-refund worker resumes, using its same database and business state. No history scan, seed replay or queue replay is part of cutover.

## Validation boundary

PHPUnit forces the `testing` environment and `card_ui_test` database. RefreshIsolatedDatabase rejects refresh outside that exact environment/database. Browser regression targets port 8000, and default local account passwords are 123456; automated PHP fixtures retain their existing independent password.

Final checks include configuration validation, same-database counts before/after cutover, Ledger reconciliation, isolated PHP tests, translations, type checking, production asset build, and read-only UI checks of all three surfaces. Mutating feature scenarios execute only inside the isolated automated database.

## Recovery

Keep timestamped restricted PostgreSQL dumps of both original databases before cutover. Configuration rollback can restore the old service bindings against their original databases; never restore a dump over newer business writes as an ordinary rollback. Preserve encryption keys and storage alongside the database. Production deployment remains separate and must use real provider isolation rules.

## Acceptance evidence

- Cutover preserves 2 tenants, 502 users, 11 card rows and 1,286 Ledger entries in `card_mock`; no Ledger entries were created by this work.
- Restricted backups are stored at `storage/app/private/local-consolidation-20260915/card_mock.dump` and `card_platform.dump` (mode 0600, excluded from Git). Both PostgreSQL archive manifests were readable.
- Full post-cutover suite: **1,073 tests passed, 8,896 assertions**. Frontend suite: **67 passed**. TypeScript, ESLint, Vite production build and formatting of changed PHP files passed. Vite retains its existing large-bundle advisory.
- Updated older acceptance assertions to the approved SaaS-only configuration routes and current account/card navigation; permission rejection, private-data handling and money assertions remain covered.
- Browser verified client login with the local password, preserved balance and promotion team data, card transaction loading, wallet activity, SaaS company listing, company administrator login and read-only business settings at port 8000. No real financial or configuration mutations were performed during browser checks.
- Ledger reconciliation reports no cached balance mismatch. The original `card_platform` database remains offline with its original 2 users, 1 card and 6 Ledger entries.
