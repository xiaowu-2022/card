# 一次部署：保留全部现有数据

以 2026-09-15 用户最新确认为准：**一个站点、一套 PostgreSQL 数据库、一份 `.env`；账号、卡片、余额、佣金、公司配置和历史记录全部保留在站点中。** SaaS、公司后台和客户端共用这套应用。

不再要求将当前数据另建为一套在线环境。`card_mock.dump` 是原备份文件名，不代表需要再部署一个网站。历史 `card_platform.dump` 不参与本次导入，不与当前数据拼表合并。

## 当前状态

服务器反馈：58 项迁移已完成，公司、用户、卡片、账本均为 0，当前是空表结构。

本次代码增加第 59 个迁移，只解除现有商户/持卡人绑定对数据库名字的限制，不改余额或历史。现有商户通过 `CARD_PROVIDER_DRIVER=directory` 按原绑定继续处理。具体批准范围见 [统一部署架构](../architecture/UNIFIED_SITE_DEPLOYMENT.md)。

## 1. 上传最新代码与备份

上传**包含本次修改的完整代码**到 `/www/wwwroot/card`，不要覆盖服务器现有 `.env`。

将下面四个文件上传到网站目录之外，例如 `/root/card-deploy/data`：

- `card_mock.dump`
- `card_mock.counts`
- `private-config-storage.tar.gz`
- `SHA256SUMS`

本次统一部署包在 `/Users/eaxyqminuo/www/card-deploy-20260915`，四个数据文件位于其中的 `data` 目录。私有压缩包包含配套密钥与上传文件，必须与数据库一起恢复。不要上传到 public 或公开网盘。

## 2. 检查服务器依赖

使用 PHP 8.4、Node 22、PostgreSQL 18 服务与客户端工具，以及维护中的 Composer 2 稳定版。CLI PHP 与网站 PHP-FPM 都需要对应扩展；重点检查 fileinfo 和 pdo_pgsql。

```bash
cd /www/wwwroot/card
php -v
php --ri fileinfo
php --ri pdo_pgsql
composer --version
node -v
pg_restore --version
pg_dump --version
composer check-platform-reqs --lock --no-dev
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
```

部署包已包含构建后的 `public/build`，使用包内原样代码时可跳过 `npm ci` 和 `npm run build`，服务器无需安装 Node；修改前端后才需要重新构建。

如果面板固定使用旧 Composer，更新面板实际调用的那个版本。不要使用 composer update、--ignore-platform-reqs 或重新生成密钥。Node/npm 缺失时先在服务器安装并设置 CLI 版本。

PostgreSQL 工具需在 PHP 命令可访问的 PATH 中。例如系统安装在 `/usr/lib/postgresql/18/bin`，先执行 `export PATH=/usr/lib/postgresql/18/bin:$PATH`。安装说明见 [PostgreSQL 官方文档](https://www.postgresql.org/download/linux/debian/)。

## 3. 一条命令恢复到现有配置的数据库

确认服务器 `.env` 已指向你要使用的 PostgreSQL 数据库，服务器上的队列/定时任务尚未启动。然后执行：

```bash
php scripts/deploy/restore-preserved-site.php /root/card-deploy/data
```

这个程序会：

1. 校验备份哈希、PostgreSQL 版本及目标是否为空表结构。目标已有业务记录时拒绝覆盖。
2. 在备份目录下保存目标库、现有 `.env` 和私有文件，并让网站进入维护状态。
3. 在同一个目标库完整恢复数据库及私有文件，保留原账号、卡片、余额与全部账本历史。
4. 恢复原持久加密密钥，保留服务器自己的数据库连接和域名配置，设置 `APP_ENV=production`、`APP_DEBUG=false`、`CARD_PROVIDER_DRIVER=directory`。
5. 执行唯一新增迁移，核对数据数量和账本。完成后保持维护状态，避免配置未完成时开始处理业务。

预期业务数量：2 家公司、502 个用户、11 张卡片、1286 条账本事件、2572 条分录。迁移数量恢复后为 59。

这一步不需要 `createdb`、Seeder、migrate:fresh 或另建归档库。恢复后的目标已有业务数据，不重复运行导入程序。报错时保留输出和 `target-before-restore-*` 备份，不清空数据库重试。

## 4. 完成同一站点的配置

服务器继续使用原部署目录和同一数据库：

- Web 根目录设置为 `/www/wwwroot/card/public`，配置真实域名与 HTTPS；不要在服务器继续用 artisan serve 作为 Web 服务。
- `.env` 的 APP_URL / PLATFORM_ADMIN_HOST 填实际客户端 / SaaS 域名；SESSION_DOMAIN 留空，SESSION_SECURE_COOKIE=true。
- 确认 PHP-FPM 运行用户能够读取 `.env`、私有文件，并写入 storage / bootstrap/cache。恢复程序按原 storage 目录属主保存文件；若面板运行用户不同，由面板统一调整目录权限。
- 使用恢复后的原 SaaS 管理员账号登录。公司域名从 SaaS 添加，按页面提示设置 `_vc-verification.<域名>` 的 TXT 记录，验证、激活后生效。不要 SQL 修改系统域名。
- Redis 服务须配置正确，使用 CACHE_STORE=redis、QUEUE_CONNECTION=redis；数据库和 Redis 不开放公网。通知、充值扫描和提现验证按真实配置启用；旧 mock 支付/链验证/OCR 配置在恢复时改为 unavailable，不伪造外部确认。
- 私有文件只供授权接口读取；不要公开映射 storage/app/private。
- 原商户连接、卡片标识及资金记录继续保留。真实 PhotonPay 连接不得使用模拟卡标识；未配置的连接保持不可用，不自动替换或伪造成功。
- PhotonPay 通知地址为 `https://实际回调域名/webhooks/card-provider`，配置正确通知验签公钥并联调。没有收到真实回调不能称为已接通。

```bash
php scripts/deploy/preflight.php --production
php artisan ledger:reconcile
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

预检查的 `passed` 仅代表列出的检查通过，`deployment_status=not_evaluated` 表示它不替代整体上线验收。原通用密码会保留并作为提醒列出，不会在迁移时被自动重置。

## 5. 开放同一站点

完成域名、数据、登录、权限及私有文件验收后：

```bash
php artisan up
```

初次数据导入不启动任务。核对已有 PENDING/UNKNOWN 订单和退款期限之后，再给同一应用配置队列与每分钟 `php artisan schedule:run`。TRON 扫描起点必须明确，不清除游标、不批量重试旧订单、不运行历史测试脚本。不得通过关闭账本保护或强制成功修复问题。

上线后继续保留原备份与导入前备份。代码回退不等于数据库回滚；已有新写入或外部结算时不得用旧快照覆盖新账本。

## 备份及迁移资料

- [迁移清单](MIGRATIONS.md)：原 58 项完整保留，新增 1 项部署兼容迁移。
- `migrations.sha256.json`：当前源码校验。
- [此前备份验证记录](PREPARATION_REPORT.md)：历史数量和恢复证据，以本手册的最新部署约定为准。
