# UI Rules

## Account ID and promotion removal

The consumer Me page shows the signed-in User's immutable public Account ID
(`YYYYMMDD` + four random digits), with an accessible copy control and
manual-selection fallback. The internal UUID is not the displayed Account ID.
It does not expose another user's details or change authentication. See
[USER_ACCOUNT_IDS.md](USER_ACCOUNT_IDS.md). The
profile avatar is removed. Wallet shortcuts include the approved same-company transfer;
Promotion uses its real invitation and commission flows, not unavailable placeholders.
Me presents a left-aligned user card and Account information controls. See
WALLET_TRANSFERS.md, PROMOTION_REQUIREMENTS.md and USER_ACCOUNT_INFORMATION.md.

## About articles

The consumer About subpage has a centered title/back button and three separated
article links (terms, privacy, account closure), without main-page chrome. Company
Admin settings edit each plain-text language version independently. Account
closure is information only, never a fake deletion action. See [TENANT_ARTICLES.md](TENANT_ARTICLES.md).

## Administrator languages

Platform/SaaS Admin and Tenant/company Admin support Simplified Chinese and English; the default is Chinese. Every Admin layout, login, invitation, table, form, confirmation, status and safe feedback uses the shared i18next Admin namespace. Language switches persist in isolated Admin cookies and preserve unsaved form data. Consumer languages and Admin language preferences are independent. See [I18N.md](I18N.md) for the resolution and security contract.

Per-card material forms explicitly separate Card user from Billing address. Use searchable country/issuing-country/nationality/calling-code choices, cascading country/state/city selections, and a native date picker. Do not reuse account materials or add a free-text geography bypass. Keep modal headers/close controls fixed and only the body scrollable. See PER_CARD_MATERIALS.md for Provider address-field mapping and the separately licensed geography dataset.

## Single-balance client refresh

The September 2026 client refresh follows the supplied live PokePay reference's centered balance, circular shortcut row, compact content sections, and fixed bottom navigation. The client uses a centered canvas (maximum 750 px) on desktop and the full width on mobile, with the live reference's Assets / Cards / Me navigation at every width. Wallet and its financial subpages belong to Assets. All client pages inherit this shell; Tenant and Platform Admin layouts remain independent.

Home and Wallet show one borderless available-balance hero with a hide/show control and a fixed asset label. There is no account carousel, currency selector, exchange, or scan action. The user's explicit 1:1 live-reference instruction replaces the earlier flat header: asset and authentication pages use the observed soft mint background blend. Other pages use the reference's warm neutral background. The surrounding neutral canvas, white content sections, dark circular shortcuts, and spacing are scoped to `.user-theme`.

The four asset shortcuts are Top up, Withdraw, Deposit, and Transfer, each using its actual backend eligibility. Me uses a left-aligned profile card and icon groups with the approved Promotion and Support destinations. Promotion has real invitation, team/daily/direct reporting and commission history/transfer flows under PROMOTION_REQUIREMENTS.md. Unimplemented third-party linking, coupons and notifications must not gain fake actions.

Home displays up to five real recent activities from the existing tenant/user-scoped Wallet query. Wallet keeps its real deposit requirements and full recent activity. Activity labels are friendly descriptions, never internal event codes, and balance amounts continue to be decimal strings. No mock balance, fake activity, second spendable account, or currency conversion is introduced by this UI change.

The live site was inspected via its Flutter accessibility tree as well as rendering: at 750px content width its shortcut row starts around y=257, circles are 112px, each action cell is 167.5px, and the three-column bottom navigation is 106px high. The header uses a 44px brand symbol and 34px brand text; the balance currency is inline. These dimensions scale with the client container and preserve minimum touch targets. User-supplied screenshots are comparison evidence, not the source of the layout. Login and available unauthenticated Card/Me screens were inspected directly; financial and account subpages behind the reference's login could not be inspected without its credentials. Existing local business flows retain their logic while sharing the observed typography, round inputs, and pill controls.

