# iOS IPA 分发

入口：`https://你的域名/ipa/`，或 `/ipa/index.php`。部署整个 `public/ipa` 文件夹，服务器需要 PHP 8.4、Phar、zlib、SimpleXML、iconv
（现有 PHP 容器具备这些能力，无需新增 Composer 包、ZipArchive 或数据库迁移）。

将已签名 IPA 上传到 `public/ipa`。文件名可包含中文和空格；建议先上传为 `.part`，
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
以及 Node 22 下 `node tests/Browser/ipa-distribution.mjs`（本地 PHP 服务 8000）。
已覆盖 XML/二进制 plist、真实主应用元数据、中文文件名/名称、XML 转义、损坏包、
符号链接/路径穿越拒绝、空目录、HTTP 安装阻止、Chromium/WebKit 手机布局和
系统安装链接；浏览器测试仅使用临时合成 IPA，结束自动清理，不触发真实安装。
另以本地现有已签名 IPA 验证主应用元数据读取。没有部署公网或验证 iPhone 实际安装。

后台 App 发布设置中的 iOS 分发地址固定显示“当前后台访问源 + /ipa”，只读。
消费者 bootstrap 与 app-release 接口分别使用该请求的公司访问源生成 `/ipa`，
不会把后台域名或其他公司的域名带给消费者。历史自定义地址保留在原发布元数据/
审计中，但不再作为实际 iOS 分发入口；读取不写入数据库，无需批量修改历史记录。
部署 PHP 与新后台资源即可生效，消费者无需重新打包 H5 或原生 App。
