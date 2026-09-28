# uni-app 完整用户端实施与验证 — 2026-09-26

## 本次范围

将 43 个正式消费者页面及相关操作实现为 Vue / uni-app 页面和组件，复用原版素材、翻译、业务规则与 Laravel 领域动作。包括登录注册及验证码、资产与四币种资金流程、卡片、保证金与理财、推广及学院、账户/KYC、客服与消息。后台保持 React；开发模拟付款页面不进入消费者客户端。

原 H5 继续作为正式入口，本次没有替换生产入口。新增本机编译预览和原版／新版左右对照验收页；不以开发服务器展示或编译成功替代验收。

## 已完成验证

- `npm run client:typecheck`：通过。
- `npm run test:client`：3 个公司配置校验测试通过。
- H5 和 App 资源编译通过；App 资源不等于签名 APK/IPA。
- 原版 `npm run build`：通过，已有 chunk-size / runtime-image 提示仍存在。
- `ConsumerApiTest` 与 `ConsumerClientTest`：共 19 测试、140 断言通过。覆盖租户与用户边界、仅 Bearer 的原生认证、过期与撤销、受限账户、CSRF、只读 GET、多步骤原生注册、流程令牌隔离和注册后会话。
- `ConsumerParityFixtureTest`：1 测试、55 断言通过。在隔离 `card_ui_test` 中生成新的合成用户和测试账本资金，导出只读页面 DTO，并检查页面读取没有改写账本。所有上游 HTTP fake；没有历史财务改写。
- `tests/Browser/consumer-uni.mjs`：四语言 × 375/768/1440px 共 12 组通过。检查消息／客服合计角标与 99+、长标题、HTML 类正文按纯文本显示、GET 不标已读而 POST 标已读、客服已读水位及横向溢出。
- `tests/Browser/consumer-uni-flows.mjs`：8 组离线交互通过：转账精度及不确定结果重试复用请求 ID、提现确认、卡片详情显隐清除、理财确认、双面证件 multipart 与可操作错误、开卡 UNKNOWN 不自动重提，注册完成后的 H5 Cookie 会话刷新，以及加载失败重试与旧注册链接参数保留。
- 浏览器交互只使用拦截的 API 响应；KYC 上传为一像素合成图片，卡号为测试号码，不提交真实身份、资金或卡商请求。

四语言（中文、英文、马来文、西班牙文）× 三尺寸共 **792 组截图对照** 已完成，原版和新版均无页面运行错误、无横向溢出。最后的局部样式修正另行重新截图并合并到同一结果集。人工抽查了手机、平板、大屏和长文案页面，完整图集供逐页视觉验收。

## 对照验收产物

- `artifacts/uni-parity/index.html`：按页面、语言和宽度切换，左原版 React、右编译生成的 uni-app H5。
- `artifacts/uni-parity/results.json`：逐页记录截图、正文、页面运行错误和横向溢出。
- `artifacts/uni-parity/flows`：关键交互截图。
- 页面与实现映射、复现命令：[PARITY.md](uni-app/PARITY.md)。

对照使用两端相同的公司、品牌、语言和测试 DTO；包含 66 个页面／展示状态，涵盖 43 个正式页面组件。订单成功、待确认、拒绝、UNKNOWN 等额外状态通过明确标记的合成只读数据提供。截图检查不代表逐像素一致，也不代表所有资金结果都完成端到端模拟。

## 仍需上线前验收

- 人工逐页验收左右截图与交互，再决定 H5 切换。
- Android / iOS 签名安装包、设备权限、图片选择与保存、系统分享、后台敏感信息遮挡及真实设备布局。
- 当前原生 Token 仅保存在内存中，重启 App 需要重新登录；持久登录需要经验证的 Keychain / Keystore 桥接。
- 公司正式 AppID、图标、证书及 DCloud 编译基座／工具链一致性。现有依赖审计问题与打包步骤见 [UNI_APP_PACKAGING.md](../deployment/UNI_APP_PACKAGING.md)。

本次未执行生产迁移、真实充值／提现／卡片操作、链上调用、真实通知、DCloud 上传、证书使用或上架。
