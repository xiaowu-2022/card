# uni-app / HBuilderX 打包

## 当前状态

用户端页面和操作已迁移到 uni-app，生成 H5 用于与原版对照验收；原 H5 和 SaaS
后台仍保持现有入口，没有自动切换。范围和验证边界见
[架构说明](../architecture/CONSUMER_UNI_APP.md)与[验收清单](../testing/uni-app/PARITY.md)。
App 资源可编译，APK/IPA 云打包和真机验证仍需公司的 DCloud AppID 与签名材料。

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

本次检测到 HBuilderX 5.15，锁定 CLI 编译器报告 5.24。App 本地资源编译已经
验证；真正云打包前须按 DCloud 支持范围统一 IDE/编译器/运行基座版本，并完成
真机测试。没有使用 DCloud 账号、上传项目、提交云打包、购买服务或发送真实通知。

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
