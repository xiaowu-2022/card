# uni-app 完整用户端迁移验收

2026-09-26：用户明确要求完整 uni-app 实现，生成 H5 后与现有 React/Inertia H5 对比验收。
原版是视觉、交互与业务行为基准；不得以通用表单、页面占位、跳转旧站或 WebView 包装代替迁移。
SaaS/公司管理后台不在本次用户端迁移范围。

## 最新验收状态（2026-09-27）

见 [本轮综合验收报告](ACCEPTANCE_H5_20260927.md)。前端构建、类型和 121 次离线浏览器场景通过；扩大后端集仍有 47 个旧规则测试失败，依赖审计尚未关闭，不能标记整体发布通过。原 H5 未切换，App 验收暂缓。

## 验收规则

- 相同公司品牌、语言、脱敏测试数据、状态和视口下逐页截图；375、768、1440px。
- 登录、未认证、已认证未激活、已激活、受限账户、空状态、加载失败、处理中与 UNKNOWN 均需覆盖。
- 原版入口、表单、校验、确认页、弹窗、分页、返回、语言、未读角标都要对应。
- 金融动作复用后端领域服务；隔离数据库及离线假上游验证，不做真实充值/提现/发卡。
- H5 通过后仍需 Android/iOS 的权限、上传下载、敏感信息、前后台和安全存储验收。
- 编译成功不等于完整验收通过。

## 页面清单

以下路径相对于 `mobile/uni-app/src`。43 个正式页面均有 Vue 实现；开发模拟页 DemoWallet / MockPayment 不向新客户端开放。

| 原页面                 | uni-app 页面                                     | 接口/交互              | H5 对比        | 原生验证         |
| ---------------------- | ------------------------------------------------ | ---------------------- | -------------- | ---------------- |
| Landing                | `screens/Landing.vue`                            | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| About                  | `screens/About.vue`                              | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| AboutArticle           | `screens/About.vue`                              | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| AcademyFeatures        | `screens/Academy.vue`                            | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| AcademyRegistration    | `screens/Academy.vue`                            | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| AcademyRewards         | `screens/Academy.vue`                            | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Account                | `pages/account/index.vue`                        | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| AccountSettings        | `screens/Settings.vue`                           | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| AssetFlow              | `screens/AssetFlow.vue`                          | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| AssetHistory           | `screens/AssetHistory.vue`                       | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Cards                  | `pages/cards/index.vue + components/Card*.vue`   | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Dashboard              | `pages/assets/index.vue`                         | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| ForgotPassword         | `screens/PasswordRecovery.vue`                   | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Kyc                    | `screens/Kyc.vue`                                | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Login                  | `pages/login/index.vue`                          | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Message                | `pages/messages/detail.vue`                      | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Messages               | `pages/messages/index.vue`                       | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| PaidPromotion          | `screens/PaidPromotion.vue`                      | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| PartnerStock           | `screens/PartnerStock.vue`                       | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Promotion              | `screens/Promotion.vue`                          | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| PromotionCommissions   | `screens/PromotionCommissions.vue`               | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| PromotionHub           | `screens/PromotionHub.vue / screens/Academy.vue` | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| PromotionReport        | `screens/PromotionReport.vue`                    | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| PromotionRewardDetails | `screens/PromotionRewardDetails.vue`             | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Register               | `screens/Registration.vue`                       | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| ResetPassword          | `screens/PasswordRecovery.vue`                   | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Restricted             | `screens/Restricted.vue`                         | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Security               | `screens/Security.vue`                           | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| SecurityDeposit        | `screens/SecurityDeposit.vue`                    | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| SecurityDepositHistory | `screens/SecurityDepositHistory.vue`             | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| SecurityDepositSuccess | `screens/SecurityDepositSuccess.vue`             | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Support                | `pages/support/index.vue`                        | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Topup                  | `screens/Topup.vue`                              | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| TopupStatus            | `screens/TopupStatus.vue`                        | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Transfer               | `screens/Transfer.vue`                           | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| VerifyRegistration     | `screens/Registration.vue`                       | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Wallet                 | `screens/Wallet.vue`                             | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Wealth                 | `screens/Wealth.vue`                             | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| WealthOrder            | `screens/WealthOrder.vue`                        | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| WealthOverview         | `screens/WealthOverview.vue`                     | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| Withdraw               | `screens/Withdraw.vue`                           | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| WithdrawalHistory      | `screens/WithdrawalHistory.vue`                  | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |
| WithdrawalStatus       | `screens/WithdrawalStatus.vue`                   | 已接入原领域动作／查询 | 对照场景已纳入 | 编译通过，待真机 |

## 实施顺序

1. 素材、设计变量、公共布局、导航与对比工具。
2. 登录、注册、验证码、找回密码、语言和会话。
3. 资产、卡片、我的及设置/安全/KYC/客服/消息。
4. 充值/提现/兑换/转账/保证金/理财完整流程与状态页。
5. 卡片申请/资料/实体卡/充值退回/注销/展示/交易记录。
6. 推广购买升级续费/邀请与报表/海报/学院/关于文章。
7. 路由操作覆盖、四语言截图对比、差异修正、离线回归、原生编译与真机验收。

## 当前状态

正式用户页面与操作已实现；正在按生成的左右对照图库验收。原 H5 继续作为正式入口，尚未自动切换，也未做 APK/IPA 真机验收。

第一批认证与语言已完成工程检查及修正，等待人工视觉确认；详见
[第一批验收记录](ACCEPTANCE_AUTH_20260926.md)。其他批次仍按原计划逐项检查。

第二批资产及充值/转账/提现/兑换的工程检查、精度与限流修复见
[第二批验收记录](ACCEPTANCE_ASSETS_20260926.md)，视觉差异仍在记录中明确列出。

第三批卡片工程检查与剩余验收边界见
[第三批验收记录](ACCEPTANCE_CARDS_20260926.md)。同日补齐材料上传和实体收件人 H5 交互，
修复图片类型识别阻塞；App 打包及真机验证按用户要求暂缓。

## 可重复执行的对照

```sh
# PostgreSQL 必须是隔离 card_ui_test；测试使用 RefreshIsolatedDatabase 与 fake HTTP。
docker compose exec -T -e UNI_PARITY_EXPORT=1 app php artisan test tests/Feature/ConsumerParityFixtureTest.php
npm run build
npm run client -- build --company local --platform h5
node scripts/client/preview.mjs
# 另一个终端
node scripts/client/parity.mjs
node scripts/client/parity-gallery.mjs
node tests/Browser/consumer-uni.mjs
node tests/Browser/consumer-uni-flows.mjs
```

- `artifacts/uni-parity/index.html`：原版在左、生成版在右，选择页面／语言／375、768、1440px。
- `artifacts/uni-parity/results.json`：每组原版与新版的运行错误、溢出、正文及截图文件名。
- `scripts/client/parity-states.mjs`：明确标记的合成展示状态，用于卡片、UNKNOWN、订单、注册验证码等；不写业务数据库。
- `tests/Browser/consumer-uni-flows.mjs`：离线交互、金额字符串、确认与重试、卡片敏感信息清除、UNKNOWN 不自动提交和注册完成后的会话刷新。
- `tests/Browser/consumer-uni.mjs`：四语言／三尺寸的消息与客服角标、已读水位、纯文本和长标题。

截图工具使用编译后的 React 与 uni-app。API 返回固定 DTO；任何业务 POST 都被拦截，外部请求不放行。
截图无运行错误不等于每个资金结果都已模拟，也不等于逐像素一致。原生权限、Keychain/Keystore 持久登录（当前只用内存）、签名安装及后台行为需真机单独验收。
