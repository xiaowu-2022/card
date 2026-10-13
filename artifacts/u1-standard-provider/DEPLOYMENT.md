# U1 标准接入交付

代码、后台/H5 资源及迁移一起部署。未含 .env、数据库、密钥或客户数据；未上线调用供应商。
部署时保留旧哈希资源，先发布新增 assets，再切换后台 manifest 与 H5 index。
线上使用 CARD_PROVIDER_DRIVER=directory、QUEUE_CONNECTION=redis 和已有受管 queue worker；APP_URL 为公网 HTTPS 后台地址。
保留 APP_KEY，按顺序执行下列迁移（已执行的迁移 Laravel 会跳过）：

```sh
php artisan migrate --path=database/migrations/2026_10_13_210000_create_u1_notification_testing.php --force
php artisan migrate --path=database/migrations/2026_10_13_220000_enable_standard_u1_card_provider.php --force
php artisan migrate --path=database/migrations/2026_10_13_230000_add_u1_callback_processing.php --force
php artisan migrate --path=database/migrations/2026_10_13_240000_preserve_u1_resource_evidence.php --force
php artisan migrate --path=database/migrations/2026_10_13_250000_serialize_u1_bin_claim_updates.php --force
php artisan config:cache
php artisan view:clear
php artisan queue:restart
```

重载受管 PHP-FPM；队列 retry_after 大于任务 timeout=60（默认90）。不新增金融定时任务、不自动开卡。
现有原生 App 的验证码入口需要重新编译安装。

配置：卡商列表选择 U1，填名称/customerNo/apiKey，保存并显式检查账户/同步卡组，启用新业务。
复制本连接回调到 U1。支持正常虚拟开卡、余额/卡面/交易读取及异步核实；费用确认后允许充值及 SHARE 组提现。
暂不传上游 requestId；丢失响应而没有 apiId 不自动重发。冻结/注销等未文档化接口不开放。

详细规则见 docs/architecture/U1_CARD_PROVIDER.md；验证见 verification.json。
U1专项65项/533断言，相关回归52项/521断言及Chrome/WebKit页面检查通过。
扩展旧开卡套件有19个原有失败，修改前相同位置也失败，见 baseline-comparison.json。
真实通知金额字段覆盖待用户在公网配置后联调。
