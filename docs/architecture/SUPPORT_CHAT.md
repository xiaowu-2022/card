# Company support chat

User-requested 2026-09-11: the consumer header support icon opens authenticated
first-party text/image chat. Each company receives and replies in its own Admin inbox.
This is a standalone Support module; it never calls providers or changes money,
KYC, cards, commissions or user lifecycle state.

- Host-resolved company plus authenticated user identifies the one conversation.
  GET does not create conversations. First message creates one transactionally.
- Admin inbox, reads and replies require an ACTIVE AdminUser and exact ACTIVE
  company membership with `support.manage`. Grant this to TENANT_OWNER,
  TENANT_ADMIN and SUPPORT only. Platform membership is never sufficient for these
  company Admin routes; the separately authorized Platform entry is described below.
- `support_conversations`: UUID, company/user composite ownership, unique company
  user, monotonic integer message sequence, last sender and activity timestamp.
- `support_messages`: UUID, composite conversation/company ownership, per-thread
  sequence, exactly one user/admin sender, request UUID, encrypted text, timestamp,
  optional encrypted image object key, keyed image digest and allowlisted MIME.
  User sender must equal the conversation owner through a composite foreign key.
- Sending locks Tenant, User, then conversation. UUID retries with identical text
  return the existing message; changed text/target is rejected. Sender, company,
  user and sequence are server-selected. Messages have no edit/delete endpoint.
- Text is 1–2000 characters, escaped on render, encrypted using Laravel's existing
  APP_KEY encryption at rest, hidden from ordinary model serialization and excluded
  from request flashing/logging. Do not send passwords, OTPs, PAN/CVV or documents.
  Key rotation must preserve decryption of existing conversations.
- Explicit allowlisted responses only. User sees their messages and the support
  nickname snapshot (generic Customer support when null), never internal administrator
  names, identifiers or email addresses.
- Messages use sequence-cursor pagination (50 at a time); inbox uses 30-row pages.
  Poll every 5 seconds only on the visible current conversation/inbox. Failures
  show connection feedback; draft and request UUID survive polling and retry.
- User follow-up permits a single JPEG/PNG/WebP image per message, alone or with
  text. Limit 5 MiB, 20 megapixels, 6000 pixels per side; inspect bytes/MIME/dimensions
  server-side, never trust the filename. No SVG, arbitrary files or remote URLs.
  Encrypt image bytes on the private non-public disk; authenticated, no-store
  image routes check exact conversation ownership / company support permission.
  Original filenames, paths and hashes are not serialized. Image messages are
  idempotent by request UUID plus text and keyed image digest. Do not delete staged
  objects on an ambiguous transaction result: a crash may leave an unreferenced
  encrypted object inaccessible through any route; future retention cleanup must
  prove it is unreferenced before removing it. No public symlinks or signed URLs.
  The 2026-09-12 fix cleans the current attempt's staged object only after the
  transaction body failed, rollback returned to the caller's transaction level,
  and a Tenant-locked query proves its preallocated message UUID is absent. The
  exact company/UUID path is validated before deletion. Existing images and
  idempotent retries are untouched. Commit exceptions, process crashes, outer
  transaction failures after a successful action, or failed cleanup remain
  conservative retention cases; no blind historical sweep is introduced.
  **2026-09-27 storage amendment:** configured OSS uploads and migrated images follow
  `OSS_IMAGES.md`, including public-readable uploaded image objects. Authenticated
  chat/image endpoints retain ownership checks; legacy local ciphertext remains
  readable until migrated. This replaces the private-disk-only requirement above.
- No external chat scripts, AI
  replies, fabricated staff presence, delivery/read guarantees or notifications
  outside this app. Inbox distinguishes awaiting reply from replied using actual
  last sender; this is not a read receipt. No new restricted-user safe routes.
- New migrations add tables and permission grants; no existing data is rewritten.
  Tests use the isolated database, never send test conversations to live customers.

## Consumer unread badges (2026-09-26)

Support replies now have a per-conversation `user_read_sequence`. Only administrator
messages beyond that cursor count as unread; user messages and message retries do not.
`POST /support/read` acknowledges the highest sequence delivered to the visible chat
page, under a scoped conversation lock. GET remains read-only. Older acknowledgements
cannot move the cursor backwards, and a concurrent reply beyond the submitted sequence
stays unread. Marking read does not change conversation timestamps or financial data.

