# Platform user fund flows

Entry: Users → More actions → Fund flows. A lazy right drawer retains list filters,
pagination and scroll. Its company/user and filter state use `funds_*` URL parameters;
closing removes only these parameters. Do not add a separate wallet-management menu.

`GET /platform/tenants/{tenant}/users/{user}/funds` requires active platform
`users.read`, `wallet.read` and `ledger.read`. A user outside the path's tenant returns
404. JSON is private/no-store and explicitly projected; no raw models, metadata,
credentials or provider responses. Opening the view never provisions a wallet.

`PlatformUserFundsQuery` reads a repeatable-read, read-only snapshot: current account
balances (grouped by currency and account type), distinct event filter options and
25 sealed Ledger entries per page. Every entry must have an owned posting. All joins
enforce tenant/user ownership; tenant clearing and other users' postings are omitted.
One entry appears once even when it moves both available funds and security deposits.
Different lifecycle entries (hold/settle/release) remain distinct actual postings.
Amounts retain native precision. Do not sum different account movements or currencies
into a misleading overall income/expense figure. Card-provider transactions are not
wallet Ledger entries and remain in the card transaction view.

Optional `asset`, `event`, `from`, `to`, `page` filters apply in the database. Date
bounds mean the company's start-day midnight inclusive and next-day midnight after the
end date exclusive, bound with explicit timezone offsets. Display times use the same
company timezone. History without filters has an explicit no-posted-events empty state;
filtered emptiness does not assert that the user never transacted.

Reuse `AssetActivityDetails` for business reason, reference and same-company sender /
recipient email; preserve commission classification. Read queries never synchronize
providers, replay business operations, fix balances, or infer an unrecorded deposit.

Validation: PlatformUserFundsTest covers empty wallets, owned postings, cross-company
and same-company isolation, all required permissions, stable pagination, date boundaries,
ETH native precision, no writes and no external requests. WalletTransferTest covers both
sides' counterparties. Deploy backend and rebuilt admin assets together. No migration,
consumer H5 build or APK packaging is required. This work does not deploy production.

## User list funds and card summaries (2026-10-11)

The user list excludes currently enabled same-company partners. Disabled partner
configurations remain user rows. There is no partner-status filter; legacy `partner`
query parameters are ignored and omitted from pagination links. Partner detail reads
and referrer candidates remain available through their existing scoped endpoints.
Its wide summary layout retains company/referrer filters, pagination, detail drawers and
subordinate actions, with fixed identity/action columns. Current-page identities
(rank and direct inviter) and financial aggregates are batched by company.

The status filter also offers `DEPOSIT_REFUNDING` (退押中): a correlated,
same-company/user refund request in `CHECKING` with no cancellation intent.
Waiting, freezing and blocked requests are included even past their saved deadline;
completed/cancelled requests and cancellation restoration are excluded. This is
a read-only filter over refund evidence, not a new user account status.

`AssetValuation` is shared by consumer `AssetOverviewQuery` and Platform summaries.
It includes available plus withdrawal/card-issue/card-funding holds in USDT, USDC,
ETH and BTC; USDT guarantee; wealth principal per asset; and unarchived cards'
provider USD balance plus their USDT overflow. Holds are explained separately but
counted once. Only fresh saved `MarketPrices::latest()` snapshots are used; USDT
needs no quote and the existing USD/USDT 1:1 convention applies to cards. Required
missing/invalid rates or unknown card balances return null, never guessed parity
or zero. Decimal calculations round the complete total once to eight USDT places.
The modal shows native components, USDT valuations, quote time (or read time when
no quote is necessary), and total. Opening card details switches the same modal
and permits returning to funds details, without stacking dialogs.

`GET /platform/tenants/{tenant}/users/{user}/funds-summary` and `cards-summary`
require active Platform users.read, wallet.read, ledger.read and cards.read.
They validate company/user ownership and run repeatable-read/read-only snapshots,
with private/no-store responses. The list omits estimated/card totals when any
required permission is missing. These reads never call a provider, synchronize
cards, fetch prices, provision wallets or modify financial evidence.

