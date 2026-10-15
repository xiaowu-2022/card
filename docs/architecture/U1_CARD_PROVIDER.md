# U1 标准卡商接入

2026-10-14：按用户提供的最新文档升级到 v2，所有新请求携带 requestId；取代 2026-10-13 暂不发送 requestId 的接入约定。
U1 是现有卡商列表中的普通连接；PhotonPay 保留。通知联调按钮是正常连接的诊断操作，没有测试模式或模拟资金。
协议来源：https://doc.u1card.com/（V2），新请求固定使用 https://api.uucard.cyou/v2/api。已有目录 base_url 的 /v1 值保留为兼容配置标识，不需要改写连接；只有历史 v1 资金意图的结果查询仍走 /v1/api。

## 配置及上线

1. 在平台「卡商」新增/编辑 U1，填写名称、customerNo、apiKey。密钥加密保存，编辑不回显，留空保留；客户号不能更换，有历史的连接不能转换供应商。相同客户号不能重复配置到活跃连接。
2. 保存后点击「检查 U1 账户并同步卡组」，显式读取 queryaccount/querygroupno。检查通过后即可用于新产品及开卡，不再设置「接受新开卡业务」勾选项。保存/列表/打开/刷新通知记录不发送卡商请求。
3. 线上配置 APP_URL 为实际公网 HTTPS 后台入口；复制连接专属 `/webhooks/u1/{UUID}` 到 U1 后台。不要使用 localhost；反向代理必须保留原始 POST 正文。回调不依赖管理员登录，也不接受请求中的任意公司标识。
4. 创建产品时选择 U1 账户及 USD 卡组，记录实际 BIN 和 groupNo。实际 BIN 参与既有全球占用约束；历史绑定不能改路由。组 BIN 改变时阻止旧产品新开卡，必须显式建新产品。
5. 充值/提现沿用现有零额外费用报价，不再设置无额外手续费确认勾选项。历史 enabled/zero_fees_confirmed 字段保留，但不再作为 U1 业务门槛；账户检查、卡组模式及原有权限/资金保护继续生效。现有资金表单最多两位小数，拒绝不能精确表达的请求，不四舍五入；接收金额保留八位小数。
6. 线上使用 CARD_PROVIDER_DRIVER=directory，并使用既有 Redis 队列（QUEUE_CONNECTION=redis）和受管 queue worker。U1 通知持久化后交由 ProcessU1Notification 核实；单任务 timeout=60 秒，Redis retry_after 必须大于 60（默认 90），失败重试三次。更新 PHP 后重载 PHP-FPM 并 queue:restart。不要使用没有 jobs 表的 database 队列。通知详情可显式重新处理待处理/未关联/等待重试的有效记录。随机通知未找到已有卡/订单就保留 UNMAPPED。
7. 部署匹配 PHP、后台资源、H5 和以下新迁移；保留 APP_KEY（丢失会导致密钥及通知无法解密）。原生 App 的验证码入口需要重新编译安装。

迁移按顺序 210000、220000、230000、240000、250000 执行。210 建立原通知数据；220 原位重命名连接/诊断/通知表并扩展 U1 的独立业务证据，保留已有通知；230 增加处理状态索引与交易同步进度；240 保存真实 BIN、不可变历史保护及资金请求的加密密钥快照；250 保证 BIN 双触发器更新幂等。迁移不调用卡商或操作钱包资金，回滚需要前向迁移。

## 标准业务行为

虚拟开卡按 groupNo 调用 vccopen。沿用本地 KYC/卡片材料、用户限制、公司授权、钱包预留、账本、开卡订单及审计规则。U1 没有持卡人接口，材料通过本地验证时记为 LOCAL_READY，外部持卡人 ID 保持 NULL，不伪造上游审核或 ID。

开卡/充值(vccrechage，文档原拼写)/卡内提现(vccwithdraw)通过原订单流程发起，创建连接内唯一 UUID 意图后才发送。资金请求发送已持久化的 u1_operations.id 作为上游 requestId（独立 UUID 避免同客户号下不同连接/操作复用本地业务 ID），同时保存返回 apiId；request_ids_confirmed 标记该意图使用 v2。普通读取及诊断每次生成新 UUID。受理码 0000/0001/0003 不代表完成。通知只唤醒已有范围内的订单，queryapiid 通过固定 HTTPS 查询，并再次验签返回的通知 JSON、核对 apiId/操作类型，才交由既有结算动作。成功结算仍通过原金额/币种/账本保护；管理订单的未确认状态沿用 UNKNOWN 展示。失败按原流程释放资金，重复通知/查询不重复结算。

