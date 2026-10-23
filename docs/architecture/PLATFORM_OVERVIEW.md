# Platform operating overview

Approved 2026-10-12. `/platform/demo` keeps its existing route and becomes a
full-platform operating dashboard. Platform root and authorized administrator login
land on it; identities without tenant.read retain their company-directory fallback.
The main overview is independent of the retained
historical-analysis company/date filters. Historical charts are collapsed by default
and keep their previous gross-withdrawal accounting, explicitly labeled separately
from the new net-payout overview.

## 2026-10-23 申请退押途中金额

首页“存量”之后新增同样黑色可点击金额卡片，字段 `pendingDepositRefunds`。
全平台汇总所有公司、普通用户和合伙人的有效退押申请：状态 CHECKING、未请求取消、
无退款 Ledger 入账且创建时间不晚于当前快照。包括尚在等待期、已到期和阻塞处理中的
申请，不限近 3/10 天，也不与合伙人团队重复相加。已完成到账、已取消及撤销恢复中不计。
金额使用申请时保存的原精度 USDT，不读取可用余额推算，不改变存量、对账或钱包。
来源 `metric=pendingDepositRefunds` 复用相同查询、截止时间和现有作用域权限、只读快照、
25 条编号分页，展示公司、用户、申请金额及申请时间。零值仍可打开来源。
来源增加“上级合伙人”列：沿当前同公司邀请链跳过普通及停用合伙人，最多取最近两位
已启用上级合伙人，由近到远分两行显示名称（缺少名称使用账号）；仅一级显示一条，
无上级显示破折号。按当前页批量读取并复用来源快照与 users.read 权限，不变更金额范围。
仅需发布 PHP 与后台资源，无迁移、H5 或原生修改；隔离/离线验证，不操作生产资金或供应商。

## Confirmed amounts

- Stock, theoretical balance, actual balance and difference sum **currently enabled
  top-level partner reports** across every company, including signed negative values. Disabled
  partners are excluded. Actual means the report's own USDT available-wallet amount,
  not all consumers' wallets or multi-asset valuation. Theoretical retains own annual
  expenses, classified/unclassified commissions, net advances/reimbursements and
  signed adjustments. Difference sums the existing report differences.
- Top-level means no currently enabled partner in the same-company invitation
  ancestor chain, traversing ordinary users and disabled partners. Stock sums only
  these root reports, retaining each report’s existing descendant scope. Operational events and alerts are independently aggregated
  once per company/business record rather than summing overlapping partner reports.
- Reimbursements sum all existing reimbursement journals net of reversals.
- Recharge counts successful credited primary/multi-asset orders and their immutable
  actual receipt amount (original amount only for older receipt evidence). Withdrawal
  counts successful primary/multi-asset payouts **excluding fees**. Completion/credit
  times own the reporting dates; pending/unknown/failed operations are excluded.
- Card balance sums saved provider balances plus local overflow of all issued cards,
  including archived cards. Funding sums unique successful initial issue arrivals
  and LOAD arrivals plus locally funded overflow, excluding opening/load fees. Returns
  are not deducted from cumulative funding. Provider transaction copies never count.
- Third-party balance uses the same source as the provider directory: API-connected
  entries use the existing 60-second cached, verified PhotonPay merchant report;
  unconnected entries use manual `reference_balance`. Sum all active directory entries
  across pages, excluding deletion audit tombstones. USD/USDT follow the existing U
  presentation; no market conversion is introduced. Any missing or unsupported balance
  makes the entire total null (Unavailable), with no manual fallback or partial total.
- Partner/non-partner asset estimates partition all users by current enabled partner
  state; disabled partners are non-partners. Reuse AssetValuation with four native
  wallets/holds, USDT deposit, wealth principal and unarchived card/overflow funds.
  Saved fresh rates only; necessary unknown rates/card balances yield null. Aggregate
  native components before valuation and round the complete group once.
- Bubble remains null and its visual field is empty.

## Time windows and operating evidence

Platform calendar days use Asia/Kuala_Lumpur (UTC+8), matching historical overview.
Today runs from local midnight through the captured read timestamp. Three/seven/
fifteen/thirty-day figures are **sums including today**, not averages; the N-day window
starts N-1 days before today. Lifetime receipt/payout/funding sums are independent of
recent windows. T+1 describes the arrival rule, not a shift in reporting date.

