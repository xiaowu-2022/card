## 2026-10-23 移除 IPA 直接下载入口

H5 苹果安装页及旧 `/ipa/` 分发页移除“下载 IPA 文件”链接，保留系统安装按钮、包信息与安装清单接口。重新编译 H5；原生壳无需重打包。

## 2026-10-22 苹果安装方式选择页

`pages/app-download/index` 改为首页品牌、金色卡片与深色背景的独立安装选择页，
不显示导航选项卡。底部三个入口分别为 IPA、WebClip、PWA：IPA 通过站内路由
进入 `pages/app-download/ipa`（保留原有包加载、超时、重试、安装流程）；另两个
入口导航到当前源 `/webclip/`、`/pwa/`。选择页本身不读取包列表。
新增文案同步 en/zh-CN/ms/es。H5 发布时保留这两个已有静态目录以及 IPA PHP
分发文件、元数据与旧哈希文件。没有修改签名或触发真实安装，原生壳无需重打包。

## 2026-10-20 用户指定 IPA 已替换 OSS（公网核验未完成）

按用户明确要求，将 `/Users/eaxyqminuo/8f5a6250b1b1e0622e5871b25508cca4.ipa`
原样替换 OSS 根对象 `U 卡.ipa`。Bundle com.tng.pingqiu，2.6.22 / 2622，
19663280 字节，SHA256 `c3e81bb00fbd54555eea4c36f2d70f5886fb52ea5141278d8476313bf48188bf`。
旧对象已保存于私有 release-backups 并完整核验；分片上传完成后 OSS 完整回读一致。

该指定文件本地 codesign --verify --deep --strict 报 arm64 invalid signature，
已在上传前明确告知用户；没有擅自重新签名或换成其他 IPA。ZIP 与版本信息可读，
不能据此声称安装可用。公开 HTTPS 下载曾读取约 9.6MB 后超时，后续连接重试亦超时；
public_verified=false，不声称公网完整校验通过。记录在
output/U-card-ipa-publication-20261020.json。

为遵守公网校验后才更新分发元数据的发布门槛，未覆盖 public/h5/ipa/oss-release.json
或声称部署网站。新元数据候选在 output/ipa-oss-release-2622-pending.json；
现有部署的分发清单仍须后续同步版本，不能将上传 OSS 等同于更新分发清单。

## 2026-10-20 分发界面编译进 uni-app（当前入口）

按用户要求，苹果分发界面已改为 uni-app 页面 `pages/app-download/index`，随 H5
编译。首页、账户和菜单复用的苹果下载动作直接站内 navigateTo，不再等待 bootstrap，
也不再导航到服务端 `/ipa` 或 `/ipa/`。桌面选择苹果下载走同一路由。
界面源码位于 `mobile/uni-app/src/pages/app-download/index.vue`，可直接编辑。

安装包信息独立请求当前源 `/ipa/index.php?format=json`，credentials omit、no-store，
8 秒超时，有加载、重试与空列表状态。页面显示不等待该请求。该明确文件地址不经过
Nginx 目录补斜杠重定向。JSON 和旧 HTML/manifest 复用同一现有包读取/OSS 配置，
不访问数据库、身份或供应商，不缓存凭证。不把版本、签名或安装完成状态编造为成功。
安装清单用当前 HTTPS origin + `/ipa/index.php?manifest=…`，下载仍使用包配置中的
HTTPS OSS/本地链接；没有把 IPA 二进制编译进 JS，也没有改动签名/设备授权。

部署整个 `public/h5` 的新增哈希资源和新入口，并一并更新 `public/h5/ipa/index.php`。
保留旧哈希、IPA、oss-release.json 以及现有 PHP-FPM 文件处理规则；本次不改服务器
代理配置。H5 界面已编译复制至 public/h5，原生壳无需重打包。
旧直接访问 `/ipa/` 的独立页面和安装清单接口仍保留兼容。

验证：苹果/桌面下载路由与 HTTPS 清单单测通过；Chromium/WebKit 手机布局实测
从首页点击可先显示分发页，再完成独立列表读取；503 后重试成功，未追加 bootstrap
或请求目录 URL；真实 PHP JSON 模式、已有离线 IPA/OSS/清单解析测试和类型检查通过。
截图：`output/uni-ios-distribution/`。线上尚未部署，未触发真实设备安装。

# iOS IPA 分发

入口：`https://你的域名/ipa/`，或 `/ipa/index.php`。部署整个 `public/h5/ipa` 文件夹，服务器需要 PHP 8.4、Phar、zlib、SimpleXML、iconv
（现有 PHP 容器具备这些能力，无需新增 Composer 包、ZipArchive 或数据库迁移）。

将已签名 IPA 上传到 `public/h5/ipa`。文件名可包含中文和空格；建议先上传为 `.part`，
上传完整后原子重命名成 `.ipa`，避免用户下载未完成的包。页面自动枚举同目录包，
按文件修改时间倒序显示；“最近上传”不代表最高版本。读取真实主应用 Info.plist
（支持二进制/XML），不使用文件名推测 Bundle ID、版本和名称；损坏/未完成包不提供
安装链接。服务器不会重新签名、修改、解压写入或自动删除包；目录不提供网页上传接口。
IPA 是公开下载文件，不得把证书、私钥、密码或其他敏感材料上传到此目录。

