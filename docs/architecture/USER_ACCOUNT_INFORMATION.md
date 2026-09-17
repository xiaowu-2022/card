# User account information

The 2026-09-11 user request adds a left-aligned profile card and consolidates display
name, email/phone replacement and password changes under Account information.
`/account/security` remains the compatible read route. Existing restricted password
access remains; new profile/contact writes require ACTIVE User and Tenant.

## Account navigation (2026-09-15)

The user approved a compact Me page: a left-aligned profile with masked contact,
copyable account ID and derived verification status; one three-column grid for
Account and security, Promotion and Customer support; and a Settings row. This
supersedes the earlier Wallet/Deposit shortcuts on Me. Assets home retains wallet
history and deposit entry points; financial completion links still return to Assets.

GET `/account/settings` uses the same authenticated, operational company/user gates
as Me. It renders language selection, About and sign out, using existing locale and
logout POST contracts. Header language/support shortcuts are hidden only on Me and
Settings. No database state, financial behavior or restricted-route grant is added.
About returns to Settings and its articles return to About.

The subsequent user-requested minimal visual treatment uses a white Me canvas,
a borderless identity block and fine separators instead of separate floating cards.
The common-feature heading is visually hidden, retaining its accessible section
label and the three-column icon grid. Only Me receives these styling overrides.

`/account/security` retains its URL and restricted-account access, with the title
Account and security. Both it and Me expose only KycStatusService's company/user
scoped status, never identity documents or identifiers. Verification remains `/kyc`.
Only the exact query `from=account-security` selects `/account/security` as its back
link and survives a successful submission; all other values use `/account`. No
arbitrary return URL is accepted. The verification prompt never grants submission
eligibility, which remains controlled by the existing server KYC gates.

Display name modifies only the tenant-owned UserProfile display_name (1–80 characters),
never legal identity, KYC, cardholders, invitations or money. Both mutations lock
Tenant then User and append sanitized audit events without contact/password/code data.

## Contact replacement

`user_contact_changes` is a dedicated User-domain challenge aggregate, not a
registration challenge. Composite User/Tenant FK and tenant/user/request UUID
uniqueness enforce ownership and stable send intent. Columns include encrypted new
destination, keyed destination/session/credential digests, HMAC OTP, attempts, expiry,
delivery uncertainty, cancellation and consumption timestamps. Only explicit masked
DTOs reach the page. No plaintext code or session binding is persisted in this table.

An authenticated active user proves their current password before sending a six-digit
cryptographic OTP to the normalized new email/E.164 phone using company Proton/Aliyun
configuration. Missing transport fails closed. The new contact must be unused within
the company; global contact lookup is forbidden. A host-session random binding plus
credential fingerprint binds the challenge to its initiating browser, current password
and previous same-channel contact. A password change invalidates outstanding proofs.

Creation serializes on Tenant then User, persists intent, commits, then invokes the
transport. Replaying the same request never sends twice. Delivery uncertainty is set
before transport (including crash uncertainty); the original OTP remains valid and
resend is blocked until expiry. Definitive delivery failure cancels the intent. A new
request observes configured resend cooldown, per-user/destination hourly caps and
company email daily recipient limits, then cancels older same-user/channel challenges.
Cache throttles are defense in depth; challenge attempts and send limits use PostgreSQL.

Confirmation requires explicit acknowledgement that the previous contact stops being
a login identifier, the same browser/user/company, unexpired unconsumed uncancelled
proof, unchanged credentials, and a matching OTP within the configured attempt budget.
Incorrect attempts commit independently of the returned error. Verification and contact
replacement/verified_at/consumption/audit are one transaction; existing company-contact
unique constraints also defend registration races. Consumption retries acknowledge only,
never reapply an old contact. Other-channel contact, account ID, password, User status,
KYC and all financial data remain unchanged. The authenticated session ID regenerates
after success. There is no admin contact override or unverified direct update endpoint.

The UI never persists password/OTP in local storage. Sensitive form fields are excluded
from flashed input and ordinary logging. Page responses are private/no-store. Tests use
isolated database/mail/SMS fakes; no real OTPs or user credential changes are used for UI QA.

## Consumer subpage headers (2026-09-16)

Only the three bottom-navigation home routes (`/dashboard`, `/cards`, `/account`)
show the shared company header. Query strings and trailing slashes do not change
this classification. Me continues to omit its header language/support shortcuts.
All other consumer subpages use a centered title and left back control without the company,
language or support header. Existing parent routes, bottom navigation, standalone
article headers and authentication/availability gates remain unchanged. Restricted
status returns to the existing permitted Account and security route. This supersedes
the earlier shared-header visibility only; financial flows and Admin/auth layouts
are unchanged.

Subpage titles use equal reserved space on both sides so the back control does not
shift the title off center. Long localized titles wrap within the center column.

## Me promotion shortcut (2026-09-17)

The Me profile omits the avatar and shows the current effective promotion level
to the right of the name/contact block in a horizontal row, alongside an icon-and-text Upgrade link to `/promotion/membership`.
The rank and qualification come from the tenant/user-scoped AccountActivationStatus.
Accounts without current deposit or paid-period qualification show 未激活 (Account inactive);
qualified accounts without a paid rank show Ordinary member. The link only opens the existing
membership flow and retains its eligibility, quote and payment confirmation rules.
