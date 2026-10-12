# 客服 WebSocket 部署（2026-10-12）

客服消息、会话状态、消息更正和各身份已读状态使用自托管 Laravel Reverb 推送刷新通知。
数据库保存与原有发送/已读接口保持一致，无数据库迁移。服务中断时自动回到原有轮询。
不执行真实客户试发、财务测试、provider 调用或历史重放。

## 同步上线

1. 上传匹配的 PHP、composer.json/composer.lock、shared 源码及构建好的
   public/build、H5 资源。使用服务器的 PHP 8.4 运行
   `composer install --no-dev --prefer-dist --optimize-autoloader`；不要 composer update。
2. 在现有私有 `.env` 中设置 `SUPPORT_REALTIME_ENABLED=true`；
   `REVERB_APP_ID` 使用此站点的独立 ID，`REVERB_APP_KEY` 是独立公开应用标识，
   `REVERB_APP_SECRET` 是单独生成的至少 32 字节随机秘密。
   例如可分别使用 `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` 生成，
   不复制示例开发值到生产，不替换 APP_KEY 或任何已有数据加密密钥。
3. `REVERB_HOST=127.0.0.1`、`REVERB_PORT=8080`、`REVERB_SCHEME=http` 指定 PHP
   内部发布目标。`REVERB_SERVER_HOST=127.0.0.1`、`REVERB_SERVER_PORT=8080` 保持
   服务只监听回环；生产 `SUPPORT_SOCKET_PORT` 留空，浏览器/App 使用当前公司的
   HTTPS 域名对应 WSS。无需配置 `REVERB_ALLOWED_ORIGINS`：服务每次握手自动校验
   `PLATFORM_ADMIN_HOST` 和数据库中当前已分配、ACTIVE 且所属公司 ACTIVE 的域名。
   新增/停用/解除分配公司域名立即影响新连接，无需更新列表或重启 Reverb。
4. 按现有站点的 PHP 路径与运行用户安装
   [Supervisor 示例](snippets/support-reverb.supervisor.conf)，加载并启动
   `card-support-reverb`。不要加 `--debug`，不要暴露内部 `/apps` 发布接口。
5. 将 [Nginx 示例](snippets/support-reverb.nginx.conf) 放进每个公司及平台虚拟主机，
   在 PHP/H5 fallback 之前，使 `/app/` 支持 WebSocket Upgrade。保持已有 TLS，
   先执行 `nginx -t` 再 reload。8080 不对公网开放。若站点配置了 CSP，connect-src
   需允许本站 WSS。Supervisor 进程用户应与网站持有相同私有配置读取权限。
6. 刷新 Laravel 配置缓存并重启受管理的 Reverb 进程；长期运行进程不会自动读取新代码。
   确认现有队列/定时任务配置不变。此推送使用提交后的内部即时广播，不依赖新队列任务。

部署未完成时 `SUPPORT_REALTIME_ENABLED=false` 保持原有轮询，完成后再启用；断线时
客户端也会自动补拉。实际生产上线状态需要检查服务管理器和各域名代理，不以构建成功代替。

## 本地与客户端

compose.yaml 已包含本地 support-realtime 服务、仅开发用独立应用标识和 8080 端口。
新建本地环境可运行 `docker compose up -d app support-realtime`；已有隔离环境应继续
使用其隔离网络/配置，不自动切换数据库或启动其他业务 worker。

后台使用 `npm run build`。H5 使用现有公司构建配置及 base 路径重新构建；原生 App
使用 `npm --prefix mobile/uni-app run build:app` 生成资源后按原有流程重新打包/安装。
已安装旧 App 会继续轮询，服务器升级不会自行替换安装包。原生 SocketTask 携带
当前已验证公司 Origin，设备 token 仅用于公司 HTTPS auth 接口，不进入 WS URL。

## 离线验证

在已有隔离 `card_ui_test` 环境运行 SupportRealtime/SupportChat/SupportUserAgents/
SupportAdminUnread/SupportBot/PlatformSupport/SupportWorkspace 测试；它们阻止真实 HTTP
请求并保留数据库隔离守卫。新增测试覆盖跨公司/跨身份订阅拒绝、撤权、停用、设备 token、
不写消息/财务证据的配置与签名、事务提交/回滚和广播故障下的消息幂等性。

`node --test tests/Frontend/support-realtime.mjs tests/Frontend/admin-support-reminder.mjs
 tests/Frontend/support-reminder.mjs` 覆盖订阅确认、重连补拉、心跳、过期回调、后台停止、
原生 URL 兼容和音频。`tests/Browser/support-reverb.mjs` 仅针对本地、合成 Reverb 服务
验证真实 WS 私有订阅、签名事件发布、频道隔离和禁用客户端事件，拒绝非回环目标。


## 后台跨页面与后台标签提醒（2026-10-12）

本次追加修改只需同步 PHP 对应版本的后台静态资源；不新增迁移或私有配置项。
提醒组件移到持久 Inertia 全局层，Platform/公司后台任意页面与隐藏标签都保留
WebSocket、未读计数补拉和提示音；首次交互启用同一个播放器后，跨页面不会重置。
后台标签不自动标记已读，切回聊天页面才刷新可见消息并按既有规则确认已读。
退出登录/失去权限会关闭提醒连接。消费者 App/H5 生命周期本次不改动。

使用隔离浏览器测试 `tests/Browser/admin-support-background.mjs`（所有 API/WS/音频
均为合成桩，无服务端消息写入）验证跨 Inertia 页面不重建播放器/连接、隐藏标签
新消息立即触发声音、切回仍可提醒、后台未提交已读。对应前端 reminder 测试覆盖
未读重复提醒、离线失败、播放失败、用户交互预热和清理。浏览器完全挂起/关闭标签、
禁用本站声音或自动播放时不能保证响铃；页面存活的普通标签切换不再主动暂停提醒。


## 当前访问域名自动识别（2026-10-13）

前端已始终使用当前浏览器域名或原生已验证公司域名连接。Reverb 现在直接读取当前
域名归属来校验来源，不再使用手填 `REVERB_ALLOWED_ORIGINS`；旧变量可以删除，留在
.env 也不会生效。不是将 Origin 原样放行：未知、未启用、未分配或公司停用的域名
拒绝连接，来源格式必须是有效 HTTP(S) origin，目录读取失败也拒绝。

本次须上传 PHP 的 SupportSocketOrigins、CompanySupportSocketServer、
AppServiceProvider 和 config/reverb.php，然后执行 `php artisan config:cache` 并
重启 Reverb 守护进程一次。没有数据库迁移和前端资源变更。之后公司域名变更无需
重启；后台主机名属于私有部署配置，修改 PLATFORM_ADMIN_HOST 仍需刷新配置/重启。
各网站仍须有现有 `/app/` Nginx 代理和 TLS。私有频道仍经当前公司 HTTP 登录/权限
鉴权，握手通过不授予聊天访问权限。
