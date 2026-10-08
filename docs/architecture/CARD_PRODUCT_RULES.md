# Card Product Rules

Phase 9 defines the minimum catalog for the future PhotonPay card flow. V1 supports only the PhotonPay Mille Card regular-card family: shared cards are deferred. Platform owns each provider-backed product and its opaque CardBin/provider reference, USD card currency, REGULAR type, minimum initial load, minimum reload, and lifecycle status. Business code must not infer behavior from the CardBin.

Tenant does not create arbitrary provider products. `tenant_card_product_configs` only controls the customer display name, future opening fee in USDT, maximum cards per user, availability, and sort order for the resolved Tenant. Tenant code cannot change provider identity, currency, type, or provider minimums, and must never read or mutate another Tenant configuration.

The Demo business conversion is exactly `1 USDT = 1 USD` with no FX engine. Opening fee is the only platform charge and configuration moves no money. Minimum initial load and reload are provider product rules, both `20.00000000` in the Demo seed. There is no platform card-load fee, local card transaction-fee engine, pricing versioning, or per-user override.

Phase 10 consumes this catalog without widening it. The initial issue amount must be at least the snapshotted Provider minimum; `arrivalAmount` is the requested initial USD Card balance under the locked Demo `1 USDT = 1 USD` rule. Wallet debits are Ledger-authoritative. PhotonPay card balance is Provider-authoritative; the local value is only a timestamped cache. Once a real card references a product, provider reference, currency, and card type cannot be changed silently; changing identity requires a new product. Existing-card load fees, Tenant-created Provider products, local FX, and per-user overrides remain deferred.

## 2026-10-08 月费与卡片备注展示

Platform 卡片产品新增／编辑增加两个可选字段：`monthly_fee_text`（最多 255 字符的文本）
及 `notes`（最多 5000 字符的多行文本）。均为该产品共享的公开展示信息，沿用
`card_product.manage` 权限、产品锁与同事务审计；未传字段保留原值，显式空值清空。
历史产品初始为空，不填充或重算旧记录。备注保留内部换行，用户界面以转义文本呈现，
不解析 HTML，也不翻译管理员填写的内容。

产品目录 DTO 增加 `monthlyFeeText` 和 `notes`，App/H5 选卡列表及开卡确认页展示，
空值隐藏。公司产品配置只读展示平台值。月费是说明文字，可填“首月免费，之后每月 2 USD”
等描述；不参与开卡费、初始充值、最低可用余额、钱包扣款或 Ledger，也不产生月费扣款任务。

部署顺序：先运行 `2026_10_08_230000_add_display_text_to_card_products` 迁移，再发布
匹配的 PHP、后台构建资源及 `public/h5`。网页壳读取线上 H5，无需重新打 APK；
旧原生业务 App 需要重新编译打包才显示新字段。无历史订单或资金变更。
验证：`tests/Feature/CardProductTest.php` 覆盖创建／编辑、审计、省略与清空、长度及类型、
目录返回及金额不变，使用隔离测试数据库与禁止外网的模拟提供商。
