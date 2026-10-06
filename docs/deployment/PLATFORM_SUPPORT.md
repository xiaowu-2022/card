# Platform support release

Deploy the backend and built React assets together, then the matching uni-app H5
artifact using the existing company build pipeline. No app packaging is needed for
this H5 acceptance. Keep company/API hostname scope as configured.

1. Apply `php artisan migrate --force` through the normal deployment process.
   `2026_09_27_160000_add_platform_support.php` adds nullable nicknames on admin users
   and messages. It grants `support.read`, `support.send`, `support.agents.manage`
   to existing PLATFORM_OWNER and PLATFORM_ADMIN roles without replacing other grants.
   Do not run development seeders against deployed data.
2. Rebuild React with `npm ci && npm run build`. Build each configured uni-app H5
   with the existing company release procedure in `UNI_APP_PACKAGING.md`.
3. Refresh application route/config caches using the existing deployment procedure.
   Confirm Owner/Admin sees **Operations → Customer support**. Other roles receive
   no new access; custom roles require an explicit permission decision.
4. Confirm the all-company list, company/customer filters, existing conversation
   history, staff tab and own nickname form. Opening/searching must not create
   conversations, mark messages read or contact customers.
5. Use synthetic accounts in an isolated test environment for send/image/retry tests.
   Verify two concurrent first sends reuse one conversation; a retry does not increase
   unread; nickname updates affect future replies only. Do not send release-check
   messages to real customers.

The company Admin inbox still requires exact company `support.manage`. SaaS sends
require both `support.read` and `support.send`; nickname administration requires
`support.read` and `support.agents.manage`. The UI staff list includes eligible
company staff. Support images use the configured existing OSS integration; the
release does not modify OSS settings, migrate files or replay provider operations.

No queue/cron additions or external notifications are introduced. On rollback,
prefer rolling back code while retaining the additive columns and nickname history.
Migration down removes nickname columns (and thus snapshots), so do not run it as a
routine code rollback. Existing permission rows are intentionally retained.

## FAQ robot release (2026-10-06)

1. Apply `2026_10_06_180000_add_support_bot.php` before releasing the updated API,
   administration and consumer builds. It adds FAQ/settings/transition tables,
   BOT sender support and conversation mode/revision. Existing chats default HUMAN;
   every company switch defaults off. Do not seed or replay customer messages.
2. Build admin assets with a supported Node runtime (verified with Node 22), and
   build company uni-app H5/native packages using the existing company build process.
   Native customers need an updated package to see the handoff button; existing
   clients can still display the compatible support message DTO. Enable bot mode
   only when the intended client versions provide handoff.
3. In Customer support → Bot and FAQ, first maintain public FAQs. Select a company
   for private entries/overrides, preview representative questions, then explicitly
   enable its robot. Preview does not send any customer messages. No scheduler,
   queue, external AI credential or provider configuration is required.
4. Validate sends, ambiguous questions, image-only fallback, handoff and finish with
   synthetic accounts in an isolated environment. Verify company isolation, stable
   retry UUIDs, stale-finish rejection and bot unread counts. Existing live chats
   remain human until explicitly finished by staff.

Rollback must retain the schema and bot message history. The migration intentionally
refuses destructive `down()`. Disable company robots before rolling back compatible
application code; never remove bot messages or try to replay conversations. A version
that predates BOT sender support is not a safe full rollback after bot messages exist.

Checks: `SupportBotTest`, `SupportChatTest`, `PlatformSupportTest`, `ConsumerApiTest`;
`tests/Browser/support-bot.mjs` uses offline Chrome/WebKit API fixtures and built admin
assets. Set `UNI_PARITY_ORIGIN` to a loopback preview of the built H5 (default 5239).
No production migration, deployment, company enablement or real customer message is
performed by these checks.

Local verification for this change: 43 backend tests / 412 assertions passed;
admin and uni-app typechecks and builds passed; offline Chrome/WebKit verified
handoff retry UUIDs, waiting state, FAQ editing/search scope, preview and stale
End service retry handling. The broad i18n suite passes 76/78; the remaining checks
concern the existing PartnerStockReport chevron and missing KYC retry copy, outside
this support change. The support translation check passes.


## Service hours and support workspace release (2026-10-06, superseded account flow)

1. Apply `2026_10_06_190000_add_support_workspace.php` after the bot migration and
   before publishing the updated API/admin/consumer assets. It adds hours, dedicated
   account metadata and quick replies, the SUPPORT_AGENT role and incremental
   `support.hours.manage` / `support.replies.manage` Owner/Admin grants. Do not run
   production seeders or create accounts/schedules automatically.