Card details use 20-row pagination and `archived=0|1` (unarchived by default).
Totals cover the complete selected scope, independently of the displayed page.
Cumulative funding = successful initial issue funding + successful LOAD arrival
and overflow funding, excluding opening fees. Unique business order rows are
aggregated before joins; service-provider transaction copies are not counted.
Returned funds = successful RETURN/CANCEL_RETURN arrival credited to wallets.
Current balance = cached provider balance + local overflow. Estimated consumption
including fees = funding - returned funds - current balance. Negative values are
retained. Any unknown current balance makes that card and the complete scope's
balance/consumption totals unavailable, while known funding remains visible.

Annual/activation commissions reuse signed classified PromotionReportQuery income
(including activation/legacy deduplication); annual expense uses completed,
Ledger-linked settlement_total (including deposit conversion and upgrade difference).
Wallet transfer receipts are summed separately by incoming/outgoing and asset.
Successful gross withdrawal requests include their fee and remain per currency.
No mixed-currency sums are shown except the explicitly valued funds estimate.

Deploy changed PHP/routes together with rebuilt public/build. No migration,
consumer rebuild or production financial/cleanup test is required.

## Global fund flows (2026-10-12)

Platform Funds and cards → Fund flows, immediately above Deposit orders, opens
`GET /platform/funds`. This explicitly approved global entry supplements the user
drawer. Active Platform users.read + wallet.read + ledger.read are all required,
including menu visibility. Company administrators cannot read the global endpoint.

`PlatformFundsQuery` reads all sealed Ledger entries (including company account
postings) in a repeatable-read/read-only snapshot. Each business entry appears once
with every same-company, same-currency posting; transfers show both customers and
system accounts are labelled by account type. It does not sum unrelated movements
or currencies. Customer names/remarks are primary; email/account IDs remain available.
Raw metadata, idempotency keys, credentials and provider payloads are never serialized.

Company, customer name/remark/account/email or reference search, currency, event and
inclusive UTC+8 dates are database filters. Exact 25-entry numbered pagination retains
filters and deterministic posted_at/id ordering. GET never provisions wallets, repairs
history, calls providers, or mutates financial data. Card-provider transaction history
remains separate. Deploy matching PHP/admin assets; no schema migration or H5 rebuild.

## Platform card list layout (2026-10-12)

Cards management defaults to the Cards tab; explicit Orders/Loads tab links remain
supported. Its leading columns are company/user, user level, direct referrer,
U-card funding, opening fee, estimated consumption and card wallet balance.
Product/card identity, status and existing actions remain available afterward.
Ranks and same-company direct inviters reuse PlatformUserListIdentity. Funding and
consumption reuse PlatformUserCardsSummary for current-page card IDs, including
archived cards when present in the existing list. Opening fees use each card's
same-company/user successful issue order and retain its native wallet currency.
Card wallet balance includes saved provider balance and local overflow; unknown
balances and estimated consumption remain unavailable, and negatives are retained.
All list projections share a read-only repeatable-read snapshot; no provider calls,
wallet provisioning, migration or financial mutations occur on entry/pagination.

## 2026-10-13 用户管理截止时刻快照与导出

Platform `/platform/users` 接受可空的 `as_of=YYYY-MM-DDTHH:mm:ss`，统一按北京时间
UTC+8 解释；未提供时仍为当前管理模式。提供时仅展示该时刻已注册且已发布的用户，
公司、账号/昵称/联系方式/备注、状态、客服资格、限制、推荐关系、等级、后代人数及
所有列表金额都读取同一历史版本。合作伙伴排除与公司/搜索/状态/客服/推荐人筛选也
按历史版本计算。分页保留 `as_of`，更改条件回到第一页。未来或格式错误的时间拒绝。

时间选择器未带入已有筛选值时默认填入 UTC+8 今天 `00:00:00`，提交查询后生效；
已有时间筛选原样保留，清空时间仍可查询当前管理模式。