The user explicitly excluded reference pages requiring sign-in from this revision. Do not remove corresponding local business flows: keep their implementation and apply the shared shell only. The observed Me profile avatar starts at y=131, its main pill at y=347, and the first group at y=479 at 750px content width. Logged-in local identity replaces guest login messaging in the same composition. Public Card introduction uses a static, flat, tenant-branded illustration and a link to login or existing application requirements, never a made-up network logo, PAN, balance, fee, or immediate-issue promise. Existing owned cards and sensitive application/review flows remain intact. The reference's marketing assets are not copied; the local asset banner advertises only the existing Cards destination.

## Shared design contract

The shared User design contract uses a PokePay-inspired mobile baseline for information hierarchy, compact financial presentation, surface rhythm, and bottom-navigation behavior without copying PokePay branding, assets, proprietary screens, or visual identity. User desktop carries the same language into a wider layout. Future Cards use this shell with Revolut-style card controls. Tenant Admin remains Stripe + Brex inspired and Platform Admin remains Stripe inspired. All surfaces continue to use the single project Design System.

The shared direction is light, clean, neutral, professional financial software. Information architecture and interaction patterns may learn from Stripe/Brex admin, Wise wallet, and Revolut card experiences without copying brand visuals.

Use shadcn/ui primitives plus focused project wrappers only. Do not add a second UI framework. Status always combines text and color using SUCCESS/WARNING/DANGER/INFO/NEUTRAL semantics. Tenant primary color affects primary controls, links, active navigation, and selected states; it never overrides risk colors.

PublicLayout, UserLayout, TenantAdminLayout, and PlatformLayout remain separate surfaces. User pages are mobile-first, with 375 px the primary acceptance width; 768 and 1440 px must also be fully usable. The revised mobile navigation contains only Assets, Cards, and Me; the former separate Home and Wallet items are merged into Assets. Cards remains disabled or a truthful empty state until its business module exists. Admin is desktop-first with sidebar/topbar and a mobile drawer; 375 px remains readable with basic actions.

The default User theme was extracted from the live reference's current green accent, pale neutral background, white surfaces, near-black text, muted gray text, and fine borders. It is represented only through scoped tokens: `--user-primary` (`#39AD8D` default), `--user-primary-readable`, `--user-primary-hover`, `--user-primary-soft`, `--user-background`, `--user-surface`, `--user-surface-muted`, `--user-text`, `--user-text-secondary`, `--user-border`, and the three User radius tokens. A configured `tenant_branding.primary_color` replaces only `--user-primary`; hover and soft states use `color-mix`, control foreground chooses the higher-contrast neutral, and a readable brand shade is derived for small text. Success, warning, danger, error, risk, and restricted semantics remain independent.

Phase 2 registration is a progressive contact -> code -> password flow, never one giant form. OTP fields support paste, numeric keyboards, autocomplete, labels, and visible errors. The authenticated User Dashboard shows only real account/contact, KYC, and Wallet state; it never displays mock balances or cards. Restricted users retain clear links to Wallet read-only state, account security, and logout.

Phase 3 adds a mobile-first `/kyc` status/submission flow and desktop-first `/admin/kyc` review queue/detail. End Users see derived review state but not internal OCR progress, object keys, or document images. Admin detail displays backend-masked identity and OCR hints; original images remain behind recent authentication and separate permission. Review decisions use durable feedback and explicit confirmation.

Phase 4 `/wallet` follows the shared consumer hierarchy: real available balance hero, security-deposit current/required/remaining, then flat business-friendly activity. Raw Wallet status and internal holds are hidden from Users. API and Domain amounts remain eight-place decimal strings; display precision is a presentation concern and never uses JavaScript numeric arithmetic. Tenant Admin Wallet/Ledger pages follow Stripe/Brex-like read-only information density and never contain add/subtract/adjust/correct/edit actions.

