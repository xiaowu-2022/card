# uni-app / HBuilderX 打包

## 当前状态

2026-09-29 用户确认官网及消费者页面使用新版 uni-app H5，旧 React H5 不再维护。
SaaS 后台继续使用 React/Inertia；线上入口是否完成切换以部署验证为准。范围和验证边界见
[架构说明](../architecture/CONSUMER_UNI_APP.md)与[验收清单](../testing/uni-app/PARITY.md)。
App 资源可编译，APK/IPA 云打包和真机验证仍需公司的 DCloud AppID 与签名材料。

Android 的生成配置固定包含 `arm64-v8a`（64 位）和 `armeabi-v7a`（32 位），
在 `app-plus.distribute.android.abiFilters` 中声明。修改持久来源
`scripts/client/index.mjs`，不要只改会被 prepare/build 覆盖的生成 manifest。
重新生成后通过 HBuilderX 云打包获取新的 APK；仅构建前端资源不会改变已有 APK。
验收实际 APK 的 `lib/arm64-v8a/` 和 `lib/armeabi-v7a/`，并在目标设备验证安装。
系统报告架构不匹配时，应比对实际下载文件、设备 ABI 和完整安装错误，
不能仅凭提示认定所有安装失败都是缺少 64 位支持。

新版 H5 官网首屏、手机菜单和页脚提供安卓版下载，链接固定为
`http://zb33333.com/specpay.apk`（不随当前域名或 `/h5/` 等部署目录变化）。部署时需提供实际 APK，并确保该路径
不被 H5 的 index 回退规则捕获。原生 App 不显示此下载入口。

## 本地开发

使用 Node.js 22+。在仓库根目录执行：

```sh
npm ci --prefix mobile/uni-app
npm run client -- prepare --company local
npm run client -- dev --company local
```

打开 `http://127.0.0.1:5200`。开发代理将 `/api/v1` 转发至 local 配置里的
公司域名；H5 使用 Cookie/CSRF，App 使用独立 Bearer API。不要将开发服务器
公开到互联网。H5 部署必须与对应公司 API 同域；不要用跨域 Cookie 绕过。

H5 的 API、邀请链接及海报二维码使用当前访问域名，同一公司的多个域名可共用
一份 H5；每个域名都必须由后端绑定同一公司并路由 `/api/v1` 到 Laravel。
`tenantSlug` 仍与 bootstrap 的公司标识严格比对，不因切换域名放宽。
App 使用内置 `apiOrigins` 和上次缓存发现当前公司域名，并在每次启动／回到前台时同步测速选优。`specpay.json` 已按用户确认保留 `tenant-a`，仅用于
该公司；其中原生包名尚未完成 App 发布验证。

```sh
npm run client -- build --company specpay --mode release --platform h5
```

产物为 `dist/clients/specpay/release/h5`；无需 DCloud AppID。

### 项目内 `public/h5` 部署

按用户要求，部署产物存放在 `public/h5`，迁移项目时一并带上。此目录只是产物位置，
不限定浏览器访问路径。同一份默认相对路径构建可部署在域名根目录、`/h5/`、`/client/`
或其他目录，部署时指定静态站点 root/alias 即可，不需要重新编译。目录 URL 应带末尾
斜杠（如 `/client/`），也支持 `/client/index.html`。公司标识仍为 `tenant-a`。
`/api/v1` 必须继续交由同域 Laravel 处理，回调和后台保持原后端路由。本次只放置文件，
未修改服务器入口或切换原 H5。

```sh
npm run client -- build --company specpay --mode release --platform h5
```

构建后按 `OSS_IMAGES.md` 执行 OSS 发布并验证 `index.oss.html`。
将 `index.oss.html` 部署为 `public/h5/index.html`；不要用未注入清单的原始
`index.html` 覆盖。保留前一版入口和哈希资源直到旧会话过期。默认 `--base ./`，资源和
邀请链接自动跟随当前部署目录，邀请链接使用目录下的 hash 注册路由，无需单独配置
注册页伪静态。API 始终使用根路径 `/api/v1/`。只有明确需要固定 URL 前缀时才传
`--base /指定目录/`。