2. Build administration and the company-specific uni-app H5/native releases. Refresh
   route/config caches using the normal deployment process. No scheduler, queue,
   external AI service or provider setup is required.
3. Platform Customer support → Service hours sets each company's weekly schedule in
   its configured timezone. Unconfigured companies remain 24/7. Staff → Add support
   account creates a dedicated login and assigned companies. Give the intended agent
   the Platform host's `/support-agent/login` URL and credentials through the usual
   private channel. Account edits/reset/disable revoke existing workspace sessions.
4. Platform Quick replies maintains company templates. Agents manage My quick replies
   and their support nickname in the independent workspace. Templates only fill a
   draft; staff must click Send. All reads and release inspection remain read-only.
5. Verify offline/new-handoff rejection, existing human conversations, assignment
   isolation, password reset revocation and template insertion using isolated synthetic
   accounts. Never send deployment-check messages to real customers.

Rollback retains all additive tables, assignments and history; the migration refuses
destructive `down()`. Review access and disable dedicated accounts before reverting
workspace code. Do not remove historical chat snapshots or replay business operations.

Validation uses `SupportWorkspaceTest` plus the existing support/auth/scope/consumer
API suites, admin/uni-app typechecks and builds, and `tests/Browser/support-workspace.mjs`
with offline Chrome/WebKit fixtures. The browser harness checks schedule save, account
creation, direct login, draft caret insertion, edit guards, visible send controls and
consumer offline handoff. Set `UNI_PARITY_ORIGIN` to the loopback H5 preview (5239 by
default). Tests do not deploy, migrate production, configure real companies or send
real customer messages.

Local verification: 63 backend tests / 574 assertions passed; admin and uni-app
TypeScript checks and builds passed. Targeted ESLint and diff whitespace checks passed.
Offline Chrome and WebKit both passed the workspace/consumer checks, including the
viewport assertion that Send remains visible. Build output retains the existing
admin chunk-size advisory. No production deployment has been performed.


## Consumer user support workbench release (2026-10-06, current)

Apply `2026_10_06_200000_add_consumer_support_agents.php` after migrations 180000 and
190000 before publishing API/admin/H5 assets. This is additive and works whether the
previous dedicated-account feature was deployed or not. Retain old migrations and
all historical identities/messages/templates; never run production seeders, migrate
accounts by email, or auto-enable consumer users. No scheduler or provider changes.

Publish the administration and company H5 builds; rebuild the company native package
for the App entry. Refresh route/config caches: `/support-agent/login` and all old
workspace/account-creation endpoints are no longer available. Existing dedicated
support identities remain barred from ordinary admin login and support operations.
Choose intended consumer users in Platform Users → More actions → Make support agent;
each uses their existing consumer sign-in and My account → Support workspace. Multiple
users share their company's queue. Remove support access takes effect on the next API
request, without ending normal consumer sessions. Configuration/FAQ/shared templates
remain in Platform Customer support; schedules are not changed by this migration.

Validate with isolated fixtures: grant/revoke, self/foreign-company denial, two agents,
text/direct-image retry, nickname snapshots, customer unread, End service revisions,
shared/personal template isolation and disabled accounts. The offline browser harness
`tests/Browser/support-workspace.mjs` checks the user-list grant, My account entry,
mobile inbox/chat, explicit send, nickname, personal templates and revoked access.
No real customer messages, production account edits or financial writes are release tests.

Rollback retains the additive sender schema and history. Code predating the new sender
cannot safely interpret new agent messages; prefer a forward fix. Do not restore old
independent routes as an implicit fallback or drop consumer-agent history.

Local verification for the consumer-account replacement: 68 support/authentication/API
feature tests passed (603 assertions). Admin and uni-app typechecks/builds and targeted
ESLint passed. Offline Chrome/WebKit passed the new workbench flow and the existing bot
flow. The broader PlatformUserFinancialsTest ran two checks successfully; its remaining
check fails during its legacy KYC approval setup (KYC_OCR_REQUIRED), before reaching the
user-list query. No KYC/financial behavior or fixtures were changed to bypass it. Build
output retains the existing admin chunk-size advisory. No production migration or
release, real customer message, or automatic user support grant was performed.

## Presence and editable consumer-agent replies (2026-10-06)

Apply `2026_10_06_230000_add_support_presence_and_message_revisions.php` before publishing
the new backend and H5/App bundles. This adds bounded current-user presence, immutable
message revision history and version-specific customer read receipts. No backfill,
original-message rewrite, scheduler, live customer message or financial replay is needed.
Rebuild administration to display deleted/edited reply markers in its shared transcript.
Publish H5 and rebuild native packages for long-press actions and foreground heartbeats.
Keep all additive tables on rollback; older clients cannot acknowledge edited versions.