v2 响应丢失且未拿到 apiId 时，保留 UNKNOWN 和原预留；既有恢复流程通过 queryrequestid 查询已发送的意图 UUID。仅接受单一有效 apiId，再通过 queryapiid 验签并核对操作类型后处理结果；空列表、多 ID、无效或不匹配结果仍待确认。不推断 requestId 提供资金幂等保证，不自动重发。历史 request_ids_confirmed=false 的意图没有发送 requestId，未取得 apiId 时保持 UNKNOWN，不进行反查或改写历史。已有资金意图保存当时的加密密钥快照，密钥替换后仅可用于核验该连接、该 apiId 和该类资金结果；不能用旧密钥提交新的 OTP/交易通知。

正常卡余额/状态刷新使用 vccquery；敏感卡面使用 querycvv，只有密码验证后的卡主可短暂查看，PAN/CVV 不持久化。SHARE 组允许卡内提现，SINGLE 不允许。vcctrade 按正序分页读取真实交易 UUID，保存原 tradeType（不猜测数字类型含义）、原币种和精确金额；异步交易通知只触发对应卡余额/交易读模型刷新，不以未被签名覆盖的通知金额记账。

OTP 以签名中 cardNo 绑定已有卡，只有同公司活跃卡主经密码验证能看最近五分钟内签发的验证码；切后台/关闭及最多三十秒后清除客户端显示。后台通知所有详情递归遮罩验证码，连相同 OTP 值出现在其他字段也遮罩。验证码不会写入审计、URL或日志。

文档没有冻结、解冻、注销或持卡人增改查接口：页面不开放这些功能，服务器明确拒绝；平台作废 U1 卡在建立批次及任何资金操作前拒绝，不能假装注销成功。实体卡添加/PIN 修改等 transport 已实现，但现有实体卡发行流程需要 U1 额外的交付/激活绑定约定，当前产品仅提供虚拟卡，不能借用 PhotonPay 的实体卡/持卡人 API。

## 接口覆盖

U1ApiClient 实现文档 17 个接口：queryaccount、querygroupno、vccadd、vccpin、vccquery、vccopen、vccrechage、vccwithdraw、vccemail、vcctrade、vccquerylist、vccrechargelist、vccquerycvv、queryapiid、querycvv、queryrequestid、notifytest。所有值均字符串，按每个接口约定字段顺序 MD5（v2 的 requestId 不额外加入签名，queryrequestid 除外；notifytest 为 customerNo+timestamp+key，不再包含 editType）；固定 HTTPS、禁止重定向、无自动重试，错误不记录原始响应/密钥。卡 iframe 返回 URL 只允许固定供应商 HTTPS；未文档化的 token 放置方式不猜测。充值列表结构有文档歧义，不作为自动入账依据。

## 通知诊断及留存

九类通知全部按文档指定字段签名验证，注意提现失败名称为 withdrawvcc.fail。正文加密保存，未知字段保留，数值词法解析为字符串，不经浮点数。连接和正文 SHA256 唯一，重复累计次数。验签失败留存并返回 400，不进入可信业务处理；有效通知落库成功返回纯文本 SUCCESS；落库/解密失败返回 503，超过 256 KiB 返回 413。删除卡商保留历史凭据/FK，仅允许已有资金意图或已有卡的有效通知恢复。

「发送测试通知/测试全部」顺序调用 notifytest；请求受理和收到通知分别显示。随机测试只以连接/类型/接收时间观察，不声称与请求一一对应。未匹配业务卡/订单不会变更钱包、订单或账本。通知详情突出交易金额/币种、账单金额/币种、卡余额/币种和卡标识，缺字段显示未提供。

## 验证边界

本地测试使用隔离 card_ui_test、Http fake、Queue fake 和全拦截浏览器数据，未调用真实供应商、未开真实卡/充值/提现。真实回调和字段覆盖需要用户上线填写参数及回调后执行。发布记录见 artifacts/u1-standard-provider/verification.json。

