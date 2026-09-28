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