The shared authenticated unread endpoint returns `count` (inbox) and `supportCount`.
The support tile/header badge shows support only; the message tile/bell shows inbox only;
the bottom navigation's Me icon displays their sum. Zero is hidden, totals over 99 show
`99+`. The existing visible-page 30-second/focus refresh is shared, and successful support
read acknowledgement refreshes badges immediately. Chat polling continues every five
seconds; hidden pages do not acknowledge messages. No support text is copied into inbox.

Migration `2026_09_26_130000_add_support_read_cursor.php` initializes existing
conversations at their current last sequence because historical read evidence did not
exist. New support replies count from rollout. Verification: SupportChat + Inbox feature
suites pass (26 tests/219 assertions); the offline browser harness verifies support=4,
inbox=3, Me=7, then support reading leaves Me=3, plus four-language/responsive checks.

## SaaS support center and nickname snapshots (2026-09-27)

Platform `/platform/support` is a separate desktop workspace beside notifications.
Its 30-row all-company list filters company, account ID/email and last-sender state
(awaiting reply = USER, replied = ADMIN), ordered by activity time and stable ID.
The selected chat reads 50 messages with the existing sequence cursor. Visible
latest-page polling refreshes chat and inbox together every five seconds; history
pages do not jump to the latest messages. Read-only staff have no composer.

Proactive contact selects a company and searches its active customers. Opening a
customer without a conversation is read-only. A successful first message creates
the existing unique company/user conversation under Tenant/User locks. Platform,
company Admin and customer messages share the same records and monotonic sequences.
Request UUID retries compare the same sender, target, text and image digest; they
never create another message or snapshot a new nickname. Failed drafts retain their
request UUID. Changing customer remounts the composer to prevent draft misdelivery.

Platform routes require active `platform_admin` authentication plus `support.read`;
send, candidate search and self-name updates additionally require `support.send`.
The staff tab and editing other names additionally require `support.agents.manage`.
Every company/user or company/image pair is validated server-side. Active tenant and
customer status is checked under the send locks for proactive Platform messages.
Company Admin routes remain guarded by exact active company `support.manage`
membership; there is no Platform bypass in the company service entry point.

`admin_users.support_name` is a nullable, maximum-30-character plain-text nickname,
independent of login name and shared across the account's companies. Support-capable
staff can edit their own nickname from the support inbox. SaaS staff managers can
edit eligible Platform or company staff on `/platform/support/agents` (30-row pages).
Updates use authenticated CSRF-protected POST, existing throttles and a
`SUPPORT_NAME_UPDATED` audit with actor, target, before/after nickname and timestamp.
No repeat password. Staff without support permission are not nickname targets.

`support_messages.support_name` snapshots the current name on each new admin reply.
Null or blank means the existing localized generic label; old messages are neither
renamed nor backfilled. The allowlisted message DTO exposes only nullable
`supportName`, never the sender's administrator name, email or internal ID.
React H5, uni-app H5 and company Admin render this field as text without translation
or HTML interpretation. A platform reply increments the existing support unread
count; it does not enter inbox messages. Acknowledgement remains scoped to displayed
sequences and cannot consume later replies or regress.

Migration `2026_09_27_160000_add_platform_support.php` adds only these two nullable
columns and the three Platform permissions, granting Owner/Admin incrementally.
Existing roles' other grants, conversations, message history and money stay intact.
See `../deployment/PLATFORM_SUPPORT.md` and `../testing/PLATFORM_SUPPORT_20260927.md`.

## FAQ robot and human handoff (2026-10-06)

The user-approved local FAQ robot supersedes the earlier ban on automatic replies
for this workflow. No language model, external service, translation or generated
answer is used. The bot sends the saved Chinese answer verbatim as **客服助手**.

Platform **Customer support → Bot and FAQ** (`/platform/support/bot`) requires both
`support.read` and `support.bot.manage`. Owner/Admin receive the new permission
incrementally. Each company has an explicit switch, off by default. The public
library applies to enabled companies; company entries supplement it. An explicit
company override replaces one public answer or disables that entry for the company.
Archiving an override restores the public rule. Disabled/archived public entries
never match, even if an override is enabled. Company overrides inherit current public
question/variants/keywords. FAQ edits and settings enforce revision checks and audit
changes. FAQ pages are paginated; editors load only when opened. Match preview is a
read-only POST and neither creates messages nor enables the robot.