```sh
npm run client:typecheck
npm run test:client
npm run client -- build --company local --platform h5
npm run client -- build --company local --platform app
```

输出为 `dist/clients/<company>/<debug|release>/<h5|app>`。App 输出是编译资源，
**不是 APK/IPA**。原网站仍使用根目录的 `npm run build`。

## 每家公司配置一次

复制 `mobile/companies/local.json` 为该公司的配置文件。填写真实的名称、
`tenantSlug`、HTTPS `apiOrigin`、反向域名格式 `appId`（原生包名）、
`dcloudAppId`（DCloud 分配的 `__UNI__...`）、版本及构建序号。

这两个 AppID 不同：DCloud 标识用于 DCloud 项目，原生包名用于系统与商店。
debug 自动给原生包名追加 `.debug`；正式签名需对应 release 包名。
正式公司配置设置 `developmentOnly: false`。local 配置故意不能用于 release；
发布检查会拒绝测试域名、HTTP、错误包名或未知字段。仅 App release 要求 DCloud
AppID；纯 H5 使用 `--platform h5` 时可留空。`prepare` 默认目标为 App。
API 凭据、Apple 密码、签名私钥和证书密码不得写入 JSON/仓库。

## HBuilderX 云打包

1. 先运行 `npm run client -- prepare --company <公司配置名> --mode debug`。
2. HBuilderX 导入 **mobile/uni-app 整个目录**，不要只导入 src，也不要导入整个
   Laravel 仓库。CLI 项目使用自己的锁定编译器；只导入 src 会换成 HBuilderX
   自带编译器，可能产生版本差异。
3. 登录自己的 DCloud 账号，为该公司申请 AppID，回填公司 JSON 后重新 prepare。
4. 检查生成的 `src/manifest.json` 中公司名称、包名、版本及平台配置，配置公司
   正式图标、启动图、隐私清单和平台签名；目前默认图标不能用于正式发布。
5. 通过“发行 → App 云打包”选择 Android/iOS 和相应证书，完成云打包后下载
   APK/IPA，真机验证后再提交商店。iOS 仍需要合法的苹果开发者账号和对应证书。

2026-09-29 已将六个 uni-app 编译依赖及锁文件从 5.24 统一升级到
`3.0.0-5020620260917001`（5.26 正式版），匹配当前 HBuilderX
`5.26.2026091802`。Vue/Vite 保持该编译器要求的 3.4.21/5.2.8。
后续更新须同时核对 IDE、项目 CLI 和云打包环境，不能仅更新 IDE 或伪造版本号。
本地验证不代表已生成新 APK；仍需重新云打包并完成真机测试。

生成文件位于 `src/generated` 和 `src/manifest.json`，不提交版本库。prepare
会重新生成，长期品牌配置应补充到公司配置生成流程，不要只手改生成文件。
一次仅准备／构建一家公司，避免修改同一工程的公司配置时另一个构建仍在运行。

## 上架前剩余工作

- 完成签名安装包的真机验收、设备权限、文件上传下载、后台敏感信息遮挡及运营方隐私审核。
- 当前原生登录不持久化，重启需要登录；如需持久登录，应配置并验证 Keychain/Keystore 存储。
- 配置真实 AppID、公司图标、证书和签名；确定发布地区及运营主体材料。
- 锁定的 DCloud 工具链存在 npm audit 报告，不能执行 `audit fix --force` 切回
  不兼容的 Vue 2 包来“消除”报告。发布前须完成受支持的工具链升级及依赖风险
  复核。本工程尚未通过正式发布依赖门禁。
- 验证真实 iOS/Android 安装包、后台遮挡、文件处理及公司链接，不以 H5 测试
  代替原生验收。

