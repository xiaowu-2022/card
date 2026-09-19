# 全资金流程本地模拟验收（2026-09-18）

## 范围与隔离

仅使用 PostgreSQL `card_ui_test`，运行环境 `testing`；当前 `card_mock` 站点与用户资金未参与测试。
链、支付及卡片外部响应使用 Mock/HTTP fake，财务记账仍通过实际业务服务和 LedgerWriter。
新增浏览器验收站点监听本机 8010，独立会话 Cookie；其入口对数据库名与环境双重检查，并阻止未配置的外部 HTTP。

本报告证明本地模拟业务与界面结果，不代表真实链上转账或真实卡服务验收。

## 资金用例与证据

| 模块 | 验证内容 | 证据来源 |
|---|---|---|
| 充值 | 四币种订单、人工确认、重复确认仅入账一次；TRON 扫描、金额匹配、过期与不足确认 | MultiAssetTest、Trc20SharedTopupTest、WalletTopupTest |
| 提现 | 冻结、审批、链证明、确认后结算、费用、取消/拒绝释放、UNKNOWN 保留、重复验证 | WithdrawalTest、MultiAssetTest |
| 互转 | 精确金额、零费用、权限、跨公司拒绝、幂等；独立进程余额竞争、同请求重放、双向互转 | WalletTransferTest、run_wallet_transfers.php |
| 兑换 | USDC/ETH/BTC → USDT、零费用、报价失效、双边原子提交、精度、并发超支 | MultiAssetTest |
| 返佣与年费 | 首次激活、直接与间接级差资格、购买/升级/续费、保证金抵扣不计佣、自动返还与恢复 | PromotionTest、PaidPromotionTest |
| 保证金与卡 | 主动缴纳、等待退款、卡冻结/恢复、开卡与费用、充值、注销退回、失败/UNKNOWN | SecurityDepositFundingTest、CardIssueTest、LocalCardSimulationTest |
| 理财 | 四币种×七档期限、逐月到期、补跑、取消、利息已消费、停用、隔离、并发与估值 | WealthTest、WealthMathTest |

当前互转入口使用公司的主资金币种，本次验证 USDT；不把此结果表述为四币种互转均已开放。

新增四币种成功链路：充值 100，申请提现 10，USDT/USDC 费用 1、链上净额 9，ETH/BTC 费用 0、链上净额 10。钱包最终均为 90，冻结账户为 0，重复确认无新增分录。

本金 1,000 的各期限总利息（均逐月验证，四币种）：

| 期限 | 年化 | 到期累计利息 |
|---|---:|---:|
| 1 月 | 6% | 5 |
| 3 月 | 8% | 20 |
| 6 月 | 12% | 60 |
| 12 月 | 15% | 150 |
| 24 月 | 16% | 320 |
| 36 月 | 17% | 510 |
| 60 月 | 18% | 900 |

新测试从 2024-01-31 开始创建订单，在每期到期前一秒、到期时刻及重复任务执行后检查余额。月末、闰年、夏令时、微小单位由时间数学测试补充。

## 数据库连接的页面验收

专用公司账户四币种各通过充值业务到账 5,000，再于受控历史时钟下各存入 1,000，六个月期限、年化 12%。恢复当前时间执行 `wealth:recover`，每币种两期共到账 20，钱包为 4,020，在存本金为 1,000。

USDT 从真实页面提交提前取出（包含密码和勾选确认）：本次返还 980，钱包 4,020 → 5,000，原订单变为 CANCELLED。此前已到账 20 加上退款 980 恰为本金 1,000。

继续从页面存入 100：钱包 5,000 → 4,900；未发息即整笔取出：钱包恢复 5,000。每一步读取数据库断言，不以页面成功提示代替到账验证。

页面截图矩阵涵盖 375/768/1440 像素，中文/英文/马来文/西班牙文，资产、资金明细、理财总览/明细、邀请数据、卡片、充值、提现、互转、保证金及会员购买页。另检查 SaaS 理财配置真实保存以及页面读取/配置保存不写资金账本。

## 修复与测试工具调整

- 更新过时的 TRON 测试：不再要求充值后自动产生保证金划拨意图；新增主动缴纳、退款后再次充值仍不自动缴纳的断言，未修改业务资金规则。
- 互转并发脚本原来使用已废弃的 USD 测试清算账户，改为当前 USDT；未修改旧账本。
- 补全新增 BTC 测试的地址校验和链状态 Mock；补齐测试公司的四语言配置。测试模拟不足导致的失败与业务缺陷分开记录。
- 新增脚本仅放在 tests/Acceptance，无业务路由、迁移或管理余额接口。