Matching strips punctuation and whitespace and lowercases Unicode text. A unique
exact canonical question or variant wins. Otherwise use the maximum Unicode bigram
Dice similarity or 0.85 for a complete keyword substring (minimum two characters).
Accept only a best score of at least 0.75 with at least a 0.10 lead over the runner-up.
Ties, ambiguous exact matches, missing matches and image-only input use a fixed
Chinese response asking the customer to rephrase or select human support. Images
are retained by the existing upload flow but never OCR'd or inspected by the robot.
No fallback automatically hands off or invents an answer.

Conversations have three modes:

- `BOT`: new customer conversations when the company bot is enabled; each new user
  message and its single robot answer persist atomically under Tenant → User →
  Conversation locks. A unique source-message association prevents duplicate answers.
- `WAITING`: an authenticated customer explicitly selects **转人工**. It can create
  an otherwise empty conversation, appends one acknowledgement and stops bot answers.
- `HUMAN`: a staff reply takes over. **结束接待** requires the current conversation
  revision and existing send permission, appends a closure notice and restores BOT
  only if the company switch is enabled. A new user message triggers the next answer.

Existing conversations start HUMAN, including when the bot is subsequently enabled.
Disabling the switch changes existing BOT conversations to HUMAN; WAITING remains
visible in the human queue. Re-enabling does not seize existing human conversations.
Legacy awaiting/replied filters remain; awaiting includes WAITING even when the last
message is its robot acknowledgement, plus HUMAN with a last user message. BOT traffic
is separately filterable and does not appear as unanswered human work.

Handoff and finish use owner-bound request UUIDs and immutable transition intents.
A retry reuses its original result even after later state changes. Ending service
with a stale revision fails with 409; the UI refreshes and asks staff to read new
messages. Send retries do not repeat a robot answer or takeover. Bot messages have
no user/admin sender ID and are explicitly `senderKind: BOT`, with `fromSupport: true`
for compatible clients. Answer text remains encrypted in immutable message snapshots;
FAQ ID/revision are internal provenance only. Consumers receive no staff identity
or FAQ management data. Bot replies and notices count toward support unread using
the existing visible-sequence POST acknowledgement; inbox remains separate.

New consumer endpoint: `POST /support/handoff`, also bridged through consumer-client,
H5 `/api/v1` and native `/api/mobile/v1`. Identity is authenticated and host-owned.
Platform/tenant finish endpoints reuse existing company authorization. No new safe
route for suspended users, provider call, financial operation, broadcast or external
notification is introduced. All GETs remain read-only.


## Service hours and dedicated workspace (2026-10-06, account model superseded below)

Platform controls company schedules through `support.read` + `support.hours.manage`.
The seven-day array begins Monday; each day has zero to six intervals. Starts are
inclusive and ends exclusive, `24:00` is supported, and an end earlier than its
start means overnight. Overlaps, including Sunday/Monday overlaps, are rejected.
Unset schedules are 24/7. Evaluate against the company's current IANA timezone,
including skipped/repeated civil times at DST changes. Agent presence does not
change availability. Settings saves lock the company, check revision and audit.

Support metadata includes `humanSupport.available`, timezone, weekly intervals and
nullable `nextOpenAt`. The UI disables offline handoff and explains the next opening.
The server also rechecks hours in the handoff transaction, returning 409
`SUPPORT_OFFLINE` before creating a conversation or message. A previously successful
request UUID is replayed before this check. Existing WAITING/HUMAN conversations
remain intact across closing time; customers may leave messages and staff may reply.
The offline FAQ fallback does not invite an unavailable transfer. GET stays read-only.

Platform `support.read` + `support.agents.manage` creates new dedicated email/password
identities, assigns one or multiple companies, resets passwords and enables/disables
accounts. Existing administrator identities cannot be converted implicitly. A new
TENANT `SUPPORT_AGENT` role grants only `support.manage`; the existing broader SUPPORT
role is not used. The independent `support_agent` guard serves `/support-agent/login`
and `/support-agent` on the Platform host. Dedicated accounts cannot log in through
the ordinary admin login. Each workspace request validates account status, session
version and active assignments; company resources recheck exact company membership.
Account/access changes increment revision and session version, invalidating existing
workspace sessions. Disabling remains possible when an assigned company is inactive.
No unrelated administrator pages or financial permissions are granted.

The workspace has company/status/search filters, independently scrolling conversation
history and inbox, image replies, revision-checked End service and the existing own
nickname form. Nicknames remain snapshots on future sent messages only. Nickname
changes increment the account editor revision without logging out the agent.

