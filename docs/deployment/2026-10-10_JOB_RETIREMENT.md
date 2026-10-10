# 2026-10-10：旧卡流水任务退役与充值扫描部署

本次只退役旧卡流水后台队列，保留浏览器手动同步、历史记录和充值自动扫描。
生产日志中 722 次 jobs 表不存在来自旧卡流水恢复；605 次 HTTP 429 和 2 次网络异常
来自 TronGrid 充值扫描。二者独立，删除旧 job 不会解除充值限流。

## 上线文件

推荐发布当前完整版本；如使用覆盖式发布，至少核对以下文件和它们现有依赖已匹配：

- `routes/console.php`
- `app/Application/Card/BatchCardTransactionSync.php`
- `app/Http/Controllers/Platform/CardTransactionBatchController.php`
- `app/Infrastructure/Providers/Blockchain/TronGridBlockchainGateway.php`
- `app/Application/Payment/ScanTrc20TopupsAction.php`
- `app/Application/Payment/ProcessIncomingTrc20TransferAction.php`
- `app/Console/Commands/ScanTrc20Topups.php`
- `app/Console/Commands/DiagnoseTrc20Topups.php`
- `config/payment.php`（含私有环境变量 TRONGRID_API_KEY 的读取）
- 完整 `public/build`（已重新构建，包含操作结果确认弹窗和旧同步停用状态）

必须删除：`app/Jobs/SyncCardTransactionPage.php`。
不要删除任何数据库表、队列数据、订单、流水或检查点。本次无新增迁移，不需要 App/H5 重打包。
历史部署仍需具备 2026-10-04 的 `execution_mode` 迁移和当前版本其余既有迁移。

## 顺序

1. 停止仅消费 `card-transaction-sync` 队列的旧 worker，并取消其自动启动。
   如果没有该 worker，跳过。移除单独配置的旧恢复命令 cron（如有）。
   保留正常的每分钟 `artisan schedule:run`，不要停用 `topups:scan-trc20` 或其他业务任务。
2. 发布匹配代码及完整后台资源，并删除上面列出的旧 job 文件。
3. 用户在服务器私有环境配置中填写可选 `TRONGRID_API_KEY`，不要放入发布包或前端。
   使用生产实际 PHP 可执行文件，在应用目录执行 `php artisan config:cache`。
   如生产使用优化/权威 Composer 类映射，按现有流程运行
   `composer dump-autoload --no-dev --optimize`。
4. 按现有运维流程重载 PHP-FPM/opcache 和常驻 scheduler，使旧代码退出。
   不清理共享 Redis 缓存，不重置扫描游标，不额外添加充值扫描 cron。
5. 可选执行 `php artisan topups:diagnose-trc20`，只读查看 CLI 版本、配置是否缓存、
   key 是否存在/格式合法、冷却时间和扫描游标。该命令不请求链上服务、不验证 key 的
   真实有效性、不扫描订单、不改变资金，不能证明线上某笔订单已到账或 FPM 已更新。

API key 不保证无限流量；上游仍可能限流。已有共享冷却和合并扫描窗口会降低重复请求。
本次只完成代码与隔离回归验证，未操作生产服务器或核对生产订单。

自动充值扫描已限制为近 1 小时创建的未完成订单；超过范围的订单保留原状态，需显式人工处理。旧扫描进度不会继续追赶一小时前的历史。
