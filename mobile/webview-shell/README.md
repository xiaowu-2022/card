# U卡 WebView 过渡工程

这是独立的 uni-app CLI 工程，只负责打开线上 H5，不导入原 App 的业务页面。

## 打包

1. 用 HBuilderX 导入本目录 `mobile/webview-shell`，不要导入 `src` 或旧 `mobile/uni-app`。
2. 使用 Node.js 22。首次在另一台电脑打开时执行 `npm ci`。
3. `npm run typecheck` 检查，`npm run build:app` 编译 App 资源。
4. HBuilderX 中选择「发行 → App 云打包」，使用原签名材料。Android 覆盖安装需要原签名一致。

当前版本 2.5.05 / 2505。DCloud AppID `__UNI__30F90A0`，Android 包名
`cc.specpay.cards`，iOS Bundle ID `com.tng.pingqiu`。保留原图标。
签名证书不包含在工程中。编译资源不等于已经生成 APK / IPA。

## 网站与线路

`src/config.json` 集中配置内置域名、公司 slug、H5 入口和缓存键。
内置域名仅为 `27m.my`、`113b.my`、`18k.my`；2026-10-08 按用户要求移除
`specpay.cc`、`specpay.top`、`specpay.vip`。此调整不禁止服务器目录或已验证缓存
再次提供这些域名；若要停止动态发现，需在 Platform 中停用或解绑相应域名。
默认入口为 `https://选定域名/#/pages/login/index`。
部署前确保所有分配给该公司的域名均提供同一份 H5 和同源 API。

每次启动并发检测内置域名和缓存域名，读取 `/api/mobile/v1/domains`，
检查 HTTPS、公司 slug、已缓存的公司 ID 及域名自身是否仍在服务器列表中。
新列表替换缓存，不累积已解绑域名；新发现的域名也要检测。
首次选择最快可用线路，后续优先保留仍可用的原线路，以便保留同源登录 Cookie。
每次回到前台，最多每分钟刷新一次列表缓存，不自动移动当前网页。
全部不可用时显示中文错误与重试，缓存不会绕过服务器验证。

启动选线及网页加载期间显示本地黑金品牌欢迎页：“Spec Pay 万事达U卡 / 全球支付，尽在掌握。”，
卡片示意图随包内置，不依赖网络。移除常驻返回、刷新、线路工具栏；加载成功后网页占满状态栏下方区域。
加载超时或连接失败时在同一品牌页提供重试；“重新连接”会确认后重新进入登录页，可能需要重新登录。
Android 返回键优先回退网页历史，无历史时提示退出。
WebView 不注入 5+ 权限；业务使用 H5 的浏览器接口、Cookie 和原有服务端权限。
壳不保存账号密码、令牌，不替网页重发交易，也不注入脚本提交表单。

## 配套 H5

H5 登录页首次进入默认英文，右上角可切换公司已启用的语言；注册页沿用所选语言。
需要把本次 `public/h5` 部署到服务器。只换壳 APK 而没有部署 H5，线上仍会显示旧网页。
后续网页修改部署 H5 即可；修改内置种子、壳行为或原生权限需要重新打包。

本地已验证目录选择和缓存测试、类型检查、App 资源编译。发布前仍需真机验证：
登录保持、注册、身份证照片选择/上传、客服图片、流水、返回键及断网恢复。
本工程不宣称已完成 APK 签名或真机验收。

## 2026-10-08 白屏入口修复

这些域名的站点根目录已经指向 H5 产物文件夹，访问入口必须是
`https://选定域名/#/pages/login/index`，不是 `/h5/#/pages/login/index`。
旧壳错误地加了 `/h5/`，服务器回退返回首页后，页面相对资源被解析为
`/h5/assets/...` 并返回 404，造成白屏。此前将其判断为服务器缺失资源不准确。
已验证 `https://27m.my/assets/index-Co5jBsxL.js` 返回 200 和 JavaScript
内容类型；无需为此修改服务器根目录或重新部署 H5 文件。

`src/config.json` 的 `entryPath` 已修正为根路径。`/api/mobile/v1/domains`
仍是公司线路接口，保留原有公司校验。`build-manifest.json` 仅是构建信息，
壳启动不请求它。不要把磁盘上的 `public/h5` 路径等同于公开 URL 路径。

壳代码同时增加单探测 4.5 秒、整轮 15 秒的独立超时，避免原生请求缺失
回调时无限等待。原生子窗口由父页面管理，不再对 append 后的窗口调用
独立 show/hide；通过内容区域尺寸控制网页展示，忽略空白文档的 loaded
事件，失败后保留重试界面。参考 [HTML5+ Webview append 约定](https://www.html5plus.org/specification/Webview.html)。

验证：`node --test tests/Frontend/webview-shell.mjs`（10 项离线测试，包含
实际入口配置的加载地址检查）、`npm --prefix mobile/webview-shell run typecheck`
和 `build:app`。这不代表签名 APK 或 OPPO 真机已通过。
入口修复需要从本工程重新云打包安装，旧 APK 内置路径不会随 Git 部署改变。
AppID 与版本保持当前 manifest 不变。