Platform `support.read` + `support.replies.manage` maintains company shared quick
replies; each dedicated agent maintains their own personal replies. Company admins
cannot manage schedules, dedicated accounts or shared templates. The picker shows
only the conversation company's shared templates plus the current agent's personal
templates, with title/body search and pagination. Each reply has title (100 chars),
body (2000 chars), revision and soft archive; scope cannot change on edit. Selecting
one inserts it at the composer selection/caret and preserves surrounding draft text.
It never sends automatically and rejects insertion beyond the message limit. Quick
replies are separate from robot FAQs and never change historical encrypted messages.


## Consumer user support agents (2026-10-06, current)

Support identity now belongs to an existing consumer user in its own company. The
Platform Users list offers Make support agent / Remove support access and an enabled
filter. The grant POST `/platform/tenants/{tenant}/users/{user}/support-agent` requires
users.read, support.read and support.agents.manage, accepts enabled/revision, locks
Tenant then User and audits the change. No administrator identity, password or role is
created. The additive `support_user_agents` table retains nickname and revision across
revocation; only ACTIVE users in ACTIVE companies with enabled authorization can act.
All consumer workspace endpoints revalidate this live state, independently of UI flags.
Revocation leaves the user's ordinary login and personal customer consultation intact.

`GET /account` adds `supportAgent`. App/H5 My account conditionally shows Support
workspace. The shared company inbox defaults to awaiting replies, supports mode,
name/account/email search and pagination, and excludes the agent's own consultation.
Each agent may read/reply to other agents' customer consultations. There is no assignment,
claim or presence-based availability. Existing company schedules and offline handoff
rules remain unchanged. Old clients continue to receive ordinary compatible chat DTOs;
updated native packages are needed to see the new workbench entry.

The authenticated operational consumer API exposes `/support-workspace` under both
`/api/v1` and `/api/mobile/v1`: GET list, GET conversations/{id}, GET images/{id}, POST
conversations/{id}/messages, POST conversations/{id}/finish, POST profile, and GET/POST
replies. Sender/company identity always comes from authentication and TenantContext.
Reads do not mark the customer's unread cursor. Images use existing verified direct
uploads bound to the agent, never the consulted customer; message retries preserve
UUID, content and image identity. The UI stops polling when hidden or access is revoked.

Messages add `sender_support_user_id` with a company-bound FK, an exclusive sender
constraint and an agent-specific request uniqueness index. `senderKind: SUPPORT_AGENT`
and `fromSupport: true` identify replies without exposing internal sender IDs. Consumer
unread counts and platform replied filters include this sender. Messages keep encrypted
immutable content and nickname snapshots. Bot takeover and finish retain company locks,
revision checks and retry semantics, recording USER audit actors and explicit transition
actor_kind for new events. No message or historical administrator identity is rewritten.

Personal templates live in `support_user_quick_replies`, company/user scoped, with
revision checks and soft archive. The picker unions current-company shared templates
with the agent's own personal templates, searches title/body, and inserts at the draft
cursor without sending. Platform maintains shared templates; each agent edits their
own nickname and personal templates in the mobile workbench.

Independent support-agent routes, guard, frontend and account creation are retired.
Legacy dedicated account markers continue to block ordinary administrator login and
support authorization. Their identity/message/private-template rows are retained;
there is no email-based mapping or implicit authorization of consumer users. Existing
Platform and tenant administrator support pages remain available under their original
permissions. Platform's staff nickname list links to Users for consumer-agent management.

## Consumer-agent presence, reading and message changes (2026-10-06)

Assigned consumer agents see customer online/offline state in their company inbox and
conversation. Visible App/H5 activity posts `/api/v1/presence` at most once per 20 seconds
(the app timer is 30 seconds). Online means an active user has a foreground heartbeat
within 75 seconds; backgrounding, disconnection or logout expires by this timeout. It is
an approximate presence signal, not a read receipt. Host/authentication owns identity;
GETs do not write presence or create conversations. No last-seen history is exposed.

Workspace messages expose `readByUser` from the customer's acknowledged visible sequence.
After an edit, an explicit receipt for that exact revision is required before showing
Read again. Receipt IDs/revisions must belong to the authenticated customer's own chat;
concurrent older receipts cannot reduce an acknowledged revision. Consumer DTOs do not
expose agent IDs, edit operators or staff-only permission/read flags.

