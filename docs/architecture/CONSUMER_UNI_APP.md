## 2026-10-08 Native read recovery

Native GET requests retry at most once after company-directory rediscovery on
transport failures or HTTP 502/503/504. Keep tenant verification and fastest valid
origin selection; do not replay POSTs, uploads, authorization failures, or requests
whose session changed during recovery. H5 retains its same-origin behavior.
The shared screen catches child setup/render errors and displays a retry message;
this is a recovery boundary, not proof of the reported Android blank-page cause.

> 2026-10-04 update: Android now uses Keystore-encrypted persistent credentials
> and sliding 30-day expiry. This supersedes the memory-only/cold-start-login notes
> below for Android only. H5 has independent secure remembered-login cookies;
> iOS remains memory-only. See [USER_AUTH_RULES.md](USER_AUTH_RULES.md).

On 2026-09-30 the user requested preserving card-application progress across
backgrounding and screen lock. Card selection, application, holder contacts and
recipient drafts remain in component memory until the form is closed/unmounted;
no draft is written to persistent storage. Foreground onShow skips automatic card
page reload while an application/selection/verification dialog is open. Explicit
post-submission refresh still runs. Request UUIDs, uncertain submission state,
server validation and PIN clearing in other screens remain unchanged. OS process
termination and a full browser reload are not covered by in-memory retention.

# uni-app consumer migration

## Native launch entry — 2026-09-29

Native App packages launch directly into `pages/login/index`; the existing login
bootstrap sends authenticated users to the assets dashboard (or the restricted
account page when required). Native login has no back link to the public marketing
page. H5 retains `pages/screen/index` as its first page and its public landing route.
The page order uses APP-PLUS conditional compilation. This does not persist native
tokens: cold starts still require login under the existing in-memory session policy.

## Decision and current boundary — 2026-09-26

The user selected **uni-app / Vue 3 / TypeScript with HBuilderX cloud packaging**,
superseding the earlier React/Capacitor proposal. Apps are separately branded and
packaged per company from shared source. Laravel and the existing React/Inertia
administration remain. On 2026-09-29 the user selected the new uni-app H5 as the
maintained public/consumer frontend and retired old React consumer H5 maintenance.
This supersedes the former old-H5 baseline; deployment state must still be verified separately.

The public H5 landing page offers Android downloads in its hero, mobile menu and
footer using a native download anchor to `http://zb33333.com/specpay.apk` (fixed
by the user on 2026-10-02, superseding the current-origin URL).
The path is independent of the H5 deployment directory and API/native domain selection.
These links are H5-only; native Apps omit them. APK distribution requires an actual
package at that URL; adding the link does not build or sign a package.

`mobile/uni-app` is an independently locked CLI project, also importable in
HBuilderX. Root npm dependencies still belong to the existing React application.
No Capacitor dependencies/configuration remain. The prepare script copies only
pure translation/data catalogs from the current frontend; it never copies PHP,
`.env`, credentials, certificates or the repository into a cloud project.

### Consumer implementation and acceptance

The consumer routes are implemented in Vue/uni-app, including public landing,
login/register/email challenges/recovery, account/settings/security/KYC, assets,
wallets and all four-asset deposit/withdrawal/exchange/transfer views, security
deposits, wealth purchases/interest/maturity/renewal/redemption, card applications,
holder documents, shipping/physical activation, card management/reveal/reload/return,
transactions, promotion purchase/upgrade/renewal/reports/posters, academy/articles,
messages and support text/image uploads. Administration stays React/Inertia.

`pages/screen/index.vue` dispatches explicit consumer DTO components to Vue screens;
this is not a WebView/iframe of old pages. Direct home/message/support pages use the
same scoped API. Internal links stay in uni-app. Original approved images, four
translation catalogs, precision helpers and geography data are bundled. App geography
uses eager data imports because the App service bundle is an IIFE; H5 lazy-loads it.

