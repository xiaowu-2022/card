# OSS / OCR URL 验收 — 2026-09-27

本轮实现 SaaS 全局版本化 OSS 配置、统一图片存储映射、URL-only OCR、历史迁移和清理恢复。仅运行隔离测试、合成图片及本地 UI；没有真实 OSS 配置、身份上传或历史财务操作。

## 结果

- OSS 专项最终 16 项通过（68 assertions）：配置权限、凭证加密/不回显、连接测试、未验证不可启用、版本绑定、无本地降级、URL-only OCR、清理失败恢复、源文件缺失恢复、幂等迁移、原密文备份保留、KYC 不可变记录保持、跨公司引用拒绝、SDK 图片 ACL/加密参数、CNAME 配置。
- 最后一次受影响回归：OssImagesTest、KycSubmissionTest、AliyunKycIntegrationTest 共 76 项通过（357 assertions）；之后增加 CNAME 用例并复跑 OSS 专项，见上述 16 项。
- 较广的一轮包含 KYC、Aliyun、客服、邀请海报、公司配置、开卡及签名单元测试：266 通过、1 失败。该失败来自新增迁移测试比较内存模型与数据库模型的默认字段/时间格式，改为迁移前后都读取数据库快照后通过，包含在上述最终回归中。不能把此初轮日志描述为单次全绿。
- 另一次开卡/客服回归 164 项通过（1607 assertions），无真实卡商请求。
- 前端 TypeScript、77 项 i18n 检查、React 构建、uni-app H5 构建通过。使用 Node 22+ 运行；机器默认 Node 16 不符合现有项目构建要求。
- Composer validate 与新增存储文件 Pint 检查通过。官方 SDK 新增一个包，没有降级现有 Guzzle。
- H5 构建保留原有卡背景静态路径提示；已确认最终输出中该文件存在。React 仍有原有大 chunk 警告。
- 本地执行本次结构迁移，未执行真实图片迁移或启用 OSS。

## 页面检查

使用本地 Platform Owner 正常登录 `/platform/settings/oss`。确认中文导航入口、配置字段、空版本列表、迁移说明与统计可见，页面 console 无 error。未在表单填入任何真实凭证。截图：`artifacts/oss-20260927/settings.png`。

## 尚需部署联调

实际 OSS RAM 凭证、Bucket/域名及公开访问权限尚未提供。需在后台保存、测试并启用；测试使用合成 PNG，不使用真实证件。历史迁移仍未执行，按 [部署说明](../deployment/OSS_IMAGES.md) 先预览再分批执行并检查失败统计。没有把离线替身通过当作真实 OSS/OCR 外网联调通过。

详细日志位于 `artifacts/oss-20260927/`：`oss-tests.log`、`final-affected-tests.log`、`broad-regression-initial.log`、`typecheck.log`、`i18n.log`、`build.log`、`h5-build.log`、`composer-validate.log`、`pint.log`、`eslint.log`。