迁移 `2026_10_13_200000_create_platform_user_snapshot_history` 在一个事务内锁定
`UserSnapshotSources::COLUMNS` 中的报表输入表，建立共同基线、字段白名单和数据库
捕获触发器。`platform_user_history` 保存安全的只追加行版本、原主键及事务标识；
`platform_user_snapshot_batches` 在事务的最后一条延迟发布事件执行时保存统一发布
时间；回滚不留下版本。批次只允许其创建事务更新发布水位，提交后不可改。此时间是
数据库事务结束阶段的记录时间，而非上游业务发生时间或浏览器时间；只有已提交的
批次能够被查询。相关源表禁止 TRUNCATE，不改变原业务身份、金融约束或 Ledger。

仅支持共同基线后完整记录的时刻；页面显示最早支持时间，不将今天的基线伪装成过去。
白名单只保留既有列表和安全详情所需字段，不复制用户密码、会话/令牌、KYC材料、卡号/
CVV、供应商认证或私人卡申请材料。增加新的报表依赖必须通过后续迁移扩展捕获机制，
不能直接让历史查询落回当前数据。源表主键必须包含在捕获白名单内。

历史查询在一致、只读的 REPEATABLE READ 中暂时使用专用查询语法，将白名单输入
替换成指定时刻的最新非删除版本。递归推荐关系读取同样使用历史关系；查询结束或
异常时恢复查询语法和报表时钟。HTTP授权始终在进入历史范围前按当前有效管理员
身份、成员资格和权限执行，历史管理员资格不能恢复访问。用户详情和资金/卡余额
摘要接口保留相同的 `as_of`；历史模式显示记录的基本详情，关闭当前业务操作页签、
修改入口及此前打开的修改对话框，避免混入实时数据。

金额继续沿用已验证的 Ledger 账户、完整流水、结算和分类证据及本币十进制精度。
历史版本保证钱包、到账分类更正、佣金、年费、转账、提现及卡缓存只反映当时已发布
的状态。卡余额是当时保存的缓存，不宣称能还原未观察到的供应商真实余额。估值采用
当时已保存且仍符合原120秒有效期规则的行情；不使用截止时刻之后的行情，不发起
供应商或行情请求。原本未知的卡余额/估值保持未知。

`GET /platform/users/export` 使用同一套筛选、排序、用户DTO和实时列权限，忽略
`page`，分批输出全部匹配用户。最多10,000行，超限返回422，不截断；无匹配结果
仍有表头。CSV为中文表头、UTF-8 BOM与CRLF，身份和多币种金额拆列，附UTC+8
快照时间；保持十进制字符串、明确未知值、防止公式注入、不落盘、不缓存。

永久数据清理的明确批准范围仍优先：相应历史副本进入同一预览指纹、私有七日加密
备份及逐行删除清单，删除捕获不重新保存这些副本。已完成清理后，受影响公司只允许
清理完成后的完整快照；全平台查询采用所有公司的共同可用起点。更早时间拒绝，避免
订单单独清理后旧钱包缓存与已删除证据形成不完整报表。其他公司的独立筛选不受影响。

## 2026-10-21 修改推荐人下拉搜索

用户编辑中的“修改推荐人”直接使用可搜索下拉框。输入姓名、邮箱、账号 ID、用户
UUID 或推广成员 UUID 的片段即可匹配，支持大小写不敏感和去连字符的 UUID 搜索。
搜索只请求同公司候选 JSON，不导航或刷新编辑区域，保留原因与已选目标；搜索、重新
选择后重新确认。每次读取 20 条，可加载更多、失败重试，取消旧请求并丢弃过期响应。

候选只包含当前公司有效用户，排除本人、当前推荐人和全部下级。读取沿用
users.read + users.referrer.manage，使用只读快照、private/no-store，不创建推广成员或
触碰资金证据。提交仍走原有公司/用户锁、版本检查、UUID 幂等、原因、明确确认和
不可变调整记录；候选选中不替代提交时的归属与循环校验，历史佣金不重算。

新增 GET `/platform/tenants/{tenant}/users/{user}/referrer/candidates`。部署 PHP 与匹配
后台资源，无新增迁移，无需 H5/原生重编译。本地隔离回归和离线浏览器夹具位于
`output/referrer-search-20261021/`，未在生产调整推荐关系或执行供应商/资金测试。
