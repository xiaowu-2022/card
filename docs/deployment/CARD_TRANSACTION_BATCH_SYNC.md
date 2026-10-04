# 后台按日期批量同步卡片流水

卡片管理 → 卡片列表 → 批量同步流水。操作者须同时具有平台 `cards.read` 和
`card_product.manage` 权限。可选择公司或全部公司；预览及提交均不受列表筛选和分页影响。
任务列表显示当前操作者最近 20 个批次，关闭页面不取消任务。

## 发布

部署本次后端代码及 `npm run build` 生成的后台资源，然后执行：

```sh
cd /www/wwwroot/card
/www/server/php/84/bin/php artisan migrate --force
/www/server/php/84/bin/php artisan queue:restart
```

通过 Supervisor / systemd 常驻运行独立 worker（不要只依赖默认队列 worker）：

```sh
/www/server/php/84/bin/php /www/wwwroot/card/artisan queue:work database --queue=card-transaction-sync --sleep=1 --tries=1 --timeout=60
```

数据库队列 `retry_after` 必须大于 60 秒（项目默认 90 秒）。进程管理器须自动重启退出的
worker，停止宽限期至少 90 秒。多台主机必须共用数据库与支持原子锁的缓存；PostgreSQL
连接必须支持 session advisory lock，不可通过 transaction-pooling 模式运行该 worker。
同一卡及同一提供商账户的批量任务使用数据库 session 锁，账户分页调用起始间隔至少一秒。

保持现有 `schedule:run` 每分钟执行；新增恢复调度仅恢复已提交的待处理任务，不创建新批次。
也可手动恢复待处理队列：

```sh
/www/server/php/84/bin/php /www/wwwroot/card/artisan cards:recover-transaction-sync
```

部署不会自动同步历史数据。由管理员确认范围后提交。无需重新打包 App。

## 日期与进度

日期按北京时间，包含起始日，包含结束日全天，最多 366 天且不能超过今天。
逐卡按每页 20 条遍历全部提供商流水，只保存日期范围内的记录；不向上游传入未经验证的
日期参数，不因乱序或日期外数据提前结束。`txnDate` 缺失时使用现有解析器的 `createdAt`，
无时区时间仅在这次筛选中按北京时间解释，不修改原列表记录时间／结算时间展示。

冻结、注销、归档卡只要仍有有效绑定也纳入。无有效 PhotonPay 卡号绑定的卡标记跳过。
卡片集合提交后固定；权限或绑定改变会阻止后续处理。页面显示累计页数及记录写入次数，
写入次数包含重复读取及重试，不能当作新增流水数。失败卡片可单独重试；手动重试从第一页
开始，以应对上游分页偏移变化，成功卡片不重跑。普通限流／暂时失败退避 30、120、300 秒。
worker 中断后的持久化租期为 120 秒，恢复仍从未提交成功的页开始，连续中断四次终止。

所有写入复用已有按卡／提供商流水号幂等更新及读取起始时间防覆盖规则，不删除旧流水，
不产生账本、充值、发卡、退款或余额调整。每页流水写入与检查点在同一事务提交。

## 排查

普通日志包含批次编号、请求编号、内部卡片编号、页码及安全错误分类，不包含卡号原文、
Token、密钥或原始响应。原始请求／返回仍通过 PhotonPay 专用加密日志保存，沿用
`PHOTONPAY_REQUEST_LOG_ENCRYPTION_KEY` 配置及 `photonpay:request-log` 查询命令。
权限撤销、绑定变化、分页异常、日期异常、持续上游失败都会在批次中显示失败原因。
若任务长期待处理，检查独立 worker、调度器、共享缓存与数据库连接；不要通过删除旧流水
或重放业务订单修复。