Long-press (or desktop context menu/keyboard Enter) on the current consumer agent's own
message opens Edit/Delete. Edit replaces text or the image caption; image replacement is
not included. Delete asks for confirmation and shows a tombstone for both sides. Neither
a customer message, bot message, historical AdminUser reply nor another agent's message
can be changed through this operation. Original encrypted messages, image objects and
nickname snapshots remain immutable. Append-only encrypted revisions include the actor,
request UUID, revision and operation; audit metadata does not contain message text.
The current projection hides deleted text and images; authenticated image gateways reject
deleted messages. Previously downloaded or public storage copies cannot be recalled.

Mutations lock Tenant then conversation, revalidate the active assignment and ownership,
and require matching message revision and stable request UUID. Identical retries succeed;
changed-payload reuse/stale edits fail, and deletion cannot be undone or edited. Changes
never resend messages, alter conversation ordering/sequences, transition bot state or
create financial/notification entries. Multiple agents continue to share the queue.

## Shared company history reaffirmed (2026-10-06)

The consumer-agent inbox defaults to ALL, including replied, waiting and bot conversations.
Awaiting reply is an optional filter; it never owns or partitions the chat. Every active
assigned consumer agent reads the same company/customer transcript, including other
agents' and administrators' replies, original nickname snapshots, images and current
edit/delete markers. History uses the conversation-wide sequence cursor in batches of 50;
changing agents does not create a new conversation or hide earlier pages. Exclude only
the agent's own consumer consultation, as before. Company isolation and own-message-only
edit/delete permissions remain unchanged. Tests cover two agents reading the same 105
messages across three pages, images, default-list visibility after a reply and no GET writes.

The consumer-agent workspace displays all company conversations without status tabs,
with WAITING and HUMAN conversations last sent by the customer sorted first across
pagination, then newest activity. The header pending-message count counts individual
customer messages after the latest human staff reply in those conversations; it is
company-wide regardless of search, excludes the agent's own consultation, and clears
when staff replies. Opening the list does not mark messages handled or alter history.

The workspace loads further conversation pages on reaching the bottom instead of
manual pagination. Search resets the loaded range; periodic refresh replaces that
range atomically and deduplicates conversation IDs, preserving already loaded history.

Each consumer-agent inbox row now projects an agent-specific unread customer-message
count and the actual latest message's created_at, independent of conversation metadata
updates. A visible thread acknowledges its highest displayed sequence through a scoped
POST; GET remains read-only. Cursors are monotonic and separate per agent, and every
acknowledgement rechecks the current grant/company/own-consultation restrictions.
Apply 2026_10_06_235000_add_support_agent_read_cursors before deploying these reads.
The header still counts unhandled messages; reading alone does not count as replying.

Logged-in consumer agents receive a global foreground voice reminder for their own
unread customer messages. Fresh /unread counters revalidate active assignment; ordinary
users and revoked agents receive agentSupport=0. The bundled Chinese AAC prompt plays
at most once per minute, stops on logout/read/background, and resumes after fresh
foreground counters. H5 requires a user gesture to unlock audio; background/closed-tab
and device-muted playback are not guaranteed. No OS push permission or external TTS
service is used. Header pending counts remain separate from unread reminders.

Customer message initials open an agent-only customer profile page. The read endpoint
resolves the customer from an authorized same-company conversation, excludes self,
and revalidates active assignment on every request. It shows current effective agent
rank, enabled partner flag, registration time, available Ledger balances, direct inviter
identity and completed external top-up/withdrawal totals grouped by currency. Successful
order totals require posting references; pending/failed orders, transfers and manual
balance adjustments are excluded. No currency conversion, wallet provisioning, provider
request, financial write or administrator identity is exposed.

Customer remarks are company-owned shared aliases, editable from Platform Users
(users.read + support.read + support.send) and from an active consumer agent's scoped
customer profile. A maximum 60-character plain-text alias has a locked revision and
actor audit; concurrent stale saves fail with 409. Clearing restores the original name.
Workspace search/list, conversation heading and customer sender labels prefer the alias;
profile shows original customer name and editable remark separately. Consumer identity,
original display name and immutable message snapshots remain unchanged. Apply migration
2026_10_06_236000_add_customer_remark before deploying.

The consumer-agent inbox's second line shows a single-line latest-message preview
instead of account ID or reception mode. Batch-read only the authorized page's latest
messages and revisions; deleted text is never returned, edits use the current revision,
and image attachments use an image placeholder. Preview reads do not acknowledge messages.

The workspace header now uses the current agent's company-wide unreadMessageCount,
matching /unread.agentSupport and row read cursors. Reading messages reduces it and
zero hides the badge. This supersedes the earlier header unhandled-message count;
pendingMessageCount remains a separate backward-compatible API field only.
