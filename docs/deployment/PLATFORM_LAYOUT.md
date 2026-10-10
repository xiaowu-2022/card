# SaaS 公共布局与公司设置选项卡

公司抽屉发布平台管理端和配置 DTO；后续域名调配变更需要下述数据库迁移，不发布 uni-app H5，不重新打包 APK。

## 公共规范

`PlatformLayout` 必填页面标题，接收说明和主要操作。顶部栏固定 56px，标题在左，
操作与账户控件在右。内容只有一个 main，外边距 20px、区块间距 16px，去掉嵌套居中
和 1600px 最大宽度。短表单保留左对齐限宽，客服、KYC、报表保留内部工作区。

`PlatformUiContext` 将 36px 控件、10px 表格上下内边距、16px 面板和弹窗内边距限定在平台端，
包括 React Portal 中的弹窗和抽屉。原 PageHeader、公司后台和用户端不采用此尺寸。
表格内部滚动、固定身份／操作列继续保留；没有全局隐藏标题或覆盖所有元素的 padding。

## 操作结果弹窗（2026-10-10）

SaaS 操作成功／失败统一由应用级 `OperationResultHost` 展示居中确认弹窗，
挂载到 body 并位于配置抽屉及业务确认弹窗上方。结果不自动消失，不通过遮罩或 Escape
关闭，必须点击“确定”；键盘焦点进入确认按钮，关闭后回到仍存在的操作控件，不滚回顶部。

普通 Inertia 提交接入成功、校验错误、HTTP 与网络错误事件；公司配置保存与独立 JSON
操作使用同一结果通道。原结果文字使用 `OperationFeedback`，不再占据页面顶部或抽屉
滚动区。同一次失败的字段／运输层重复消息合并，关闭后再次失败仍须提示；编辑器关闭或
列表刷新不丢失尚未确认的结果。字段旁的纠错提示、静态资格说明和持续任务进度保留。
批量同步在完成／部分失败时提示结果，不在每个自动分页请求后打断任务。

范围仅限 `/platform`，共享组件在公司后台和消费者页面保持原有展示。业务权限、
确认步骤、幂等键、审计与账务逻辑不变。重新构建管理端资源即可，无数据库迁移或 H5／APK
重建要求。离线验收：`node --test tests/Frontend/admin-operation-results.mjs`；
`node tests/Browser/operation-feedback-fixture.mjs` 提供合成数据的长页／抽屉验收页面，
覆盖成功、失败、相同错误重试、网络异常、焦点和草稿保留，不发送业务请求。

## 公司设置（2026-10-08 统一抽屉）

公司管理 `/platform/tenants` 是唯一公司目录，移除独立公司配置菜单与重复列表。
行内“配置”打开右侧视口抽屉，宽度最多 1200px，小屏全宽；公司名称、标识、状态、
单排滚动分类与关闭操作保持可见，正文独立滚动。保留列表统计、筛选、分页和焦点。
默认基本信息，分类依次为基本信息、域名、品牌与 App、语言、业务规则、资金、
产品配置、推广、理财、关于我们、短信、邮件、管理团队、客服时间、快捷回复、客服机器人。
只读公司详情仍可供 tenant.read 用户使用；每项写入继续要求原有权限。

每次只加载选中的公司类别。公司身份通过原有 DTO 和 X-Admin-Company 一致性检查确认；
此请求头不能代替鉴权。客服类别继续要求 support.read 和对应 manage 权限，不要求
额外 tenant.manage。仅配置权限的账号可读最小公司目录，不因合并获得财务统计。
数据库、锁、修订号、生命周期条件与审计不变；未授权类别不显示，直接请求仍校验权限。

保存成功停留原分类，刷新已保存数据；其他未保存表单保留输入。修订号随新的 DTO 更新。
关闭、切换和离开有未保存保护；保存中锁定关闭与分类切换。产品、团队、FAQ 和快捷回复
在抽屉正文内编辑，域名分配、移除及公司启停继续显式确认。公司域名仅列本公司和可分配
的未绑定有效域名，不能改绑其他公司的域名。全局域名创建/验证仍属系统设置。

`editor` 查询参数承载独立详情 URL，保留分类内部搜索、分页与浏览器历史。
旧 `/platform/company-configurations`、公司详情及公司配置直达链接进入统一目录；
旧 section/editor 和合法公司筛选继续解析。客服全局 FAQ、接待工作区和用户管理中的
客服人员授权保持独立；公司客服设置链接进入抽屉。新增公司成功后打开基本信息。

发布匹配 PHP 和 `public/build`；域名变更迁移见下文，不需要发布 H5 或重打包 APK。
离线验证使用 `platform-layout-settings.mjs` 的 Chrome/WebKit、375/768/1440/1920 宽度，
以及 `admin-list-dialogs.mjs` 的其他弹窗回归。后端包含 CompanyDrawer、PlatformListDialogs、
SaasCompanyConfiguration、PlatformSettingsNavigation、PlatformDomainManagement、SupportBot、
SupportWorkspace、PlatformAndroidRelease、TenantArticles 测试，使用隔离 card_ui_test。
不得使用真实资金、群发通知或卡商调用验收布局。