User Dashboard is not a technical status console. It shows the most important current state and one next step; after Wallet activation the real available balance is the visual focus. End User KYC copy is simplified and never exposes raw state codes, OCR status/provider/confidence, application identifiers, or internal review metadata. Me uses the observed three-column icon groups on a borderless neutral canvas; financial details continue to use clear labeled rows. Unfinished Withdrawal, Deposit refund, and Card actions are hidden or explicitly unavailable and never lead to fake flows.

White-label V1 allows logo, brand name, favicon, primary color, support information, and basic public copy through safe tokens. It does not allow custom CSS/JS/React, free themes, dashboard layout builders, or page builders.

Phase 5 makes Top-up the first real Wallet quick action only when operational eligibility and Provider availability are true. `/wallet/top-up` uses a 375-first amount and review flow; browser return remains read-only and distinguishes Processing, externally received but not credited, Completed only at CREDITED, and safe Failure copy. History uses business language rather than Provider/Ledger terms. `/admin/topups` and detail follow the desktop-first Stripe/Brex information density and are permission-gated read-only views with no status or balance mutation controls. Withdrawal, Deposit payment/refund, and Card actions remain unfinished and hidden.

The Top-up submit intent keeps one stable request id through review and disables repeat submission while navigation is pending. Admin detail separates external payment, internal credit, and Provider exception status. A post-credit Provider exception never makes the User's completed Top-up appear incomplete.

Phase 6 exposes an amount-first Security Deposit section and a simple confirmation screen with Required, Already deposited, Deposit now, and Available after values supplied by the backend. There is no amount input and no optimistic balance update. Insufficient Available balance leads to the existing Top-up flow. A committed funding Entry appears as one user-facing `Security deposit` debit activity, while Tenant Admin sees Current, Required, Remaining, and Satisfied as read-only values with no financial controls.

Phase 7 adds a mobile-first fixed-rail Withdrawal flow: amount and masked saved destination, a review step showing exact USDT amount/network/address/remaining availability, then truthful Pending, Approved, Verifying, Success, Rejected, and Cancelled states. Users never select asset/network or see internal hold/clearing language. Tenant Admin gets a desktop-first queue/detail workflow for review, recent-auth address reveal, approval/rejection, transaction-hash submission, verification, and final chain evidence. Full addresses never appear in lists, and financial override controls do not exist.

Phase 8 Top-up is a mobile-first USDT amount entry followed by shared TRC20 payment instructions. Instructions emphasize the exact required transfer and show Requested, Wallet receives, network, copyable public address, copyable amount, and a 30-minute countdown. Detection changes the friendly state to Payment detected / Confirming transaction and suspends expiry copy; CREDITED alone is Top-up complete. Expiry never silently creates replacement instructions. History distinguishes requested and exact amounts. Tenant Admin Top-up pages expose chain and settlement facts read-only, with no assign, mark-paid, mark-credited, transaction-edit, or balance controls.

Phase 10 replaces the Card preview with a real mobile-first sequence: approved identity -> Card setup form -> truthful Cardholder pending/action-required state -> opening review -> creating/unknown -> safe Card list. The approved per-card revision collects fresh holder/contact/address/identity details and uploads in the first step of the application dialog. It permits different holders and never pre-fills account identity. Only approval of that application's materials reveals the financial confirmation step. Money review shows opening fee, initial Card balance, Wallet total, available balance, and USD received; all arithmetic uses decimal strings/BigInt minor units, never JavaScript floating point. Insufficient balance links to Wallet top-up. PROCESSING/UNKNOWN explicitly tells the User not to create another request.

Normal Card UI shows only product name, masked PAN, expiry, safe status, and Provider-balance cache. It never exposes Provider/Cardholder identifiers, raw status/reason JSON, full PAN, CVV, Ledger accounts, or internal hold terminology. Tenant and Platform Admin Card pages are permission-gated read-only tables with safe Cardholder, Issue Order, and masked Card details; they provide no success, settlement, release, creation, reveal, reload, freeze, cancel, or balance controls.

