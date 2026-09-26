# Company support chat

User-requested 2026-09-11: the consumer header support icon opens authenticated
first-party text/image chat. Each company receives and replies in its own Admin inbox.
This is a standalone Support module; it never calls providers or changes money,
KYC, cards, commissions or user lifecycle state.

- Host-resolved company plus authenticated user identifies the one conversation.
  GET does not create conversations. First message creates one transactionally.
- Admin inbox, reads and replies require an ACTIVE AdminUser and exact ACTIVE
  company membership with `support.manage`. Grant this to TENANT_OWNER,
  TENANT_ADMIN and SUPPORT only. Platform membership is never sufficient.
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
- Explicit allowlisted responses only. User sees their messages and generic
  Customer support identity, never internal administrator identifiers/email.
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