The uni-app H5 is now the maintained baseline. Earlier acceptance used the **compiled** H5,
not the dev server: isolated Pest DTOs plus explicitly synthetic read-model states
are rendered by both React and uni-app, at 375/768/1440px in four languages. See
`docs/testing/uni-app/PARITY.md` for the matrix and generated comparison gallery.
Passing compilation and screenshot runtime checks alone does not certify every
financial path or exact pixel equality. Human visual acceptance and signed native
install/device acceptance remain separate.

Native bearer tokens remain in memory: restarting the App requires login. No token,
password, OTP, full PAN/CVV or PIN is persisted in uni storage. Adding persistent
login requires a verified Keychain/Keystore bridge. Transfer retry intent stores
only its scoped non-secret request ID, asset, amount and recipient; passwords and
confirmations clear on submission/leave. Card details expire after 30 seconds and
clear when leaving/hiding. UNKNOWN issuing/recipient/activation results are not
silently re-submitted. Card transaction reads use platform records only.

## API and identity

API families are explicitly routed in `routes/consumer-api.php`, with full consumer
screen/action adapters in `routes/consumer-client.php`:

| Prefix | Authentication |
| --- | --- |
| `/api/v1` | Existing scoped tenant-user session, web CSRF and same-origin cookies |
| `/api/mobile/v1` | Bearer token only; native requests never restore browser/admin cookies |

`GET bootstrap` returns allowlisted tenant/user data, locale/timezone, unread counts
and an H5 CSRF token (null on mobile). `POST login`, `POST logout`, `POST locale`,
`GET account`, `GET assets`, `GET cards`, `GET unread`, `GET messages`,
`GET messages/{uuid}`, `POST messages/{uuid}/read`, `POST messages/read-all`,
`GET support`, `POST support/messages`, `POST support/read`,
`GET support/images/{uuid}` are currently exposed.

`/client/<original-consumer-path>` reuses the existing controllers, FormRequests,
authorization, throttling and domain actions. `ConsumerPageResponse` emits allowlisted
page JSON, converts local redirects into client navigation, translates flashed field
errors to HTTP 422, strips client partial-Inertia headers and never returns admin
props. API responses are private/no-store. There is no wildcard route to arbitrary
controllers, mock payments or administration.

Native multi-step registration/recovery/contact proofs use `X-Consumer-Flow`, an
opaque random handle to an isolated server session. It is scoped to tenant, expires
in two hours and is never authentication. Bearer authentication is checked separately.
Flow requests are serialized, controller session rotation is retained, and changing
the authenticated user clears previous flow proofs. Native registration completion
issues its own scoped device token; H5 completion refreshes cookie-session bootstrap.

Redirect flash messages/errors use that same isolated flow session. The request-scoped
redirector is restored in finally, avoiding default/browser session leakage. Bootstrap,
login, locale and recovery API limits use separate operation prefixes so routine reads
cannot consume recovery attempts; the existing rates and tenant-host scope remain.
Auth acceptance evidence: `docs/testing/uni-app/ACCEPTANCE_AUTH_20260926.md`.

Transfers, asset order creation, exchange confirmation and asset withdrawal cancellation
also have independent API throttle prefixes. Unread polling must not consume these
financial-operation allowances. Asset acceptance evidence and remaining visual differences:
`docs/testing/uni-app/ACCEPTANCE_ASSETS_20260926.md`.

Card acceptance evidence, API adapter coverage and outstanding UI/device checks:
`docs/testing/uni-app/ACCEPTANCE_CARDS_20260926.md`.
H5 cardholder uploads decode the selected local blob and inspect PNG/JPEG signatures;
the H5 image-info API does not supply the native MIME-type field. Server validation
remains authoritative. App packaging/device testing is deferred at the user's request
while H5 parity acceptance continues.