The consumer Card page supports virtual cards only. Display existing cards directly and available products in the application dialog; do not render a Virtual/Physical card selector or an unavailable physical-card placeholder.
The My Cards header now opens the available-product application form through an “Apply for a card” dialog rather than rendering it inline. Preserve existing readiness/unresolved-order gates and the second explicit financial confirmation. Each product's form owner stays mounted outside the dialog content lifecycle, retaining its decimal amount and request UUID when dismissed, reopened, or translated. Only an accepted server response may advance to a new intent. The dialog is bounded to the viewport, scrolls internally, traps focus, has a translated close control, and cannot be dismissed during submission.

Toast is for low-risk feedback such as saved/copied. Important failures and critical actions use durable inline feedback or an AlertDialog with explicit consequences and confirmation. Internal Ledger terminology and raw provider codes/JSON never appear to users. Motion is limited to short dialog, drawer, dropdown, hover, and navigation transitions. Accessibility requires labels, keyboard use, visible focus, adequate contrast, readable type, and non-color-only state.

## Money presentation

Company KYC settings offer MANUAL/AUTOMATIC under the explicitly approved company
policy. Automatic mode has a durable warning and an explicit acknowledgement before
saving. Existing pending work remains manual; automatic acceptance does not claim
document authenticity or PhotonPay verification. Show system approval provenance in
admin review details and immediate completed status to successful new applicants.

Money presentation uses two decimal places with string/BigInt half-up rounding, shared across consumer and admin surfaces. Database, Ledger and provider amounts retain their full decimal precision. Formatting an input must not emit a change: saving unrelated settings preserves the exact original amount; only an explicit amount edit replaces it. Copyable provider payment instructions and all financial request payloads retain their exact required amount. This display convention is not a financial precision migration.

## Consumer i18n

All consumer/public system copy uses the shared i18next catalog, including activity labels, friendly status, forms, confirmations and errors. English, Simplified Chinese, Malay and Spanish are complete resources; tenant-enabled languages determine the available choices. A language change preserves financial form intent and request IDs. Brand/product names and user-authored content remain unchanged. See [I18N.md](I18N.md) for precedence, persistence, date formatting and CI guards. Never fix mixed-language UI by hardcoding another language in JSX.
# Homepage reference-layout exception (2026-09-11)

User authorized high-fidelity public homepage layout/style work from https://pokepay.cc/.
This applies only to Landing and `.marketing-home` CSS: large branded hero, three
original decorative card designs, section proportions, product grid, interactive
management showcase, application steps, FAQ and footer. Authenticated and Admin
design contracts remain intact. No third-party logo/network/merchant assets,
license numbers, customer endorsements, service guarantees or fictional balances.
Reference license/promises are not our verified credentials and are not published.
All calls to action use existing local routes or section anchors; public login
and company login remain available, and eligibility/verification gates are intact.

## Black / gold / green homepage hero (2026-09-15)

