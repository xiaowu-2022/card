# OSS 图片部署、迁移与恢复

1. 部署代码，执行 `composer install --no-dev --optimize-autoloader`、现有前端构建及 `php artisan migrate --force`。迁移只新增存储配置、文件映射和权限，不搬文件，不修改业务或 Ledger。
2. 在阿里云创建 Bucket 和专用 RAM 用户，授予此 Bucket 的 PutObject/GetObject/DeleteObject 及写入 public-read 对象所需权限。所有图片按已批准方案公开可读；Bucket 的阻止公共访问设置不能阻止这些对象 ACL。无需开放匿名写入或匿名列举。
3. 在 SaaS「控制 → OSS 存储配置」填写 Region、Bucket、区域 HTTPS Endpoint、HTTPS 图片访问域名和 RAM 凭证。默认 OSS 域名能公开返回图片时可使用 `https://<bucket>.oss-<region>.aliyuncs.com`；需要 CNAME 的 Bucket 配置绑定图片域名和 HTTPS；若上传/下载 API 也要求 CNAME，将 Endpoint 填为同一个图片域名，SDK 自动按 CNAME 签名。不带处理参数的图片域名必须原样返回原文件，不跳转、不要求 Cookie、Referer 或签名，以便 OCR 拉取；展示请求的 `x-oss-process` 参数必须透传给 OSS。
4. 保存 → 测试连接 → 启用。连接测试使用随机合成 PNG，验证写、读、匿名域名读取与删除；不提交 OCR 或真实身份资料。Endpoint 支持同地域 `-internal` 后端访问，图片域名仍必须公网可读。
5. 保留原应用 APP_KEY、KYC/Card 加密密钥、私有/公共磁盘原目录，以及所有历史配置版本；这些是读取旧图片和解密迁移的必要条件。启用后新图存 OSS，历史图保持原读取位置直至迁移完成。

SDK 建连超时 10 秒，普通展示请求超时 30 秒；超过 1 MiB 的业务图片上传及原图读取允许最多 180 秒。公开静态资源的离线发布上传/下载校验允许最多 600 秒，使用六个独立工作进程，单文件相同内容最多重试三次。慢链路下较大图片可能超过原来的 30 秒，不能仅凭超时认定远端没有收到文件。迁移保留目标键与原本地文件，按下述失败重试命令恢复，下载校验通过后才切换映射。Web 上传还需检查 PHP、反向代理和负载均衡的请求时限；SDK 时限不会自动修改这些配置。

```sh
# 默认只预览，不发送文件
php artisan images:migrate-oss
# 每批上限 100；重复执行，直到 eligible 为 0
php artisan images:migrate-oss --execute --limit=100
# 也可限制公司
php artisan images:migrate-oss --execute --tenant=<company-uuid> --limit=100
# 修复缺文件、凭证或网络问题后重试失败项
php artisan images:migrate-oss --execute --retry-failed --limit=100
# 平时由每分钟 scheduler 执行，只恢复文件清理
php artisan images:recover
```

迁移输出 eligible、migrated、failed、previous_failures_skipped；错误只标记业务类型/引用，不打印证件内容或 OSS 地址。源文件缺失或解密失败必须恢复原备份/密钥后重试，不生成替代证件。校验失败不会切换读取；已完成项不重复上传。未完成的目标对象用于原地重试，不重复创建随机副本。

不要删除本地备份，不要回滚新表或移除旧配置。回滚客户端不影响文件映射；后端需保持可读取 OSS 映射的版本。停止使用某个失效配置时先修复其读取能力，再启用新配置供新增上传；旧记录不会偷偷改读新 Bucket。公开对象地址一旦已分发，不能靠应用登录控制其传播；备份和域名配置应纳入既有运维管理。

当前不自动迁移真实图片；需先完成配置连接测试，再显式执行迁移命令。上传失败、cleanup_pending 和 MIGRATION_FAILED 记录分别可在后台图片统计及 stored_images 中排查，禁止输出 credentials、原始上游异常或图片内容。


## 静态资源发布与图片展示（2026-09-29）

新上传必须有已验证启用的 OSS 配置。原始文件保留，展示端使用 OSS 缩图/WebP；
无需重传既有业务图，也不实际压缩存储原图。先应用
`2026_09_29_160000_add_public_assets_to_media_storage`，该迁移只增加 JSON 清单列。

```sh
npm run build
npm run client -- build --company specpay --platform h5 --mode release
# 预览，不上传
php artisan assets:publish-oss --web --h5=dist/clients/specpay/release/h5
# 上传白名单公开资源；全图校验后更新清单，失败按同一命令续跑
php artisan assets:publish-oss --execute --web --h5=dist/clients/specpay/release/h5
```

脚本输出 `Verified: <公开资源路径>`，不输出凭据。编译资源阶段失败不切换入口；
已验证对象在 staging 清单中留待续传。过程中不要重建这两个目录。
不加 `--web` 只发布原始图片、图标、地区数据；不会移动 HTML/API/PHP、日志或密钥。

检查 OSS 的公开域名允许匿名跨域 GET（JS 模块/字体/JSON 必须有正确 CORS），
JS 返回 application/javascript，CSS 返回 text/css，不下载成附件、不重定向。
构建产物中的相对 import 必须与上传目录对应。脚本使用内容哈希路径并长期缓存，
图片 IMG 可按实际网络表现调整服务端固定档位，原始图片无参数地址继续供 OCR 使用。

成功后生成 `dist/clients/specpay/release/h5/index.oss.html`；验证页面与动态导航后，
将它原子替换为部署目录的 `index.html`。保存原入口和原本地资源作回滚/启动资源，
不删除旧 OSS 对象。后端 Vite 自动使用已经完整发布的 OSS 编译清单；开发热更新
仍由本地开发服务器提供。签名原生包需重新构建/打包才能获得新的读取逻辑。

### 空 bootstrap 清单回归修复（2026-09-29）

`specpay.cc` 的 HTML 已包含 OSS 清单，但其 API 数据库的 `publicAssets` 为 `[]`。
同时旧 Vite 配置未定义 `import.meta.env.UNI_PLATFORM`，生产 H5 无法进入读取
HTML 清单的分支；现显式注入 uni CLI 的平台标识。旧客户端还会用空清单覆盖
HTML 清单并回退本地静态图。客户端现保留入口内有效映射，
只合并 API 返回的有效 HTTPS 地址；H5 缺失映射不再请求本地 static 目录。原生离线
启动资源仍可使用包内文件。用 `OSS_EMPTY_BOOTSTRAP=1 node tests/Browser/oss-assets.mjs`
复验 API 空清单的完整页面加载。新入口需部署到线上才能替换旧 JS 哈希。

本次验收：7 项 origin 测试及 uni-app 类型检查通过。生产编译入口不再含未解析
UNI_PLATFORM；空 bootstrap 浏览器测试通过，金色卡片背景样式为 HTTPS OSS 地址，
65 个远程响应、0 个本地静态请求、0 个页面错误。更新了当前工作区
`public/h5/index.html`；线上需部署该入口。旧内容哈希对象未覆盖。
