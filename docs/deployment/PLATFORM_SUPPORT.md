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