User approved replacing only the public homepage header and hero with a deep-green
background (#091510), warm-white text, champagne-gold CTA/border (#D6BD79), and one
horizontal tenant-branded card. This supersedes the three-card hero composition
above; later product artwork and all authenticated/admin surfaces stay unchanged.
The final user-selected artwork is the high-resolution black/gold Spec Pay card
with a dotted world map and globe, supplied on 2026-09-15. It supersedes both the
initial screenshot and the intermediate hand-drawn SVG. The unchanged local PNG
is displayed with CSS cropping of only the outer scene; printed card contents,
branding and network artwork are retained as the user-selected illustration,
not claims about actual issued cards. Page/navigation branding still uses company
settings. The header and notice now use charcoal and muted gold; the hero uses a
warm gold ambient background, warm secondary text and a champagne-gradient CTA.

At 375px, brand/copy, a 327px card and CTA form a natural-height stack with 24px
side padding. Tablets retain the stack with a 520px maximum card. From 1024px,
the hero uses text/CTA on the left and a card up to 620px on the right inside a
1200px content width. The illustration preserves its cropped 1488:870 proportions.
Long company brands wrap in headings. Primary controls remain at least 44px,
the hero CTA at least 48px. Existing routes, locale policy and eligibility rules
remain authoritative; no API/data or authenticated/admin styling changes apply.

On 2026-09-15 the user removed the public homepage company-sign-in entry. The
mobile menu and footer no longer link to /admin/login; direct company login and
its authentication rules remain available. This supersedes the earlier requirement
to expose company login on the homepage.

On 2026-09-15 the user standardized generic visible card terminology to
“万事达U卡” / “Mastercard U Card”, including homepage artwork labels, FAQ,
consumer card application/empty states and administrator explanatory copy. The
shared locale catalogs contain equivalent Malay and Spanish wording. The consumer
catalog DTO's cardType display label follows this terminology; protocol enums,
provider cardScheme data, BIN capabilities and eligibility/routing are unchanged.
This is presentation terminology only, not a conversion of existing cards or
provider-network configuration. User-authored product names and history are not
rewritten.

## Consumer product polish (2026-09-15)

User approved the client review in `docs/reviews/2026-09-15-client-product-review.md`
with the explicit display rule: external top-ups and withdrawals use USDT;
internal balances, card amounts, fees, deposits, transfers and commissions use a
`$` prefix. `systemMoney` formats decimal strings, never floating point. This is
presentation only: USDT Ledger and USD card assets, order snapshots and financial
contracts remain unchanged. Foreign merchant transaction amounts retain their
reported currency; no conversion is fabricated.

`WalletActivityQuery` is a read-only, tenant/user-scoped business projection of
sealed events. Card issue/management and withdrawal steps are grouped by their
existing reference type/id before the 20-activity limit; the amount is the exact
net USER_AVAILABLE change. Expandable steps identify available-balance changes,
including zero settlement deltas. No events or balances are changed by this query.

Card management exposes its existing history and stable order status reads, with
pending counts and capability-based freeze/unfreeze/holder/cancel under More.
Amounts start empty; server validation, quote economics, UNKNOWN recovery, private
reveal and refund restrictions remain authoritative. System order timestamps use
the company timezone. Client transaction times now use system settlement time or first-recorded time
per CARD_TRANSACTION_READS.md; unzoned source time stays internal. The 2026-09-15
follow-up removes issuing/integration wording from consumer copy in all four locales.

Consumer direct-member search is scoped to the viewer's direct invitation relation.
Funded filtering means current positive security-deposit balance; per-member
commission shows only awards to the viewer from that member's own funding events,
not team earnings. No new level assignment authority is introduced.

Phone entry shares the calling-code directory and exact server-side verification
flows. Contact verification displays expiry/resend times but cannot bypass server
cooldowns, password checks or uncertain delivery restrictions. Balance visibility
stores only a boolean per user in sessionStorage for the current browser tab;
amounts and credentials are never persisted by that preference.

## Promotion layout consolidation (2026-09-15)

The approved promotion UI retains the dark-green available-commission area and
consolidates team totals into the overview at `#team-summary`. The authenticated,
operational `/promotion/team` route redirects to that anchor; daily, direct and
commission history keep their routes. These three destinations use equal icon
links. Other promotion areas use white backgrounds, two-column statistics, 22px
page headings, 14px text and at least 13px secondary copy with 44px touch targets.
Date filters reuse the existing company-timezone queries; raw timezone identifiers
are replaced by expandable explanatory text. New result pages scroll to the list
start, preserving active filters and resetting the relevant page when filters change.
Direct-member rows separate ID/level, deposit/own commission, and joined time/edit.
Edit errors remain next to the active form. No new statistics or financial states
are added: team commission includes the team, history and daily commission show only
the viewer's awards, and transfer records retain their signed decimal amounts.
Existing transfer confirmation, idempotency, refund restrictions and level permissions
are unchanged. Test funds and level changes remain isolated, never live UI checks.
