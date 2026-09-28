# OSS 迁移与 PhotonPay 沙箱开卡验收（2026-09-28）

## 环境与范围

用户明确要求执行图片迁移并测试开卡。本次执行于本机 Docker `local / card_mock`，连接已通过合成图片读写删除测试的真实 OSS；开卡连接严格限定 PhotonPay sandbox。未连接生产数据库，也未执行生产开卡。

启用配置 `01a0e6d4-7739-72e3-a5ef-c8867b929a22`，Region 为 `cn-beijing`。保留旧配置版本，不回显或写入报告任何 AccessKey、身份字段、图片内容、图片地址或卡号。

## 迁移执行

迁移前枚举 1,812 个不同的业务图片引用：KYC 1,804、开卡资料 6、客服 1、邀请海报 1。未发现缺失的源文件。只迁移业务引用的图片，不搬运加密测试档案、日志或内置静态资源。

使用原 `MigrateImages` 服务完成上传、下载 SHA256 校验、源文件复核及事务切换；本次本地运行器使用 8 个不重叠分片，父进程持有正式迁移命令相同的 PostgreSQL advisory lock。原图片和加密原件保留。

2,043,150 字节邀请海报原先触发 30 秒上传超时，映射保持本地。调整大图片上传超时至 180 秒后，在同一目标键重试成功：上传 97.94 秒，下载校验 3.39 秒。没有因超时直接认定迁移成功。

最终结果（`verification.json`）：

| 检查 | 结果 |
| --- | --- |
| 原有图片迁移 | 1,812 / 1,812 |
| 原本地备份解密后 SHA256 核对 | 1,812 / 1,812 |
| 新开卡直接写入 OSS 的资料图 | 2 |
| 当前不同业务图片引用 / OSS 映射 | 1,814 / 1,814 |
| 缺失、未就绪、校验失败 | 0 |
| 各类最大图片鉴权读取 + 公开读取校验 | 海报、KYC、客服、开卡全部通过 |

正式命令再次预览和执行 `--execute --retry-failed --limit=1000` 均返回 `eligible=0, migrated=0, failed=0, previous_failures_skipped=0`。worker 5 日志中的退出码 1 对应已被后续重试恢复的海报首次失败，最终状态无失败项。

用开卡前截止时间核对原有行的全列序列化 SHA256：Ledger 2,894 行、KYC 902 行、持卡人 26 行、客服消息 3 行、开卡订单 26 行全部与原基线一致。新沙箱开卡产生的新记录单独保留，不计入原记录校验。

## 实际沙箱开卡

- 使用此前明确授权保存的加密测试资料，通过现有应用服务新建持卡人；正反面图片先存 OSS，读取校验均通过，再按 PhotonPay 原文件协议提交。
- 持卡人 `01a0e6de-5cb8-7184-80a2-1b9d022f0019` 为 `READY`。
- 开卡订单 `f0a84495-63f3-450e-bde8-0df4168d9b7e` 为 `SUCCEEDED`。
- 卡片 `01a0e6de-a758-7333-95a4-e94c99d67326` 状态 `normal`；初始金额 20 USD，开卡费 2 USDT，本地沙箱钱包按原流程支出 22 USDT。
- 因测试账号已有历史开卡，临时通过原带审计配置服务提高测试产品数量限制，完成后已恢复原值 1。未修改历史订单、卡片或 Ledger。
- 同一稳定请求 UUID 再执行，返回原持卡人、订单和卡片，不重复开卡。新测试卡保留，未销卡、退款或执行额外充值。
- `ledger:reconcile` 对测试公司核对通过，没有余额差异。

## 测试和边界

- `OssImagesTest / CardIssueTest / KycSubmissionTest / SupportChatTest`：199 tests、1,773 assertions 通过。
- 大图片超时调整后的 `OssImagesTest`：19 tests、82 assertions 通过。
- `KycOcrTest / CardholderTestMaterialsTest`：10 tests、60 assertions 通过。URL OCR 协议和错误路径使用隔离测试，不向真实 OCR 提交身份资料。
- PhotonPay sandbox 身份认证、商户归属、BIN 目录及已有卡片读取检查通过。
- 实际开卡通过应用服务执行；本轮未完成登录后的 H5 浏览器全流程或 App 打包验收。不能据此宣称生产、全部卡种或全部卡片操作均已验收。

本地详细证据位于 `artifacts/oss-migration-20260928/`，其中 `before.json / checkpoint.json` 是原业务记录校验基线，`verification.json / original-row-check.json / final-idempotency.log` 是最终迁移核对，`card-execute.json / card-retry.json / ledger.log` 是沙箱结果；脚本仅允许本地 `card_mock`，发送使用固定幂等意图。