## 复跑

以下命令会重建隔离测试库；不能与浏览器资金测试同时运行。应用的数据库刷新守卫拒绝 `card_mock`。

```sh
docker compose exec -T app php artisan test
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=card_ui_test app php tests/Concurrency/run_wallet_transfers.php
npm run typecheck
npm run test:i18n
npm run build
node tests/Browser/wealth.mjs
```

浏览器数据须在上述测试结束后准备；每轮从新的隔离测试库开始。setup 拒绝重复创建已准备的理财订单：

```sh
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=card_ui_test app php tests/Acceptance/financial-fixture.php setup
docker compose run -d --no-deps --name card-financial-acceptance -p 127.0.0.1:8010:8010 -e APP_ENV=testing -e DB_DATABASE=card_ui_test -e SESSION_COOKIE=card_financial_acceptance -e SESSION_DRIVER=file -e BLOCKCHAIN_GATEWAY_DRIVER=mock -e APP_URL=http://a.localhost:8010 app php -S 0.0.0.0:8010 -t public tests/Acceptance/router.php
node tests/Acceptance/browser.mjs
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=card_ui_test app php tests/Acceptance/financial-fixture.php reconcile
docker stop card-financial-acceptance
docker rm card-financial-acceptance
```

浏览器脚本只使用本机 8010 和固定隔离环境，输出截图、操作前后余额与对账 JSON 到 `/tmp/card-financial-browser`。验收进程结束后停止专用服务。

## 自动化结果

| 检查 | 结果 |
|---|---|
| 最终全量后端回归（含四币种提现、28 档理财组合及取消断言） | 1,347 通过，14,687 条断言 |
| 后续补充三个兑换方向 | 3 通过，18 条断言 |
| USDT 实际进程并发互转 | 超支竞争、重复请求、双向互转均通过 |
| TypeScript 类型检查 | 通过 |
| 国际化测试 | 75 通过 |
| 前端构建 | 通过；保留现有大包体提示 |
| 变更 PHP 文件格式检查 | 通过 |
| 独立理财 UI fixtures（三尺寸四语言、金额与异常状态） | 通过，单独标注为界面测试 |

额外兑换金额基准：测试快照 USDT/USD=0.999、USDC/USD=0.998、ETH/USD=2,000、BTC/USD=60,000，分别兑换 1 原币。实际到账 USDT 为 0.99899899、2002.00200200、60060.06006006，费用均为 0，原币余额清零，重复确认无重复入账。

## 最终浏览器与对账结果

- 连接真实测试库的浏览器验收通过：132 个用户页面组合、3 个 SaaS 尺寸页面、2 张操作确认/结果截图，共 137 项截图检查。
- 本轮初始账本 16 条；提前取出、新存入、未发息取出合计新增 3 条，最终 19 条。随后所有 GET 页面和 SaaS 修改最低金额均未增加资金账本。
- SaaS 将 USDT 最低理财金额从 1 保存为 2，刷新页面与数据库读取一致，已有订单快照不变。
- 最终账本对账及支付对账通过，差异为 0。USDT 可用余额恢复为 5,000，USDT 在存本金为 0；其他三币种各可用 4,020、在存本金 1,000、已到账利息 20。
- 临时验收容器已关闭并移除；测试库保留本轮隔离记录供复核。未运行真实资金、链上发送或真实 PhotonPay 请求。

### 保存的证据

- [提前取出确认：扣回 20，返还 980](artifacts/financial-2026-09-18/375-cancel-980-confirmation.png)
- [页面存入后的订单详情](artifacts/financial-2026-09-18/375-new-deposit-confirmed.png)
- [资金流水](artifacts/financial-2026-09-18/375-zh-CN-funds.png)
- [理财总览](artifacts/financial-2026-09-18/375-zh-CN-wealth.png)
- [SaaS 配置](artifacts/financial-2026-09-18/1440-platform-wealth.png)
- [操作前余额](artifacts/financial-2026-09-18/before.json)、[取消后余额](artifacts/financial-2026-09-18/after-cancel.json)、[最终余额与对账](artifacts/financial-2026-09-18/after.json)
- [全量测试日志](artifacts/financial-2026-09-18/backend.log)、[补充兑换日志](artifacts/financial-2026-09-18/exchange.log)、[互转并发日志](artifacts/financial-2026-09-18/transfer-concurrency.log)、[浏览器日志](artifacts/financial-2026-09-18/browser.log)、[页面检查清单](artifacts/financial-2026-09-18/results.json)

完整 137 张截图位于本机 `/tmp/card-financial-browser`，上述关键截图已复制进项目，避免只依赖临时目录。