The six operating columns reuse PartnerBusinessStock evidence: first successful
deposit activation, completed annual settlement, completed deposit refund, signed
activation/legacy commission, approved annual return and signed annual commission.
Only the two ordinary-member deposit columns exclude currently enabled partners;
other operating totals cover all company users. Disabled configurations remain ordinary
members. No historical balances or repeat funding are invented as first activation.

70% annual alerts reuse active-cycle weighted progress and outstanding paid amounts
after approved returns. Deposit alerts reuse CHECKING requests without cancellation
intent and saved eligible dates between now and the next 3/10 days inclusive. Undated,
already-overdue, completed/cancelled requests do not count; 3 days is a subset of 10.

## Read isolation and delivery

2026-10-12 presentation correction: remove repeated calculation, evidence-source and
deduplication explanations from the homepage, including collapsed history. Retain only
scope/unit/time metadata, T+1, rate timestamp when present, unavailable states and short
net/gross/reference qualifiers in labels. Accounting and permission rules are unchanged.

Active Platform scope and tenant.read remain mandatory; existing scoped permission
catalogs gate each section server-side. Partner/operation sections additionally require
users.read, wallet.read, ledger.read and partners.manage; assets require the first
three plus cards.read. Card funding/balances require cards.read and ledger.read;
provider references require provider_operation.read. Cash flows retain their existing
wallet_topups.read / withdrawals.read gates. Company administrators cannot use this
global endpoint.

Controller reads run in one repeatable-read/read-only snapshot and return private,
no-store responses. Never provision wallets/members, change user status, refresh
prices, contact chains or replay financial operations from this page. The only remote
read exception is the existing provider-directory merchant report for third-party balance.
All financial values remain decimal strings, formatted with insignificant zeros
removed; null is never rendered as a fabricated zero.

Deploy matching PHP and rebuilt public/build together. No schema migration, H5/App
rebuild or production financial/cleanup test is needed. Automated evidence lives in
the isolated card_ui_test fixtures and offline Chromium/WebKit overview fixtures.

Local verification: PartnerStockTest, PlatformDailyFundsTest, PlatformFundsSummaryTest,
AdminAuthenticationTest and PlatformUserFinancialsTest plus the new card/actual-receipt
overview scenarios pass. TypeScript and changed-file ESLint/Pint checks pass. The
offline browser fixture covers Chinese/English at 375/768/1440/1920px, exact large
and negative amounts, missing balances, a blank bubble, refresh and gated sections.
Full i18n checks retain five pre-existing failures outside this feature (obsolete
wallet/manual-receipt source assertions, a cleanup-dialog translation, a consumer
breadcrumb and a KYC error translation); relevant funds/overview checks pass.

## Source dialogs (2026-10-12)

Every displayed metric amount, including zeros and unavailable values and native cash
totals, is a semantic keyboard-focusable button in the existing foreground colour.
Bubble stays blank. Clicking opens a modal, never replacing the dashboard or its
historical filter state. Loading, retry, empty, exact numbered pagination, Escape and
focus return are supported.

`GET /platform/overview/sources` validates the metric/period/page/native-asset selector
and requires active Platform scope plus the same metric permission conjunction as the
homepage. Company accounts are rejected. Customer summaries require users.read.
The response explicitly projects identities/source IDs, native amounts, saved-rate
estimates and current read time; no raw metadata, credentials or provider payloads.

Sources are successful immutable receipts/payouts, unique issue/load arrivals, all
issued card cached balances plus matching overflow, enabled partner report contributors,
signed reimbursement journals, eligible native asset accounts/unarchived cards, original
activation/business events and annual-cycle/refund alerts. Shared source builders keep
settlement, fee exclusion, partner exclusions, inclusive UTC+8 periods and deduplication
aligned with totals. Asset components retain quote precision; the aggregate keeps the
overview's round-once valuation. Partner totals and source pagination use the same current enabled top-level
partner selector; nested partner reports are not added separately. Provider rows use the same
cache as the directory and exclude deletion tombstones.

