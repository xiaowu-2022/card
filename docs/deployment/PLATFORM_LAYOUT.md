# SaaS 公共布局与公司设置选项卡

本次仅发布平台管理端和配置详情 DTO；不迁移数据库，不发布 uni-app H5，不重新打包 APK。

## 公共规范

`PlatformLayout` 必填页面标题，接收说明和主要操作。顶部栏固定 56px，标题在左，
操作与账户控件在右。内容只有一个 main，外边距 20px、区块间距 16px，去掉嵌套居中
和 1600px 最大宽度。短表单保留左对齐限宽，客服、KYC、报表保留内部工作区。

`PlatformUiContext` 将 36px 控件、10px 表格上下内边距、16px 面板和弹窗内边距限定在平台端，
包括 React Portal 中的弹窗和抽屉。原 PageHeader、公司后台和用户端不采用此尺寸。
表格内部滚动、固定身份／操作列继续保留；没有全局隐藏标题或覆盖所有元素的 padding。

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

发布匹配 PHP 和 `public/build`；无数据库迁移，不需要发布 H5 或重打包 APK。
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
本次没有数据库迁移、历史数据修正、H5 发布或 APK 打包。
不要使用真实资金、群发通知或卡商请求验收布局。