官方参考：[CLI/HBuilderX 工程区别](https://uniapp.dcloud.net.cn/quickstart-cli)、
[云打包](https://uniapp.dcloud.net.cn/dev/app/cloud-build.html)。

## 生成 H5 与对比预览

```sh
npm run build
npm run client -- build --company local --platform h5
node scripts/client/preview.mjs
```

生成版预览默认 `http://127.0.0.1:5202`。该工具只监听本机，将 API 请求代理到
prepare 后公司的 `apiOrigin`。端口占用时指定 `UNI_PREVIEW_PORT=5203`；不要停止
不属于此次预览的服务。其他公司输出通过 `UNI_PREVIEW_DIR` 指定。对照图库生成后
位于 `http://127.0.0.1:5202/__parity/index.html`。

本地 debug H5 重建保留该公司输出目录中的旧哈希资源，先复制新资源，再原子替换
`index.html`，避免已打开的页面切换到消息／客服时请求旧脚本出现加载超时。
刷新页面可进入最新版本。release 和 App 输出仍保持干净构建；正式静态发布也应
在旧会话有效期间保留其可访问的哈希资源，而不是只保留无法通过原 URL 访问的备份。

正式 H5 部署仍是拉取代码、安装锁定依赖、按公司 prepare/build，然后原子发布
`dist/clients/<company>/release/h5` 的静态产物。原根目录 `npm run build` 继续构建
SaaS/React。切换前需审核 Nginx：`/api/v1`、回调、PHP、私有图片和现有后台域名仍
交给 Laravel；仅消费者页面与其静态资源指向新 H5，旧路径访问由 uni-app 的入口
转换到对应页面。不得将 API/回调误返回 H5 index.html。保留旧静态版本便于前端
回退；前端回退不回滚财务数据或迁移。此次未修改生产 Nginx 或替换正式入口。

App manifest 已显式包含相机／相册 Camera 和系统分享 Share 模块，以及 iOS 拍照、
读相册和保存海报的用途说明。权限仅在用户选择上传／保存时触发；未加入录音、
定位、通讯录、第三方分享 SDK 或推送。参见 DCloud 官方
[功能模块](https://uniapp.dcloud.io/tutorial/app-modules.html)、
[系统分享](https://uniapp.dcloud.net.cn/share)、
[manifest 权限描述](https://uniapp.dcloud.net.cn/tutorial/app-manifest)。

## App 内置域名与自动选线

在公司的 JSON 配置中填写 `apiOrigins` 数组，例如：

```json
"apiOrigin": "https://primary.your-company.com",
"apiOrigins": [
  "https://primary.your-company.com",
  "https://backup.your-company.com"
]
```

以上仅为格式示例，必须替换为该公司的真实域名。`apiOrigin` 也会自动加入初始列表，
同时继续用于本地 H5 代理。specpay 配置按用户指定内置 `zb33333.com`、`specpay.cc`、
`specpay.top`、`specpay.vip`、`113b.my`，全部使用 HTTPS。release 对每一个域名检查 HTTPS
和非测试域名要求。修改后重新 prepare／打包。

日常新增域名在 SaaS「系统设置 → 域名」中启用并分配给该公司，无需重新打包。
App 从任意可用的已知入口获取该公司全部已启用域名，缓存完整列表，以最多六个
并发请求测速（单个超时四秒），选择本次成功响应最快的入口。被停用、解绑或归属
不符的域名不会入选。全不可用时显示现有网络错误，恢复后可重试。

先部署包含 `/api/mobile/v1/domains` 的后端，再发布新 App。所有域名必须具备正确
DNS／HTTPS 证书并指向同一套后端、数据库和会话缓存；网关应保留公司 Host，不得
把 API 重定向成 H5 页面。SaaS 显示启用不代表证书和网络已经就绪。至少保留一个
App 已知的内置或缓存入口可用；若全部失效，App 无法凭空获知新增入口，需要恢复
一个旧入口或更新安装包。H5 仍同域访问，不参与 App 选线。

## macOS HBuilderX Node 编译故障（2026-09-29）

HBuilderX 使用 Bash login shell 查找 CLI 项目的 Node；仅配置 `.zshrc`
可能提示找不到 Node。当前电脑的 `.bash_profile` 已配置 Node 路径。

在 Apple Silicon 上运行 Intel 版 HBuilderX 5.26 时，本机验证：arm64 Node
22.23.1 和 22.12.0 加载该 IDE 的 `uni_helpers` 后会 SIGSEGV；普通命令行
编译没有加载 IDE 插件，成功不能代替编辑器编译验证。当前本机使用
`~/.local/hbuilderx-node/bin/node`：检测到 `UNI_HBUILDERX_PLUGINS` 或
`HX_Version` 时运行 HBuilderX 配套的 x64 Node，其余情况使用原生 Node 22.23.1。
没有修改 HBuilderX 插件，也没有禁用插件校验。

本机 node_modules 已补充与锁定依赖版本一致的 `@rollup/rollup-darwin-x64`
4.63.5 和 `@esbuild/darwin-x64` 0.20.2，并保留 arm64 版本。
重装 node_modules 后需重新安装相应架构的可选依赖；版本以当前锁文件为准。
更换 HBuilderX 架构后应重新检查运行时架构，并更新或移除该本机适配配置。

AppID 以公司配置为持久来源；HBuilderX 重新获取 AppID 后须同步
`mobile/companies/specpay.json` 的 `dcloudAppId`，避免 prepare 覆盖。

已使用 HBuilderX 5.26 CLI 的 `publish app --type appResource` 验证，
2026-09-29 15:59 显示编译成功、导出成功；产物为
`mobile/uni-app/unpackage/resources`。该验证没有提交云打包或生成签名 APK。

## Mandatory Android updates (2026-09-30)

The App checks the installed native `appVersionCode` at startup, foreground and
before API work (successful results are shared for at most 60 seconds). A newer
published version blocks consumer operations and presents Download update. Network,
missing release and invalid metadata errors block operations with Retry. H5 is unchanged.
Requests already dispatched are not cancelled or replayed by version checking.
The download opens the system browser; the user completes Android installation.

First deploy the backend and publish the signed APK **before** distributing this
update-aware App. Earlier installed Apps without this code cannot gain detection
remotely and must be updated once manually. Native resources compiling successfully
is not a signed APK or device installation test.

Each company has its own release selected by the active Host. Publish using the
company slug and the actual signed APK metadata (DCloud appid, not Android package
name). Current Spec Pay source is 2.3.58 / 2358. On the server:

```sh
cd /www/wwwroot/card
/www/server/php/84/bin/php artisan app:publish-android tenant-a /path/to/signed-specpay.apk --code=2358 --release-version=2.3.58 --appid=__UNI__GBE57092
```

Replace `tenant-a` with the deployed company's actual slug if different. Keep the
same Android package name and signing certificate for upgrade installation, and
confirm APK metadata in HBuilderX before publishing; this command does not inspect
Android signing certificates or parse APK manifest metadata. Each distinct APK must
increase versionCode; update `mobile/companies/specpay.json` before prepare/packaging
and confirm the generated manifest matches. Do not advertise an unsigned/test APK.

The command checksum-verifies the copied APK at an immutable
`public/app-releases/<tenant UUID>/<sha256>.apk`, then publishes metadata through the
same company-locked database transaction and audit as the Platform form below.
Legacy `storage/app/app-releases/<tenant UUID>/android.json` pointers remain readable
until an explicit publication replaces them in the database. Old APKs remain.
Both release directories and the database are deployment data and must survive code uploads.
All active company domains must serve these same files, with HTTPS and APK downloads
allowed. The public GET `/api/mobile/v1/app-release` contains no secrets/session
creation; unavailable releases return 503. The existing H5 `/specpay.apk` download
is independent; replace that file with the same signed APK when releasing for H5.


### 2026-10-02 Static distribution host and API routing

`zb33333.com` hosts the static APK only. It is removed from Spec Pay API seeds and
ignored even if present in an old cached/server domain list. Startup, foreground
and explicit update retries refresh the full company directory, select a verified
API origin, then fetch `/api/mobile/v1/app-release` there. Downloads open the fixed
`http://zb33333.com/specpay.apk`; upload the same signed release to that static
host. The API still requires published company release metadata and its retained
release artifact (use `app:publish-android` on the API server). A static APK upload
alone does not publish metadata. Do not query the static host for app-release.
Recompile and cloud-package the native app; an H5 deployment cannot patch an
already installed APK. This supersedes the API-host download URL above.

### Online Android branding (2026-10-03)

`client prepare/build --platform app` now reads `/api/mobile/v1/bootstrap` from
configured company API seeds, verifies `tenant.slug` and a nonempty tenant ID,
then downloads the public configured `tenant.apkLogoUrl` without credentials. The
static APK host is never queried for configuration. Failed/unconfigured branding
stops native preparation before overwriting the manifest; stale local branding is
not a fallback. H5 builds do not fetch branding.

Upload the separate **APK Logo** in Platform company branding settings first.
It never falls back to the website/company Logo. The APK Logo is
contained without distortion on a white square for Android density icons, and
centered on white portrait splash images. Generated resources live under
`src/static/native-branding/`; the manifest receives `app-plus.distribute.icons`
and `app-plus.distribute.splashscreen` on every native preparation. A SHA-256
provenance file records the source API/company, never signed image URLs or secrets.
This uses DCloud's classic uni-app manifest schema:
https://uniapp.dcloud.net.cn/tutorial/app-manifest

Run `npm ci`, then `npm run client -- build --company specpay --mode release --platform app`.
Repackage with HBuilderX cloud packaging and install the resulting APK; existing
installed icons cannot be changed by editing server configuration. Android resource
compilation is not a signed APK or a device installation verification. iOS branding
is outside this Android change. Do not cloud-package stale output after a failed build.

### CLI 项目图标路径（2026-10-04 修复）

HBuilderX 云打包按 `mobile/uni-app` 项目根目录解析图标和启动图路径，
因此生成的 manifest 使用 `src/static/native-branding/...`，不能省略 `src/`。
`prepare` 和 `build --platform app` 均由 branding 脚本生成该路径。
导入完整 `mobile/uni-app` 项目；修改后关闭并重新打开打包窗口再提交。

### H5 Safari input and dialog compatibility (2026-10-04)

H5 shared form/login fields use real HTML inputs rather than uni-input's clipped
wrapper. Decimal amounts remain strings with `inputmode=decimal`; passwords use
native `type=password`. Native App builds retain the uni input control.
H5 dialogs teleport to body, lock background scrolling, and follow visualViewport
resize/scroll so the software keyboard and Safari toolbar do not hide the dialog.
Long content scrolls inside the dialog; closing restores the previous page position.
`tests/Browser/consumer-input-modal.mjs` checks Chromium and WebKit with offline
fixtures, including a tall transformed ancestor and simulated keyboard viewport.
This does not replace acceptance on the affected physical iPhone.
Publish rebuilt H5 JS/CSS and its matching entry to apply the browser fix; clear CDN
entry caches as appropriate. Updating only PHP or an APK does not update H5 browsers.

H5 的 uni-app 选择器整体使用层级 1100，高于筛选弹窗的 1000，避免滚轮和
确认按钮被弹窗遮罩挡住。`tests/Browser/consumer-picker-layer.mjs` 使用离线
每日数据验证 Chromium/WebKit 的触摸滑动、确认、取消及页面滚动锁恢复。
此修复仅需发布 H5 入口及配套资源，无需重新打包 APK。

### 身份认证图片缩略图与刷新（2026-10-04）

认证结果页使用 OSS `thumbnail` 展示策略（最长边 480px、JPEG、质量 75），固定小图框；点击才加载原图弹窗。缩略图失败依次尝试原图和服务器副本，每个地址一次，单次加载超过 15 秒也进入回退；全部失败显示图片不可用及刷新按钮。刷新重新尝试并通过只读认证查询更新签名地址。原图、认证记录、OCR 和补传状态不变。

同时部署后端及 H5；原生 App 需要重新打包。无需数据库迁移。离线验证：`tests/Feature/KycVerifiedDetailsTest.php`、`tests/Browser/consumer-kyc-images.mjs`（预览服务 5217，API 和图片完全模拟，不访问真实证件）。

### 存量报表双版本（2026-10-04）

启用的合伙人账号自动展示团队下级外部总入金减总出金（提现申请总额），排除本人，按本次公开行情统一折算 USDT。非合伙人展示原存量公式。部署后端、后台及 H5 后生效；原生 App 需重新打包。无需迁移或历史资金修正。汇率不可用显示估值不完整，原币金额保留；仅 USDT 的报表不依赖行情。

### Backend APK name (2026-10-06)

Platform → Company configuration → Brand and support now includes **APK name**
beside APK Logo. Save up to 60 characters; markup and control characters are rejected.
The company-scoped branding save retains its existing permission and audit controls.
Deploy the `add_apk_name_to_tenant_branding` migration and rebuilt admin assets first,
then explicitly configure the name for each company. Existing rows start unconfigured.
Native prepare/build reads `tenant.apkName` together with `tenant.apkLogoUrl` from the
verified company bootstrap; missing/invalid names stop packaging. The downloaded name
is written to generated company configuration and manifest, with no local-name fallback.
The company JSON `name` continues to configure H5. Website branding, package identifiers
and distribution filenames are independent. Name changes require rebuilding and installing
the APK; they do not rename already installed applications remotely.


### Platform Android release publication (2026-10-06)

Platform → Company configuration → Branding now includes **Android release**.
The form shows the current version and whether its retained artifact is available.
Upload the actual signed `.apk` (maximum 250 MiB), enter its exact version name,
positive increasing version code and DCloud AppID (`__UNI__…`, not the Android
package name), confirm matching metadata/distribution, and click Publish Android
release. Existing AppIDs are filled automatically and cannot be changed. APK
archive signature/size and copied SHA-256 are checked; signing certificates and
embedded version metadata are not parsed or independently verified.

The fixed download URL remains `http://zb33333.com/specpay.apk`. Put the same APK
on that separate static host **before** publishing metadata; this form does not
upload there, fetch arbitrary URLs or change installed App names/icons.
Publishing a newer version activates the existing mandatory-update gate.
Existing compatible APKs do not need rebuilding to consume this server change.

Deployment:

1. Deploy PHP code and run `php artisan migrate --force` (new table
   `tenant_android_releases`); deploy matching rebuilt administration assets.
2. Allow the upload in the Platform web server and PHP: `upload_max_filesize=250M`,
   `post_max_size=260M`, and Nginx `client_max_body_size 260m`. Configure proxy/request
   timeouts for the expected upload speed, then reload the corresponding services.
   `docker/php/uploads.ini` carries the local PHP limits; image-specific validation
   limits are unchanged. Ensure temporary upload space and release storage are writable.
3. Preserve `public/app-releases`, legacy `storage/app/app-releases` and the database
   across deployment. All API nodes need the same database and release artifact storage.

Publication requires active Platform `tenant.manage` authority on the routed
company. The company row serializes web/CLI publication; revision checks reject
stale editors, and different APKs require strictly increasing version codes with
the original AppID. An identical retry is a no-op. Metadata and immutable
`ANDROID_RELEASE_PUBLISHED` audit commit together; failures preserve the old
published version. Content-addressed files are installed before the transaction;
failed publications may leave an unreferenced artifact, never an advertised missing
one. The legacy pointer is a read-only fallback only when no database publication
exists; missing current artifacts return 503, without silently downgrading.
Only the selected Branding editor reads the new Platform DTO. API GET remains
company Host-scoped, credential-free and read-only. The existing CLI command is
optional and shares these rules. Do not run an old CLI publisher after deployment.

Offline validation: `PlatformAndroidReleaseTest`, `AppReleaseTest`, `ApkBrandingTest`,
and `tests/Browser/platform-android-release.mjs` (Chromium/WebKit, synthetic uploads,
375/768/1440 px, no real publication or external traffic).