Each modal read uses a repeatable-read/read-only database snapshot and returns private
no-store JSON. Amounts/current balances are refreshed when opened or paged, and the
modal shows its read time and aggregate; changes since the initial dashboard load are
not fabricated as historical balances. Reads never provision, refresh market rates,
replay payments or mutate financial evidence. Only the approved provider-balance
merchant reporting exception may contact a provider. No migration or H5 rebuild.

## Actual receipt exclusion (2026-10-12)

Homepage recharge periods and their source dialogs only count CREDITED receipts whose
current effective classification is ACTUAL: automatic arrivals and manual actual
confirmations. Use DepositReceiptClassification for both primary/multi-asset orders,
including the latest same-company append-only correction. Initial or corrected ADVANCE
receipts are excluded; changing back to ACTUAL restores the settled amount exactly
once. Amounts remain actual_received_amount with original amount fallback for legacy
settlements. This does not alter wallets, original receipts, advance journals, global
fund flows or the independent historical section. No schema migration or frontend
change is required.


## 2026-10-13 后台北京时间

后台日期显示、今日/近 N 日统计、合作方每日统计及资金日期筛选统一使用
Asia/Shanghai（北京时间 UTC+8），不跟随浏览器、公司或服务器时区。今日从北京
时间 00:00 开始，次日 00:00 不包含；实时汇总及来源明细共同截止当前读取时间。
绑定 PostgreSQL timestamptz 查询边界必须保留 ISO 8601 时区偏移，不能把 UTC Carbon
对象直接绑定成无时区字符串，否则 UTC+8 会把起点误读为前一天 16:00。
历史时间戳、公司配置、业务结算及消费者时区规则不重写，无需迁移。部署 PHP 与
匹配后台构建；仅在隔离数据库验证 UTC / UTC+8 会话下的零点前后和次日边界。

## 2026-10-21 实际充值金额显示调整

总览“实际充值金额”增加“调整及记录”。Platform 管理员可登记非零正负 USDT
显示调整（最多 18 位整数、8 位小数），必填原因、北京时间调整日期并明确确认。
日期不晚于北京今天，仅用于调整记录。按用户补充要求，仅累计显示值应用调整；当天及近 3/7/15/30 天保持原始实际充值统计。
结果保留负数。原始累计充值统计 + 显示调整净额 = 当前累计显示合计；原始估值不可用
时仍不可用，不用调整数替代缺失的充值证据。更正只追加反向调整，历史不可改删。

`platform_overview_recharge_adjustments` 是独立的全平台显示日志，不归公司或用户，
记录金额、日期、原因、操作人、请求哈希和登记时间。它不修改充值订单、到账分类、
用户/公司充值统计、历史资金分析、预支、合作日志、Wallet 或 Ledger。原始
`cashSource` 保持仅已到账有效 ACTUAL 充值。总览 USDT 累计显示及累计来源弹窗共同应用调整；
来源页单独列原始充值与分页调整记录，展示原值、调整净额与显示合计，保留同一快照
和读取时间上限。其他原币的来源不计 USDT 调整。

GET/POST `/platform/overview/recharge-adjustments` 要求 active Platform tenant.read +
wallet_topups.read，写入额外要求 wallet_topups.confirm，并在事务内重新检查。
POST 使用 UUID 幂等、全局事务锁及原子审计 `OVERVIEW_RECHARGE_DISPLAY_ADJUSTED`；
相同请求原样返回，不同内容冲突。GET 私有/no-store、20 条编号分页。来源接口新增
独立 `adjustment_page`，不改变原始充值分页状态。读取不请求供应商或刷新汇率。

部署新增迁移 `2026_10_21_110000_add_overview_recharge_adjustments`、PHP 与后台资源；
有调整历史时禁止回滚删除。仅后台改动，无须重编译 H5 或原生 App。不在生产登记
测试调整或运行资金测试。

本地验证与交付：`output/overview-recharge-adjustments-20261021/`。最终隔离回归
8 项通过、116 条断言（含推荐人搜索联合回归）；浏览器通过负数录入确认、记录展示、备注转义与独立来源分页，
类型检查与后台生产构建通过。登记时间和历史 DTO 使用显式 ISO 偏移，跨数据库时区
的读取截点验证通过。未在生产应用迁移或登记调整，无供应商调用。