Money remains decimal strings; no JavaScript-number financial calculations. Asset
and card reads call the existing scoped queries, not providers or Ledger mutations.
Message read-all reuses the existing receipt watermark and concurrency protection.
GET never marks read. Restricted users can access account/messages; support and
asset/card views retain operational-user requirements. Closed/draft companies and
disabled users fail closed. Normal exceptions use API JSON, not Inertia redirects.

Native tokens use the Sanctum token model in `consumer_device_tokens`, extended with
mandatory tenant scope and captured session_version. Credentials are random 64-byte
alphanumeric secrets (only SHA-256 hashes stored), expire after 30 days and authorize
only the consumer API. Lookups check tenant before user resolution; malformed IDs,
wrong hash/type, expiry, session-version changes and disabled identities are rejected.
The issuance action rechecks password, user and company under Tenant -> User locks.
Sign-out deletes the current device token only. No provider secrets, password or
token is written to audit/log output. Existing authentication audit is reused.

Browser authentication remains independent. Root web/admin routes are unmodified;
there is no broad CSRF exclusion or permissive cross-origin credential policy.
The native client discovers company origins from packaged seeds and the cached public
directory; bootstrap confirms the expected tenant slug, while server authorization
relies on Host and stored IDs. See the domain routing contract below.

## Deployment and recovery

Apply the additive `2026_09_26_230000_create_consumer_device_tokens` migration through
the normal deployment process before enabling native sign-in. It creates only a
token table; no historical rows, funds or Ledger are changed. It has been exercised
in isolated `card_ui_test`, not applied to production as part of this work.

A client rollback must not roll back finance or token migrations. Keep old supported
API contracts when shipping later App versions. To stop serving this development
client, remove its separate H5 deployment; existing H5/admin remain available.
Expired token cleanup may delete expired authentication rows only, never financial
or identity records. Add a scoped maintenance command when native deployment begins.

Build and cloud packaging instructions: [UNI_APP_PACKAGING.md](../deployment/UNI_APP_PACKAGING.md).

Latest bounded PhotonPay sandbox lifecycle evidence: `docs/testing/uni-app/ACCEPTANCE_H5_20260927_R3.md`; broad H5 acceptance remains recorded in R2. Public callback delivery is not yet confirmed. This does not replace production deployment, device, or signed App release checks.

## Native company domain selection — 2026-09-29

Each package includes `apiOrigins` seeds (plus the existing `apiOrigin` used for local
H5 proxy configuration). On every App foreground event, including cold launch, the
client calls credential-free `GET /api/mobile/v1/domains` against seeds/cached origins.
The endpoint resolves the company from the active Host and returns only its ID, slug
and complete set of ACTIVE assigned domain HTTPS origins. No caller tenant selector,
unassigned/disabled domains, other-company domains, credentials or business data are
returned. GET does not create a flow session, token or financial/audit record. The
normal company availability gates and private/no-store response policy apply.
Local/testing environments additionally advertise the current origin for local ports.
Discovery has a separate 30/minute Host/IP throttle, so probing a large company domain
list does not consume a shared alias bucket or login/business request allowances.

Discovery uses at most six concurrent requests, four seconds per candidate. Candidates
must return a valid directory containing themselves and the packaged company slug.
The tenant ID is pinned for the running App session. The fastest valid initial response
supplies the complete current directory; newly discovered origins are also measured.
The selected origin has the lowest observed successful response time among the current
directory, excluding invalid/wrong-company/unreachable responses. Timing is one HTTP
sample per candidate per cycle, not a guarantee of future throughput. Large lists are
processed in batches without truncation; many unreachable hosts can delay startup.

The public directory replaces the app/company-scoped local cache; secrets are never
stored there. Cached and packaged origins remain recovery entry points, but must pass
fresh discovery before receiving business requests. All-offline discovery fails into
the existing network error UI; a later request/foreground can retry. An already running
selection is shared by concurrent callers. Requests, uploads and private downloads wait
for selection and capture the selected origin when dispatched. In-flight operations
continue on their original origin; selection never retries a business mutation. Bearer
and X-Consumer-Flow remain in memory, tenant-scoped and shared across company aliases.
H5 continues using its current origin with host-only cookie/CSRF rules.

