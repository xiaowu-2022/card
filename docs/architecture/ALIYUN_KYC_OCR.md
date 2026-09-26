# 阿里云证件 OCR

2026-09-24 用户批准大陆身份证和护照识别，替代原来上传合法图片就自动认证的规则。

实名认证表单选择证件类型；大陆身份证仅 CN 且正反面必传，护照仅资料页。
提交先校验权限、已有认证/待审核状态，后用私有图片二进制调用固定杭州端点，
不提供公开图片 URL。RecognizeIdcard 校验正面号码及校验位并识别背面签发机关/
有效期字段；中国内地及港澳台护照走 RecognizeChinesePassport，其他护照走
RecognizePassport。仅识别非空号码且与填写号码规范化后匹配才能存入新申请，
自动模式随即认证，手动模式进入审核。审批入口也校验加密 OCR 匹配证据。
接口异常、缺失字段、识别不匹配均不创建认证身份，允许用户修正后重提。

身份证背面字段识别不等于验证有效期，当前未做证件真伪、公安二要素或人脸核身。
不宣称 OCR 成功能证明本人或证件真实。姓名识别不作姓名核验，因为当前表单
没有姓名对照输入；护照签发国按用户选择调用接口，OCR 不提供全覆盖证件国籍核验。

服务独立读取 ALIYUN_OCR_ACCESS_KEY_ID / ALIYUN_OCR_ACCESS_KEY_SECRET；
KYC_OCR_DRIVER=aliyun。缺凭据或权限失败时关闭通过路径，不回退 Mock。
Mock 仅用于 local/testing 的显式配置。图片、证件号码和完整 OCR 响应不进日志，
证件沿用私有存储，加密保存 MATCH 证据，阿里云 RequestId 用于查请求。
自动审批仍保留身份账户数量限制、公司隔离、锁和审计，无 Ledger 操作。

部署先执行 2026_09_24_180000_allow_kyc_passports 迁移，再部署应用和前端。
迁移仅扩展证件类型及允许护照无背面，不修改既有身份、图片或审批结果。
阿里云开通个人证照识别，并授权 ocr:RecognizeIdcard、ocr:RecognizePassport、
ocr:RecognizeChinesePassport。配置独立 RAM 凭据后执行 config:cache 并重载 PHP。
未使用用户真实证件做线上测试；接入验收先使用授权测试图片核验实际返回。

官方接口：
- https://help.aliyun.com/zh/ocr/developer-reference/api-ocr-api-2021-07-07-recognizeidcard
- https://help.aliyun.com/zh/ocr/developer-reference/api-ocr-api-2021-07-07-recognizepassport
- https://help.aliyun.com/zh/ocr/developer-reference/api-ocr-api-2021-07-07-recognizechinesepassport


## 2026-09-26 安全故障诊断

当网页显示 KYC_OCR_UNAVAILABLE 时，CLI 配置检查只能证明该 CLI 进程读到了
非空配置，不能证明 AccessKey 有效、RAM 已授权、产品已开通或 PHP-FPM 已重载。
适配器现在将失败写入现有应用日志，消息为 `Aliyun KYC OCR failed`，字段仅包含：
固定 action/phase、HTTP 状态、严格白名单 provider_code、UUID 格式
provider_request_id，以及可提取时的 cURL 数字 transport_code。未知错误码为
UNRECOGNIZED，不保存原始值。Message、响应体、图片、证件号码、密钥、Authorization
及完整异常链不记录，也不向浏览器暴露。日志写入失败仍拒绝认证，不附原异常。

phase=configuration 表示运行进程缺少配置；transport 表示发请求时异常，常见
cURL 6/7/28/35/60 分别指 DNS、连接、超时、TLS 或证书问题；upstream 表示非成功
HTTP 或阿里云 Code 响应；response_json/response_data 表示返回格式无法解析。
权限、签名、服务开通等具体根因仍必须以线上诊断证据为准。

本补丁仅需同步 `app/Infrastructure/Providers/Kyc/AliyunKycOcrProvider.php` 并
重载网站 PHP-FPM。无迁移、前端构建或配置新增要求。正常用户提交遇到失败后，
在服务器项目根目录查看匹配该消息的日志即可；不得自动重放历史证件或日志。
本地 HTTP 伪造测试覆盖正常识别、403/200 错误、超时、缺配置、异常返回及敏感信息
过滤，不用真实证件或凭据发起线上请求。
