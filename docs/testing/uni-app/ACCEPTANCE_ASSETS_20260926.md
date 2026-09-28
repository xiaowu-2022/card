# 第二批验收：资产、充值、转账、提现、兑换

2026-09-26。对照对象为原 React H5 与编译后的 uni-app H5。
本批检查完成后仍保留旧 H5；不代表整站或 Android/iOS 真机验收完成。

## 本批修复

- 复现消息未读查询占用转账/资产操作限流桶，导致合法请求返回 429。
  转账、资产订单、兑换确认、提现取消改为独立计数，保留原次数、窗口和认证规则。
- 新版 USDT 提现手续费和净到账金额原先被两位小数格式化；改用精确字符串显示。
  例如 1.01 USDT、0.1% 费率显示手续费 0.00101、到账 1.00899，而非 0.00/1.01。
  仅修复显示；资金计算、订单快照和 Ledger 不变。
- 资产操作页币种选择恢复图标与灰底布局，网络选择移除重复标签；提现明细及按钮对齐旧版。
- 补齐用于截图的已启用币种网络、可兑换、报价待确认/过期/完成状态。
  这些是明确标记的合成显示数据，不修改任何公司的真实配置。

## 验证证据

| 检查 | 结果与范围 |
| --- | --- |
| 后台回归 | 321 项通过、2,412 个断言；包括转账、充值、TRC20、提现、多资产、账户完整性及 Consumer API |
| 新增浏览器交互 | 10 项：四币种精度/超精度/同请求重试，提现小额费用、修改后重审，兑换精确报价/过期/USDT 禁用，BTC 充值请求 |
| 既有浏览器流程 | 8 项回归通过，含转账、提现、UNKNOWN、敏感字段清理和加载失败重试 |
| 四语言三尺寸对照 | 26 个场景 × 中/英/马来/西班牙语 × 375/768/1440px，共 312 组 |
| 构建 | H5、App 资源构建和 Vue/TypeScript 检查通过；App 资源不是 APK/IPA |

新增真实 Laravel API 流程覆盖：原生转账在多次未读查询后仍可提交、同请求不重复记账、
跨公司凭据拒绝；H5 与原生的充值指令创建、提现冻结/取消、兑换报价/确认与重复确认。
所有数据库操作只在隔离 `card_ui_test`，上游使用已有 mock/fake；没有真实充值、提现或交易。

最初回归失败还暴露两处旧测试准备过时：充值测试使用 MY 国家但未指定护照、也未 mock OCR；
提现测试仍期望生产绑定旧的 unavailable 网关。测试准备已按批准的 CN 双面/OCR 和公共 TRON
网关规则更新，保留 fail-closed 与生产禁止 mock 的断言，并禁用测试中的 SSR 网络访问。

浏览器交互使用拦截响应验证生成页面；后台测试验证真实 Laravel 请求，两者分开执行。
不能据此宣称已完成浏览器与真实后端全链路、真实链上到账或原生真机验证。

## 复跑

```sh
docker compose exec -T app php artisan test --compact \
  tests/Feature/WalletTransferTest.php tests/Feature/WalletTopupTest.php \
  tests/Feature/Trc20SharedTopupTest.php tests/Feature/WithdrawalTest.php \
  tests/Feature/MultiAssetTest.php tests/Feature/WalletAssetIntegrityTest.php \
  tests/Feature/AssetMarketVisibilityTest.php tests/Feature/PublicWithdrawalNetworksTest.php \
  tests/Feature/ConsumerApiTest.php tests/Feature/ConsumerClientTest.php
node tests/Browser/consumer-uni-assets.mjs
node tests/Browser/consumer-uni-flows.mjs
npm run client:typecheck
npm run client -- build --company local --platform h5
npm run client -- build --company local --platform app
```

截图首次出现一次提现历史资源 404，单页交互检查未复现；验收索引采用相同条件复测结果。
截图脚本现记录失败资源 URL，方便后续定位偶发问题。

浏览器证据：`artifacts/uni-parity/assets-acceptance/`。
预览服务启动后访问 `/__parity/index.html?path=/dashboard&lang=zh-CN&width=375`。
完整对照索引保留其他批次结果，不能将截图数量等同于整站验收通过。

## 仍需人工确认

截图运行错误/横向溢出检查与人工抽查，不等于逐像素一致。
提现历史入口在新版单独一行，旧版小屏头部存在标题重叠；此处尚有布局差异，保留供对照确认。
系统选择器交互、字体/间距及 Android/iOS 键盘和安全区仍需视觉及真机验收。
下一批为卡片相关页面与完整操作流程。