All aliases must serve the same backend/database, authentication/flow cache and correct
TLS certificates. Domain ACTIVE is catalog membership, not proof of DNS/TLS readiness;
client probing checks actual reachability. At least one packaged/cached domain must
remain reachable to discover new domains. Losing every known entry point requires an
App update or restoring one known domain. There is no unrelated global discovery host.

Offline validation: `tests/Frontend/consumer-domain-routing.mjs`, `consumer-origin.mjs`,
`consumer-config.mjs`, `tests/Feature/ConsumerDomainsTest.php` and `ConsumerApiTest.php`.
Native resource compilation does not replace signed Android/iOS network acceptance.

### 2026-09-29 H5 KYC photo-picker lifecycle

H5 photo/camera selection can emit document visibilitychange and uni-app page
onHide/onShow. The mounted KYC form retains its number and photo references in
memory during background/foreground transitions; it does not refetch its page DTO
on that return or invalidate an in-flight load. Actual visible-page navigation,
unmount and successful submission still clear the form. No identity data or photo
bytes are added to persistent browser storage. Other sensitive screens retain
background clearing. Regression: tests/Frontend/consumer-kyc-lifecycle.mjs.

### Mandatory App version checks — 2026-09-30

Native startup/foreground and API entry points enforce the latest company-published
Android versionCode, using credential-free release reads only after company origin
validation. A blocking PageShell gate offers a same-origin immutable APK download
or retry. Missing/invalid/unreachable release data fails closed; newer installed
builds are never downgraded. A concurrent check is shared, successful checks expire
in 60 seconds, and H5 is unaffected. No business mutation is replayed. Release
publication and first-install requirements are in UNI_APP_PACKAGING.md.


## 2026-09-30 KYC and card processing feedback

KYC and card application mutations display a blocking ProcessingOverlay above modals,
with actual waiting seconds and a 15-second slow-processing hint. KYC reports completed
uploads then recognition/submission; cardholder, recipient and issuing steps use distinct
messages. Only KYC `kyc_url` uploads run concurrently, at most two; all started uploads
settle before failures propagate and no business request runs after a failed upload.
Local/dual-copy uploads stay sequential. useAction preserves JSON scalar types and
ignores progress/completion after scope disposal. No automatic mutation retry, timeout
change, OCR/provider bypass or financial change is introduced. Native packaging still
requires HBuilderX; build:app only compiles resources.


### 2026-10-03 fixed consumer navigation
PageShell title/brand headers stay fixed at the viewport top, with reserved content space and top safe-area padding. Existing bottom tabs remain fixed with bottom safe-area and content clearance. Avoid containment or transforms on navigation ancestors that would make fixed elements scroll with content. Authentication topbars and the public landing header follow the same fixed behavior; guest/article pages retain their existing tab visibility. H5 needs rebuilt assets; native clients require repackaging. This changes consumer navigation only, not desktop administration.

### 2026-10-05 H5 long-page navigation correction

CSS `position: fixed` inside the page was insufficient when a page ancestor established
a containing block through transform/contain/will-change. An offline regression reproduced
the bottom bar at the end of a 4000px content extension. PageShell now uses `ViewportLayer`
to teleport its header and bottom tabs to `body` on H5. Native compilation keeps the views
inside the page. Page visibility hooks remove portals for cached hidden pages and restore
them on return; unmount removes them automatically. Theme and typography are carried to the
portal explicitly; safe-area padding, content clearance, guest/article visibility and modal
z-order are preserved. Bottom-bar dimensions use viewport units rather than container units,
with basic pixel fallbacks for navigation and content padding.