每个包使用 `/ipa/index.php?manifest=编码后的文件名` 动态生成 XML 安装清单。
清单及 IPA URL 固定使用当前请求 Host 的 HTTPS，不读取客户端转发 Host。
必须通过有效公开 HTTPS 域名访问；反向代理应保留原 Host。清单和入口不缓存，
安装包使用新文件名发布，避免 CDN/设备复用旧包。不建议反复覆盖相同文件名。
页面不会假报安装成功，Safari 调起系统后由用户在主屏幕确认进度。

Nginx 须让 `/ipa/` 命中 `index.php`，`.php` 沿用现有 PHP-FPM 配置；不要用
`^~ /ipa/` 静态块绕过 PHP 处理，否则可能下载 PHP 源码。参考
`snippets/ipa-distribution.nginx.conf`，合并时保留站点原安全响应头与其他路由。
若域名仅托管 H5 静态资源，须为该入口接入 PHP-FPM；纯静态服务器无法自动扫描 IPA。
无需修改现有 API、公司登录或 App 发布元数据。公司 iOS 分发页配置可填写上述入口。

Apple OTA 需要有效签名、受信任 HTTPS 证书，以及满足签名类型要求的设备。
Ad Hoc 仅允许描述文件登记设备，企业包受企业签名适用范围约束，App Store 包应走
App Store/TestFlight。网页分发不会绕过设备登记、签名失效或系统信任流程。
参考：[Apple 分发说明](https://support.apple.com/guide/deployment/depce7cefc4d/web)。

验证命令：`docker exec card-app-1 php /var/www/html/tests/Standalone/ipa-distribution.php`，
以及 Node 22 下 `node tests/Browser/ipa-distribution.mjs`（以 public/h5 为根目录的本地 PHP 服务 8123，
可用 IPA_TEST_ORIGIN 指定测试地址）。
已覆盖 XML/二进制 plist、真实主应用元数据、中文文件名/名称、XML 转义、损坏包、
符号链接/路径穿越拒绝、空目录、HTTP 安装阻止、Chromium/WebKit 手机布局和
系统安装链接；浏览器测试仅使用临时合成 IPA，结束自动清理，不触发真实安装。
另以本地现有已签名 IPA 验证主应用元数据读取。没有部署公网或验证 iPhone 实际安装。

后台 App 发布设置中的 iOS 分发地址固定显示“当前后台访问源 + /ipa”，只读。
消费者 bootstrap 与 app-release 接口分别使用该请求的公司访问源生成 `/ipa`，
不会把后台域名或其他公司的域名带给消费者。历史自定义地址保留在原发布元数据/
审计中，但不再作为实际 iOS 分发入口；读取不写入数据库，无需批量修改历史记录。
部署 PHP 与新后台资源即可生效，消费者无需重新打包 H5 或原生 App。

如果点击下载回到 H5 首页，先分别访问 `/ipa`、`/ipa/`、`/ipa/index.php`。
三个入口都应到达分发页，不能由 H5 fallback 返回首页。配置已包含 `/ipa` 到
`/ipa/` 的显式重定向。当前站点根目录为 `public/h5`，分发文件及 IPA 均放在
`public/h5/ipa`，无需跨根目录 alias。index.php 仍须交给 PHP 8.4 FPM；保留原有
H5 根目录。server 级无条件 rewrite 必须移到 location / 或排除 ipa。
仅配置静态 try_files 而未接 PHP-FPM，无法运行本分发页。

H5 更新时保留 `public/h5/ipa`，不要用删除整个 public/h5 的方式发布，或让
`rsync --delete` 删除 ipa。已有 client 构建输出在 mobile/uni-app/dist 和
dist/clients 下，不会删除 public/h5/ipa。已完成的 IPA 允许进入 Git，随代码部署；仅忽略 .part/.upload 未完成文件。

2026-10-20 线上只读核查：113d.my/ipa/ 与 /ipa/index.php 返回分发页的空目录状态；
/ipa/U-card-2.6.20.ipa 则返回 Content-Type: text/html、title Spec Pay 的 H5 首页。
该路径没有实际分发 IPA，不能把 HTTP 200 当成包已上传。确认文件在服务器的
当前 H5 document root 下 ipa 目录，已移除该目录 IPA 的 Git 忽略规则，发布时应一并提交和部署安装包。
空目录与读取失败现已分开显示，读取失败附带允许列表错误码，不输出原始异常。

## OSS 加速下载

分发页仍在当前域名 `/ipa`。`public/h5/ipa/oss-release.json` 保存已发布安装包的
名称、真实 Bundle ID/版本、大小、SHA256 和公开 HTTPS URL。页面和安装清单
只读取该元数据，不在每次请求时联网请求 OSS 或读取本地大包。对应文件即使没有
同步到网站服务器，仍可显示和安装；其他本地 IPA 继续使用原来的分发方式。

当前根对象名为 `U 卡.ipa`，公开地址为
`https://zb33333.com/U%20%E5%8D%A1.ipa`。新发布覆盖前保留旧对象备份，并完整
回读 OSS、校验公开 HTTPS 下载的 SHA256 后才更新 oss-release.json。元数据与
index.php/distribution.php 一同部署；只上传 OSS 不会修改已部署的网站代码。
更新远端包时须同步元数据，不能让旧版本清单指向内容不同的新包。配置 URL
携带版本哈希查询参数以避免复用旧 CDN 缓存，OSS 对象仍使用根目录固定名称。
