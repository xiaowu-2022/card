# SaaS 公共布局与公司设置选项卡

本次仅发布平台管理端和配置详情 DTO；不迁移数据库，不发布 uni-app H5，不重新打包 APK。

## 公共规范

`PlatformLayout` 必填页面标题，接收说明和主要操作。顶部栏固定 56px，标题在左，
操作与账户控件在右。内容只有一个 main，外边距 20px、区块间距 16px，去掉嵌套居中
和 1600px 最大宽度。短表单保留左对齐限宽，客服、KYC、报表保留内部工作区。

`PlatformUiContext` 将 36px 控件、10px 表格上下内边距、16px 面板和弹窗内边距限定在平台端，
包括 React Portal 中的弹窗和抽屉。原 PageHeader、公司后台和用户端不采用此尺寸。
表格内部滚动、固定身份／操作列继续保留；没有全局隐藏标题或覆盖所有元素的 padding。

## 公司设置

列表与编辑弹窗共用 `company-settings.ts` 的九类定义，使用 `SettingsTabs`：资金、品牌、语言、
业务、关于我们、短信、邮件、推广、理财。左右按钮滚动，方向键/Home/End 移动焦点，
Enter/Space 才选择类别，避免键盘经过类别时丢失表单。选中项随容器缩放保持可见。
系统设置使用同一组件并保留每项权限过滤。

列表默认全部授权公司，原公司／搜索／状态／分页过滤不变。公司设置行突出编辑配置，
保留公司产品和团队入口；改名和生命周期操作继续在公司管理使用。
单个配置弹窗固定公司名称和选项卡，内容独立滚动、操作在底部。切换只请求目标类别；
返回 DTO 公司必须与请求公司匹配。资金设置在弹窗内不提供更换公司的选择框。
未保存关闭／切换需确认，取消保留输入；保存中不能切换／关闭／重复提交。
失败显示重试，校验失败保留表单；旧编辑链接、section/editor、列表过滤、滚动和焦点保留。

平台 `TenantSettingsController` 向查询传入当前类别，使品牌／语言／业务／文章／短信／邮件
只取本类 DTO 和所需关系；公司后台调用原查询路径不变。所有原保存、授权、公司绑定、
金额与审计规则不变，GET 不调用提供商或进行业务操作。

## 页面清单

以下文件均检查了公共标题、外层边界、重复容器与主要操作入口。浏览器代表页面测试覆盖公共组件；
特殊工作区的内部排版继续保留，发布后用授权管理员只读检查实际长文本和权限组合。

| 页面文件／入口 | 布局与操作 |
| --- | --- |
| Dashboard | 顶部资金概览；日期筛选、图表及报表内部网格保留 |
| Tenants、TenantDetail | 公司列表／兼容详情；顶部主要操作，原新增与改名弹窗 |
| TenantCreate | 共用创建弹窗，不向背景顶部栏注入标题 |
| CompanyConfigurations | 全公司分页、九类横向选项卡、固定操作列 |
| tenant-admin/Settings（平台上下文） | 六类按需配置 DTO；单弹窗切换，短表单左对齐 |
| AssetSettings | 系统／公司资金设置；公司弹窗身份固定 |
| PaidPromotion、WealthSettings | 单配置弹窗，表单分组滚动；只读公司版保留原布局 |
| tenant-admin/CardProducts、Team、Onboarding（平台上下文） | 独立公司入口，共用外层；不混入九类配置弹窗 |
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

## 离线验证与发布

- `tests/Browser/platform-layout-settings.mjs`：全部请求模拟；Chrome/WebKit 的 1024/1366/1920，
  顶栏56、边距20、控件36、单元格10、单 main/标题、九类按需切换、键盘、未保存取消／放弃、
  保存中锁定、失败保留输入、错误重试、公司 DTO 不匹配、刷新、旧链接、公司后台尺寸不变。
- `tests/Browser/admin-list-dialogs.mjs`：上述三种宽度下，公司／客户／产品／通知／合伙人，
  表格横滚、固定操作列、弹窗高度、详情抽屉、滚动、焦点和列表 URL。
- 后端回归：PlatformListDialogs、SaasCompanyConfiguration、PlatformSettingsNavigation、TenantArticles、
  PlatformNotificationProfiles；使用隔离 card_ui_test，验证类别 DTO、授权、公司隔离及只读。
- 类型检查、ESLint、78 项 i18n/前端回归、后台生产构建。
- 本次后端回归结果：39 项通过、897 次断言；浏览器两套离线脚本均通过 Chrome/WebKit。

部署匹配的 PHP 文件与 `public/build`，刷新后台浏览器；本次没有数据库迁移、历史数据修正、
H5 发布或 APK 打包。不要使用真实资金、群发通知或卡商请求验收布局。