2026-10-14 v2 更新仅需 PHP 发布及重载 PHP-FPM/队列 worker，无新增迁移、前端构建或存量资金意图修改。验证仅使用隔离数据库及 HTTP fake。

## 通知诊断文件（2026-10-14）

回调数据库事务提交后，追加到私有 `storage/logs/u1-notifications-YYYY-MM-DD.log`，每天轮转，保留七天，文件权限 0600。包括通知 ID、连接 ID、北京接收时间、通知类型、验签结果/原因、重复次数、固定签名字段的存在性/类型，以及用 APP_KEY 加密的 `body_encrypted` 原文。OTP、签名、密钥、卡号和任意未知字段不会以明文写入文件。日志时间前缀遵循运行环境，核查使用 `received_at_beijing`。文件失败不影响已提交的通知接收，数据库仍为权威证据；没有历史自动回填。部署 PHP/config 后刷新配置缓存并重启工作进程，保留 APP_KEY，日志不得放入公开目录或上传至外部日志服务。

可按后台通知记录 ID 查询：`rg '记录ID' storage/logs/u1-notifications-*.log`。加密正文仅在服务器内使用 `Crypt::decryptString()` 做验签核查，不要输出或转发 OTP 原文。

补充：Webhooks 控制器在业务验签和数据库操作前对每次请求写 `U1 webhook arrived`，九种通知（包括 OTP）、无效签名、重复、未知类型和无效 JSON 均覆盖；使用 receipt_id 关联后续验签/拒绝/失败结果。不存在连接、解密或数据库失败也记录固定原因，不输出异常原文。超过 256 KiB 的请求仅记录大小及拒绝状态，不复制超限正文。入口正文仍仅以 APP_KEY 密文写入；增加小写 cardno 等固定字段格式诊断。不改变既有回调 HTTP 状态、授权或验签条件。

2026-10-14 OTP 文档更正：签名顺序为 `MD5(cardNo+type+otp+timestamp+key)`，替代原先 timestamp 在 otp 前的顺序。已使用用户提供的失败通知在本地解密重算，修正顺序与上游签名匹配。仅更新验签字段顺序，不接受旧顺序回退、不改写历史通知、不自动重新处理既有记录；发布 PHP 后重载 PHP-FPM/队列 worker。

2026-10-14 生产错误修复：queryapiid.data 同时接受 JSON 对象与通知 JSON 字符串，仍严格验证内层签名、apiId、操作类型。正常待确认通过队列 release 有界重试，最终仍保留 RETRY，不改资金结果。非预期异常保留失败队列记录，独立日志记录安全异常类型及通知 ID，详见部署文档的 2026-10-14 后台任务错误修复。

## 2026-10-15 Provider-specific application materials

The consumer catalog derives `applicationProfile` from the product's server-side provider binding. U1 applications collect the initial load and an optional verification email; names, phone, birth date and identity-document copies are not U1 opening parameters. Approved account KYC and existing owner/company/product, consent, wallet and idempotency gates still apply. Optional email is retained in encrypted application materials and forwarded to U1 vccopen; it is not included in the documented opening signature. Other providers retain their existing cardholder material requirements. Client-supplied provider selection cannot relax backend validation. Deploy matching PHP, web and rebuilt H5 assets (copied into public/h5); no migration or live financial test is required.

2026-10-15 follow-up: verification email is now required for U1 by product policy in both client and server validation. The provider API still treats email as unsigned optional transport data; our opening workflow always supplies a valid email. The application dialog uses a narrower, rounded layout with separated inputs and a fee summary.

2026-10-15: all outbound U1 endpoints now record correlated request and response diagnostics in storage/logs/u1-requests-YYYY-MM-DD.log (daily, 0600, seven-day retention). Exact request/response bodies are encrypted with the application key; API credentials and sensitive card data never appear in searchable fields. Failures identify TRANSPORT, HTTP_STATUS_OR_BODY_SIZE, RESPONSE_JSON or RESPONSE_SIGNATURE. Operation evidence checks record signature validity and apiId/type match; order-level query exceptions record tenant/order/provider request IDs. Deploy PHP/config together, rebuild config cache and restart queue workers; no migration or financial replay.