## 页面清单

以下文件均检查了公共标题、外层边界、重复容器与主要操作入口。浏览器代表页面测试覆盖公共组件；
特殊工作区的内部排版继续保留，发布后用授权管理员只读检查实际长文本和权限组合。

| 页面文件／入口 | 布局与操作 |
| --- | --- |
| Dashboard | 顶部资金概览；日期筛选、图表及报表内部网格保留 |
| Tenants、TenantDetail | 公司列表／兼容详情；新增使用原弹窗，改名位于配置抽屉 |
| TenantCreate | 共用创建弹窗，不向背景顶部栏注入标题 |
| 公司目录兼容入口 | 重定向至公司管理并恢复目标抽屉分类 |
| tenant-admin/Settings（平台上下文） | 六类按需配置 DTO；抽屉分类切换，短表单左对齐 |
| AssetSettings | 系统／公司资金设置；公司抽屉身份固定 |
| PaidPromotion、WealthSettings | 统一配置抽屉，表单分组滚动；只读公司版保留原布局 |
| tenant-admin/CardProducts、Team、Onboarding（平台上下文） | 统一公司抽屉分类，复用独立内容 |
| Users | 全公司客户表格，固定身份与操作列，充值操作移到顶部 |
| WalletAdjustment、UserReferrer、UserPromotion、ManualCommission | 行操作弹窗，自己的标题与字段错误，背景列表标题不变 |
| Partners | 移除嵌套 main、1100px 居中和额外 24px 外边距；新增在顶部；存量／邀请数据用右侧抽屉 |
| Notifications | 顶部新建，原单公司预览确认弹窗 |
| CardProducts、CardProviders | 顶部新增，保留产品／卡商编辑弹窗及权限 |
| Cards | 顶部标题，发卡／充值／卡片内部页签、同步和消费记录抽屉保留 |
| Topups、AssetOrders | 充值／各网络提现列表，原审核与详情抽屉 |
| Kyc、KycDetail | 列表与审核工作区；共用外边距，原图片与授权流程 |
| Support、SupportAgents | 顶部主要操作，筛选可换行，保留会话分栏与人员列表 |
| Administrators | 顶部新增，原管理员列表与创建弹窗 |
| FinancialOperations | 共用标题、分页表格，原只读审计 |
| Domains、NotificationProfiles | 系统设置公用选项卡、原新增编辑弹窗 |
| OssSettings、KycSettings | 系统设置公用选项卡，原左对齐单例表单 |
| Login | 例外：独立认证布局 |

## 离线验证与手动发布

- `tests/Browser/platform-layout-settings.mjs`：全部请求模拟；Chrome/WebKit 的
  375/768/1440/1920 宽度，16 类按需切换、键盘、未保存取消／放弃、保存中锁定、
  多表单草稿保留、错误重试、迟到响应、公司 DTO 不匹配、刷新和前进后退。
- `tests/Browser/admin-list-dialogs.mjs`：其他弹窗及合伙人报表的离线回归。
- 后端使用隔离 `card_ui_test`，验证类别 DTO、权限、公司隔离及只读。
- 运行类型检查、变更文件 ESLint、国际化回归和后台生产构建。

由运维手动部署匹配的 PHP 文件、路由／中间件和完整 `public/build` 资源，
按现有部署流程重建路由及配置缓存，然后刷新后台浏览器。
抽屉本身没有数据库迁移；域名调配的后续变更需执行下述迁移。无需 H5 发布或 APK 打包。
不要使用真实资金、群发通知或卡商请求验收布局。

## 2026-10-08 所有域名统一调配

公司列表展示全部已分配域名；不再显示主域名或设置主域名操作。系统子域名和自定义
域名都可解绑后分配给另一公司。只允许分配未绑定的 ACTIVE 域名；旧公司绑定、
原始 ID 集合、并发锁和审计继续校验。解绑后原域名不解析到公司，不能访问原公司资源。
系统子域名仍保留来源类型；生成新公司时创建初始系统域名，但不赋予主域名优先级。
邀请链接、异步 KYC 与支付恢复从当前公司 ACTIVE 域名按 hostname 稳定排序选择；
无有效域名时不回退其他公司。未进行外部业务调用或生产修改。

历史 is_primary 列保留以兼容数据库；读取不再使用，解绑/分配时清除该域名的旧标记。
原设置主域名端点保留权限与归属检查后返回错误，不再写入。