`tests/Browser/consumer-fixed-navigation.mjs` checks scrolling long pages under transformed,
layout-contained and will-change ancestors, multiple portrait/landscape sizes, clickable
bars, route replacement, cached-page navigation/back and modal masking in Chrome/WebKit.
All API responses are synthetic. Deploy the rebuilt H5 bundle; no PHP or migration required.
Native resource compilation is a compatibility check, not a signed APK release.

### 2026-10-09 mobile content clearance

PageShell uses viewport units for its shared layout, avoiding container-query units in
critical padding on older Android WebViews. Content has at least 20px horizontal and
16px top padding; its scrollable bottom reserve is the tab height plus the safe-area
inset and 48px of breathing room. Native top clearance uses the greater of uni-app's
status-bar height and the CSS top safe area, with the same value on the fixed header
and its content spacer. Bottom tabs have an 80px minimum height and separated icon
highlight/label spacing. Promotion's fixed share dock follows the shared tab height;
its existing additional content reserve remains in place so the invitation code can
scroll above both controls. Chat retains its bounded composer layout.

The offline Chrome/WebKit navigation regression also checks 320/390/750px content
insets and maximum-scroll clearance on assets, account, KYC and promotion fixtures.
H5 assets must be rebuilt/deployed; native resources require repackaging/installing.
No API, accounting, permissions or database changes are involved.
# 2026-10-09 H5 底部导航排查

用户反馈同一手机浏览器正常，旧 APK 在更新网页后出现底部黑边及菜单文字裁切。
可视视口补偿和全白背景尝试未解决真机问题。按用户确认，撤销 VisualViewport
高度差补偿及监听，底部导航恢复固定 bottom: 0；内容底部留白和推广页悬浮操作
同时取消差值补偿。保留原有导航栏高度、CSS 安全区、配色及首页渐变。
“我的”首页继续移除 Logo 栏及栏高，保留顶部安全间距。

浏览器回归检查可视视口变化不会额外抬高导航，并保留长页、缩放窗口、页面
切换和弹窗检查。此次为单变量排查，尚不能确认真机黑边根因；部署 H5 后使用
原 APK 对比，不需要为这次网页修改重新打包。

## 登录页黑边诊断（2026-10-09）

用户反馈更新 H5 后出现黑边，重新打包壳仍无效；登录页初次打开即出现，
键盘开合或登录切页后可能消失。根因尚未确认，不再将壳改动视为已验证修复。
H5 的认证页品牌图片区连续点击五次可手动打开 `login-layout-1` 面板。
仅显示屏幕、布局/可视视口、100vh、安全区和登录容器矩形及背景色；不读取
输入值、账号、会话、Cookie 或 URL，不发送诊断请求、不持久化。复制按钮
只在用户主动点击时复制当前尺寸快照。面板默认关闭，刷新也是手动操作，
离开页面即关闭。应采集首次打开黑边时和键盘收起后的两份快照，保留同一 APK
与同一 H5 构建作对比。此面板用于取证，不代表黑边已修复。

2026-10-09 真机诊断照片：有黑边时 window/document 高 775，visual/vh/login 高
775.27；无黑边时分别为 804/804，safeBottom 两次均 0px，scrollY 均 0，缩放均 1。
页面已填满其收到的视口，不能再把差额当作页面底部 padding。无黑边照片顶部
内容同时延伸至状态栏/刘海区域；尚未确认引发窗口模式变化的具体机制。
面板新增手动 `viewport-fit=auto` 对比，保留其他 viewport 参数，点击恢复、
关闭或离开页面时还原原设置；不改变默认入口。点击测试后等待画面稳定再刷新
尺寸。此对比用于检验当前 `viewport-fit=cover` 与系统区域布局的交互，不保证
动态修改可复现冷启动行为。参考 Chromium 官方 display cutout 说明：
https://chromium.googlesource.com/chromium/src/+/HEAD/docs/ui/android/display_cutout.md
