# PhotonPay 多账号与 BIN 占用

2026-09-24 用户批准。本规则替代新增产品的可空卡商、手工 BIN、归档释放 BIN，以及数据库发卡凭据整段不可轮换的旧规则。保留历史产品、卡、订单、上游标识及 Ledger。

## 账号

2026-10-12 修订：只有当前未归档、未注销的本地卡片会阻止删除卡商，余额为零、
冻结、过期或状态未知的当前卡片也会阻止。只有产品（含归档产品）、收件人申请、
订单、回调或 BIN 历史时允许从管理列表移除卡商，不依赖外部统计中的累计发卡数。
删除以永久 `CARD_PROVIDER_REFERENCE_DELETED` 审计作为目录墓碑；保留卡商原行、
历史外键、私密凭据和 BIN 占用。普通卡商查询、下拉选项与手工三方余额统计排除
已移除卡商；历史产品关系及回调验证可明确读取保留身份，已接受订单继续按原有
验证和幂等机制恢复，绑定该卡商的新业务拒绝。不会删除或自动解绑关联历史。
Platform `card_provider_reference.manage`、明确确认、版本检查、请求幂等及原子
删除审计仍然必须满足。锁定卡商及已有产品绑定，重新检查当前卡；保留数据库
外键保护。审计不保存密钥，旧新增请求不能复活该 ID。删除不调用卡商，不改钱包
或 Ledger。删除最后一页最后一项后返回有效页码。PHP/admin 资产一起发布，
无迁移或生产删除/资金测试。

验证：CardProviderReferenceTest 与 PhotonPayMerchantReportingTest 使用隔离测试库；
`tests/Browser/card-provider-delete.mjs` 使用离线响应覆盖中英文、三种宽度、取消/
确认、当前卡片禁用、旧版本错误及权限隐藏，不删除开发库已有卡商。

`platform_card_provider_references` 的一个 PhotonPay 记录对应一个账号。Platform 的 `card_provider_reference.manage` 权限维护名称、环境、App ID/Secret、商户签名私钥、回调公钥、USD account/member、可选 Matrix 以及新业务开关。固定环境地址，不接受用户输入 URL；CSRF、版本锁、审计保留，无重复密码确认。

凭据使用 Laravel Crypt 加密，响应不返回密钥或 Secret，编辑空值保留。异常不闪存这些输入，前端不使用记忆表单，提交结束清空秘密字段。身份独立保存在 `photonpay_identity`；有持卡人、收件人、订单或卡历史时不能改变身份。轮换不改变产品绑定。配置改变使连接检查、目录、能力缓存失效，发卡 Token 缓存由加密配置摘要隔离，不复用旧版本 Token。

显式检查调用 Token、USD 账户归属及 BIN 查询；校验 accountNo/memberId/currency/accountType，保存目录。未检查、失败或迁移身份不明时发卡适配器不可用，保留既有确认状态和 UNKNOWN，不用未经确认的凭据查询另一个账号。轮换后必须重新检查。该检查不证明私钥与上游签名配置匹配，也不证明开卡成功。

暂停仅关闭新增持卡人、收件人、开卡及充值，在锁内复核；同一配置的已检查账号继续支持查询、回调、激活与待确认订单处理。已提交请求的幂等重放不重新发送。

账号密钥输入支持完整 PEM 或纯 Base64，保存前解析并统一为 PEM 后加密。商户私钥必须为至少 2048 位 RSA，回调公钥必须为至少 1024 位 RSA；格式错误按具体字段提示，不回显输入值。

## 产品和 BIN

新产品必须绑定已启用、配置完整、检查通过的账号，BIN 必须属于其显式保存目录。普通产品 GET/保存不获取上游目录。目录刷新不自动解锁未通过身份检查的账号。

2026-09-24 用户修正规则：删除（归档）产品释放 BIN；仅停用而未归档仍占用。此前永久占用规则废止。

`card_bin_claims.bin` 是完整字符串主键；产品写入及占用在同一事务提交，按 BIN 加事务锁并以主键约束阻止跨账号并发重复。`534934` 和 `53493435` 分别占用。允许修改的未使用产品更换 BIN 时释放旧占用。

新增迁移只从未归档产品重建占用。已有多个未归档产品使用同 BIN 时保留记录并标记冲突，禁止新增；归档其中一条后按剩余未归档产品重算，全部归档才释放。数据库触发器维护占用，不允许直接删除占用绕过约束。归档产品及其卡、订单、上游标识和 Ledger 不变，新产品不接管历史业务路由。

## 回调

账号回调路径 `/webhooks/card-provider/{account UUID}`。使用该账号保存公钥验签，卡、持卡人、订单查询限定到该账号产品；跨账号引用拒绝。事件保存不可变账号 ID，摘要命名空间包含账号。旧事件按相同资源及原摘要去重，避免由于新摘要格式重复处理。

旧路径 `/webhooks/card-provider` 仅从有长度限制的未验证标识查找已有资源所属账号，且必须唯一，然后使用保存的公钥验证原始字节。定位过程不变更业务，不信任报文公司或账号字段。歧义、缺失配置或未绑定的 `.env` 产品拒绝；不尝试其他公钥或全局公钥。既有验签后内联处理、金额不能直接记账、无自动通知重放/轮询均保留。

`cards:mock-purchase` 仍仅在隔离本地 Mock 环境运行：对已知本地卡生成签名合成 inbox 事件，不替换任何全局/账号公钥，也不通过公网回调解析器。它不证明真实账号回调成功。原有本地模拟卡查询和资金流程保留。

## 迁移与检查

`2026_09_24_140000` 不请求上游、不创建卡、不移动资金。已有数据库报告和发卡连接先检查配置身份一致，无法确认时记 `LEGACY_CONNECTION_REQUIRES_REVIEW`，不迁移该账号。只有环境、App ID、USD/member/Matrix 与旧环境配置一致时才导入其回调公钥；其他账号在后台补充独立公钥。

运行 `php artisan cards:accounts-preflight`，输出未绑定 `.env` 产品、历史重复及冲突 BIN、需要核对的账号，不输出秘密。存在 `.env` 历史产品时先制定同一账号的显式映射并证明归属，不自动改绑，不禁用历史路由保护。当前开发数据库只读检查没有未绑定产品；`522105` 的两条归档记录和 `53493435` 的一条归档、一条在用记录全部保留。

## 2026-10-15 Database-only merchant report reads

Card-provider directory and homepage merchant reporting now read only photonpay_report_snapshot in the provider directory row. Reads never call PhotonPay or refresh expired caches. Missing snapshots return unavailable; old values remain with their queriedAt on refresh failure. The credential hash prevents showing snapshots after credential rotation.

The first successful issue or LOAD/RETURN/CANCEL_RETURN order transition queues RefreshPhotonPayAccountReport after commit. New/rotated reporting credentials also queue initialization. The job fetches fresh merchant balance/card count through existing scoped merchant validation, serializes per reference, rejects stale credentials/older reports and stores sanitized metrics only. Failure retries independently of business settlement. BIN reads/explicit configuration checks retain their existing behavior.

Deploy 2026_10_15_150000_add_photonpay_report_snapshot with PHP, migrate, restart queue workers, then run php artisan photonpay:refresh-reports once to enqueue existing-provider initialization. This command only queues read-only reporting; never run financial operations for deployment verification. No frontend/H5 build is required.
