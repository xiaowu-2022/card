# In-app messages

Approved 2026-09-26. In-app delivery only: no email, SMS, browser push or support-chat import. No historical order backfill.

## Data and transaction boundary

`inbox_events` is the durable outbox and immutable recipient snapshot. Every event includes tenant/user, a unique `(tenant_id, user_id, event_key)`, safe template parameters or a broadcast reference, and business occurrence time. `inbox_receipts` holds delivery and monotonically set `read_at`. Composite foreign keys and database guards enforce recipient scope and immutable content. `inbox_broadcasts` records actor, content, request identity and recipient count; recipient event rows are created atomically with the batch.

`InboxWriter::record` requires an active business transaction. `CaptureInboxEvent` observes newly created, allowlisted audit evidence and card-management terminal transitions synchronously; commissions and physical activation rejection have explicit transaction-local hooks. Rolls back with settlement. It does not scan audit history. Only confirmed credit/settlement and definitive failures are captured; QUOTED/PROCESSING/UNKNOWN do not imply success or failure. Amounts are native decimal strings and are never converted to JavaScript numbers.

After commit, the independent `InboxDelivery` processor attempts business-message delivery. Exceptions are contained and leave the event pending, so a delivery failure cannot be interpreted as a financial failure. Broadcast delivery is deferred to the bounded recovery worker. Receipt insertion and marking the event delivered share one transaction. Repeating delivery cannot create another receipt. No financial service, provider callback or external operation is called by delivery.

Capture sources: wallet and multi-asset deposit credit (including manual confirmation); both sides of wallet transfer; withdrawal completion/rejection with released hold; KYC approval/rejection; card issue, load, return, cancellation and physical activation; security-deposit funding/refund; wealth purchase/monthly interest/redemption/renewal; paid promotion purchase/upgrade/renewal, activation/annual commission shares, and automatic annual returns. Each installment/share uses its own identity. Generic safe failure copy excludes provider errors and private KYC/card data. Related links preserve existing business access policies.

The minute worker additionally records newly due manual-renewal wealth redemption windows, locking and rechecking the order. Only maturities at or after `inbox_installation.started_at` with an open redemption window qualify. It does not return or renew money. Legacy automatic-return contracts continue using their actual settlement notifications.

## Consumer API and UI

Authenticated, tenant-scoped routes:

- `GET /messages?filter=all|unread|business|platform`: newest first, 20 per page.
- `GET /messages/{uuid}`: pure read, never marks a message read.
- `GET /messages/unread-count`: private/no-store JSON.
- `POST /messages/{uuid}/read`: idempotent, owned receipt only.
- `POST /messages/read-all`: receipt-ID watermark captured before acquiring recipient lock; arrivals beyond it remain unread.

Delivery allocates receipt IDs under the same tenant/user advisory lock as read operations. This avoids timestamp rounding and sequence-allocation races. Suspended but still authenticated users retain inbox access; message links retain their original restrictions.

Me has a Messages tile next to support; authenticated pages have a bell. A shared provider drives both badges, hiding zero and capping display at `99+`. Server page props provide the initial count. Visible pages refresh every 30 seconds, immediately on focus/visibility restoration and after read actions. Hidden pages do not poll; failures retain the last confirmed count. Details mark read via POST, with retry handling.

Business templates render in current zh-CN/en/ms/es, with company-timezone dates. Platform title/body are escaped React text, including in previews, without HTML interpretation or translation. No private document data, PAN, CVV or raw upstream failure is persisted in message parameters.

## Platform permissions and send workflow

`/platform/notifications` requires `notifications.read`; candidate search, preview and send require `notifications.send`, active Platform membership and existing CSRF controls. The additive migration grants both to PLATFORM_OWNER and PLATFORM_ADMIN; company administrators have no entry or send capability. No second password challenge.

Choose a company, then selected recipients (account-ID/email search, up to 500 selections) or all existing users of that company. Title is at most 100 characters and body 5,000. Preview binds actor, immutable content intent and a digest of the recipient set in an encrypted 10-minute token. Confirmation rechecks scope, permission and the audience; changed audiences require a fresh preview. One send transaction freezes the actual recipient IDs, inserts the batch and events in chunks, and audits counts/audience. Later registrations are not included.

The request UUID is idempotent. Reuse with changed actor/company/content/selected users fails; identical retries return the original batch even after its preview expires. Admin reporting shows actor/time, target/delivered/read counts and pending/completed status. No resend, recall, attachments or scheduling UI.

## Deployment and recovery

1. Apply migrations, including `2026_09_26_120000_create_inbox.php`, before enabling the new application code. Build frontend assets. The migration adds inbox tables/permissions only; no messages are fabricated from existing orders and no Ledger rows change.
2. Keep the existing Laravel scheduler running every minute. `messages:recover` uses `withoutOverlapping(5)` and `onOneServer`; shared scheduler cache is required for multiple hosts.
3. Recovery processes up to 500 pending events per invocation. Re-run `php artisan messages:recover --tenant=<uuid>` for scoped backlog recovery. Repeated invocations are safe; this command must never be substituted with money/provider recovery commands.
4. Monitor oldest undelivered events and `Inbox delivery pending` warnings (IDs/error class only, no body). If delivery fails, repair infrastructure and let the same events retry. Do not edit/delete immutable events or financial records.
5. No real broadcast, live deposit/withdrawal/card operation or historical financial rewrite is part of installation/testing.

## Validation

`tests/Feature/InboxTest.php` covers transaction rollback, template/transaction guards, duplicate delivery, read-only GET, scoped ownership, suspended access, read watermark concurrency, partial delivery failure/recovery, immutable audience and idempotent send, changed preview and denied permissions. Existing financial suites exercise the new transaction-local adapters with offline providers; card tests include no notification for UNKNOWN. Browser fixtures in `tests/Browser/inbox.mjs` make no live writes and cover four locales, 375/768/1440px, shared `99+` badges, reads, decimal precision, literal HTML, long text, empty/error/retry states and admin layout.

Local Compose also provides `inbox`, a dedicated 60-second message-only recovery loop
(`docker compose up -d --build inbox`). This avoids enabling unrelated financial
schedules just to deliver local inbox notifications. Production continues using the
existing minute scheduler. Verification results and unrelated existing test failures
are recorded in [the verification report](../testing/INBOX_MESSAGES_20260926.md).

The 2026-09-26 support extension adds `supportCount` to the unread endpoint and
`unreadSupport` to page props. Individual entry badges retain their own counts; the
bottom Me badge sums inbox and support. Support read acknowledgements trigger the
shared refresh. See [support unread behavior](SUPPORT_CHAT.md); chat content remains
separate from inbox messages.