手动上线时先执行 `php artisan migrate --force`，包含
`2026_10_08_120000_allow_reassignable_system_domains.php`，再发布匹配 PHP 与完整
`public/build` 并按原流程刷新缓存。迁移只放宽系统域名未分配的数据库约束，不改现有归属。
回滚前必须显式分配所有未绑定的系统域名；回滚不会猜测或恢复旧公司绑定。

## 2026-10-08 精简公司配置入口

公司列表不展示域名列。抽屉移除基本信息及独立公司改名表单，保留 15 类配置，默认域名。
旧 onboarding 编辑链接映射至域名；新建公司后也打开域名配置。
公司状态列直接展示启停开关；无 tenant.manage 权限只读，关闭公司不可切换。
草稿开启沿用原基础配置检查，正常公司关闭及停用公司开启沿用原生命周期端点。
切换先确认，成功后更新开关，失败保留原状态，保留列表筛选、分页和滚动。

### 2026-10-10 公司入金拆分

公司列表和顶部汇总将原 USDT 入金拆为“实际入金”和“预支金额”。沿用原有
CREDITED wallet_topup_orders 统计范围与最终入账金额（actual_received_amount，
历史空值回退 amount）；manual_receipt_type=ADVANCE 单列预支，其余（包括自动到账
和历史未分类记录）计入实际入金。两项之和保持原入金总额，出金口径不变。
汇总使用相同公司/搜索/状态筛选且不受分页限制，两列均沿用原入金读取权限。
不改余额、账本或历史分类；部署匹配 PHP 与 public/build，无新增迁移或 H5 重编译。

### Shared user information and detail drawer (2026-10-10)

Platform consumer-user identity cells share the Users layout (name, company remark,
email, company and account ID), including card issue/load/card lists, deposit and
withdrawal orders, KYC, partners and nested partner report identities. Existing page
rows are batch-enriched with company-scoped user summaries only for `users.read`.
Other readers retain their existing limited identity fields without a detail action.

Click opens a lazy, no-store right drawer using
`GET /platform/tenants/{tenant}/users/{user}/details`. It reuses the Users financial
projection and action permissions, showing all user-list fields plus phone. Financial
fields are omitted without their own read permissions. Passwords, tokens, identity
originals and card secrets are not added to this DTO. Existing KYC, funds, adjustment,
remark and restriction workflows retain their separate authorization and confirmation.
The background list, filters and scroll remain mounted; closing restores trigger
focus. Drawer data is refreshed after Inertia operations. No migration, H5 rebuild,
provider call or financial write is required for this presentation change.

Validation: the scoped detail/summary, native-precision wallet, funds-drawer and
partner-hierarchy suites pass (19 tests, 131 assertions). Browser checks cover
Users, deposit orders, card issue/card lists, KYC and partner lists, including
nested remark/funds dialogs and return to the list. Expanded legacy suites still
have two fixtures that fail the newer KYC identity-number requirement before their
read assertions, plus four existing localization/source-shape assertions (manual
receipt extraction, KYC Loading copy, partner navigation arrow, KYC upload message).
These are not waived security checks or changes to KYC rules.

On 2026-10-10 the Users list financial columns became Available balance, Actual
deposits, Cumulative advances and Withdrawal amount. Deposit totals sum CREDITED
wallet top-up and asset deposit orders for the exact company/user, using actual
received amount with legacy amount fallback; ADVANCE is separate from actual/null
classification. Totals remain decimal strings grouped by original currency and
require wallet_topups.read. Pending orders and standalone cooperation journal
entries are excluded. Withdrawals retain the existing successful gross USDT total
(including fees). Held funds, security deposits and commission remain in user details.

User rows now show a Details action rather than the user-operation dropdown.
Details opens the shared Customer details drawer; all existing per-user actions
are rendered as explicit buttons there, with unchanged permission gates. The list
agent-level cell is read-only; its editor link remains in details. Identity cells
continue to open the same drawer.

### Customer detail tabs and action footer (2026-10-10)

The shared customer drawer defaults to Basic information. Fund flows, Verification,
Deposit orders and Withdrawal orders are permission-gated lazy tabs within the same
drawer. Existing mutation buttons live in a non-scrolling footer; the tab body scrolls
independently. Embedded fund/KYC views retain their existing read endpoints and document
password gates without changing the parent list URL. Standalone legacy drawers remain
available. Customer order tabs use explicit tenant/user-scoped read-only JSON endpoints,
with users.read plus the corresponding deposit/withdrawal read permission. Order details
reuse the original review/confirmation controls and scoped mutation endpoints. No schema
or H5 rebuild is required; deploy PHP/routes and the rebuilt admin assets together.

Enabled partners additionally expose a lazy Partner stock tab in customer details,
only when the administrator has partners.manage. Resolve the enabled partner ID by
both company and user on the detail GET, then reuse the existing scoped hierarchy
stock reader, pagination and descendant drilldowns. Ordinary/disabled partners do
not expose this tab; report reads recheck enabled status and company scope.
