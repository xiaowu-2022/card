# OSS 图片部署、迁移与恢复

1. 部署代码，执行 `composer install --no-dev --optimize-autoloader`、现有前端构建及 `php artisan migrate --force`。迁移只新增存储配置、文件映射和权限，不搬文件，不修改业务或 Ledger。
2. 在阿里云创建 Bucket 和专用 RAM 用户，授予此 Bucket 的 PutObject/GetObject/DeleteObject 及写入 public-read 对象所需权限。所有图片按已批准方案公开可读；Bucket 的阻止公共访问设置不能阻止这些对象 ACL。无需开放匿名写入或匿名列举。
3. 在 SaaS「控制 → OSS 存储配置」填写 Region、Bucket、区域 HTTPS Endpoint、HTTPS 图片访问域名和 RAM 凭证。默认 OSS 域名能公开返回图片时可使用 `https://<bucket>.oss-<region>.aliyuncs.com`；需要 CNAME 的 Bucket 配置绑定图片域名和 HTTPS；若上传/下载 API 也要求 CNAME，将 Endpoint 填为同一个图片域名，SDK 自动按 CNAME 签名。图片域名必须原样返回文件，不加图片水印/压缩转换，不跳转、不要求 Cookie、Referer 或签名，以便 OCR 拉取。
4. 保存 → 测试连接 → 启用。连接测试使用随机合成 PNG，验证写、读、匿名域名读取与删除；不提交 OCR 或真实身份资料。Endpoint 支持同地域 `-internal` 后端访问，图片域名仍必须公网可读。
5. 保留原应用 APP_KEY、KYC/Card 加密密钥、私有/公共磁盘原目录，以及所有历史配置版本；这些是读取旧图片和解密迁移的必要条件。启用后新图存 OSS，历史图保持原读取位置直至迁移完成。

SDK 建连超时 10 秒，普通请求超时 30 秒；超过 1 MiB 的图片上传允许最多 180 秒。慢链路下较大图片可能超过原来的 30 秒，不能仅凭超时认定远端没有收到文件。迁移保留目标键与原本地文件，按下述失败重试命令恢复，下载校验通过后才切换映射。Web 上传还需检查 PHP、反向代理和负载均衡的请求时限；SDK 时限不会自动修改这些配置。

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
