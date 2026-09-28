# SaaS system settings

2026-09-27: consolidate global asset, domain, SMS, email, OSS and KYC settings under one desktop-oriented System settings sidebar entry. Administrators and financial audit records remain separate.

`/platform/settings` redirects to the first permitted category: assets for `tenant.manage`, otherwise OSS for `storage.manage`. Guests go to Platform login; inactive accounts, company-only administrators and users without either permission cannot enter.

`PlatformSettingsLayout` provides a shared heading and route-backed tab navigation. Each tab retains its existing GET URL and loads only that category's props. Old bookmarks, reload and browser back/forward remain valid. Tabs are filtered by existing permissions; the original controllers, mutation routes, validation, CSRF, audit and encrypted-secret handling remain unchanged. No password reconfirmation or combined save-all operation is introduced. Company-specific configuration screens remain separate, including company domain assignment.

Acceptance: PlatformSettingsNavigationTest, PlatformKycSettingsTest, PlatformDomainManagementTest and PlatformNotificationProfilesTest passed (24 tests, 361 assertions); frontend/i18n 77 passed; TypeScript, changed-file ESLint, PHP Pint and React build passed. The existing large JS chunk warning remains. Desktop browser inspection covered all six tabs, the consolidated sidebar and current-tab highlighting. No configuration was saved and no live provider request was made during UI checks.

Evidence: `artifacts/platform-settings-20260927/`.
