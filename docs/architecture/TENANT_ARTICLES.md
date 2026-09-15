# Tenant-owned About articles

The consumer Account About entry opens `/about`, a centered-heading/back-button
subpage with exactly three links: terms of service (`terms`), privacy policy
(`privacy`), and account closure (`account-closure`). Each opens `/about/{article}`.
These routes retain the existing authenticated, operational end-user and Tenant
surface policies. This does not expand restricted-user access.

## Ownership and storage

`tenant_articles` belongs to the Tenant domain. Columns: UUID id, tenant_id (FK,
restricted deletion), article_key, locale, body (plain text, <= 50,000 characters),
created_at and updated_at. A unique key covers tenant_id/article_key/locale;
database checks constrain the three fixed article keys and en/zh-CN/ms/es locales.
There are no publishing/review states, custom slugs, custom layouts, or templates.
Saving publishes that language immediately. Saving empty content makes the
language version unavailable; no default legal policies are generated or seeded.

## Administration and isolation

Company Admin -> Settings -> About us articles (`/admin/settings/articles`).
The existing tenant_settings.manage permission and active Admin + exact Tenant
membership checks protect both reads and writes. Host resolves TenantContext;
client Tenant/id/key/locale body selectors are prohibited. The fixed article and
locale are allowlisted route segments, never tenant selectors.

Controller -> UpdateTenantArticleAction -> TenantArticle. The action locks the
trusted Tenant row and upserts only its tenant/key/locale tuple, preventing first
save races. It records a sanitized TENANT_ARTICLE_UPDATED Audit event containing
the article key, locale and whether content is configured, never the article body.
Reads use explicit allowlisted fields, not unrestricted model serialization.

## Languages and rendering

Admin chrome uses independent en/zh-CN i18next resources (Chinese default).
Editors support each of the four supported consumer content languages, including
preparing a disabled locale before enabling it. Unsaved editors remain mounted
when changing article or language. Only Tenant-enabled consumer languages can be
read. The resolved consumer locale selects exactly one stored version: there is
no cross-language or cross-Tenant fallback. Missing versions show a localized
unavailable message. Tenant-authored text is not automatically translated.

React escapes the article text; CSS preserves line breaks and wraps long content.
HTML, CSS, JavaScript, links and Markdown are not interpreted. No uploads or
third-party editor/design system are introduced. Consumer subpages reuse the
750px user-theme canvas without the main-page brand header or bottom navigation.
Admin styling remains separate.

## Non-goals

Account closure is an informational article, not a deletion request or a real
account/card cancellation flow. Article reads/saves never change User, KYC,
Card, Wallet, Ledger, Security Deposit or Provider state. Legal drafting,
consent tracking, actual closure, refunds and custom CMS pages remain out of scope.
